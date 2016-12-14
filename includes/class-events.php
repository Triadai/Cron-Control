<?php

namespace Automattic\WP\Cron_Control;

class Events extends Singleton {
	/**
	 * PLUGIN SETUP
	 */

	/**
	 * Class properties
	 */
	const LOCK = 'run-events';

	/**
	 * Register hooks
	 */
	protected function class_init() {
		// Prime lock cache if not present
		Lock::prime_lock( self::LOCK );

		// Prepare environment as early as possible
		$earliest_action = did_action( 'muplugins_loaded' ) ? 'plugins_loaded' : 'muplugins_loaded';
		add_action( $earliest_action, array( $this, 'prepare_environment' ) );
	}

	/**
	 * Prepare environment to run job
	 *
	 * Must run as early as possible, particularly before any client code is loaded
	 * This also runs before Core has parsed the request and set the \REST_REQUEST constant
	 */
	public function prepare_environment() {
		if ( ! is_rest_endpoint_request( 'run' ) ) {
			return;
		}

		ignore_user_abort( true );
		set_time_limit( JOB_TIMEOUT_IN_MINUTES * MINUTE_IN_SECONDS );
		define( 'DOING_CRON', true );
	}

	/**
	 * List events pending for the current period
	 */
	public function get_events() {
		$events = get_option( 'cron' );

		// That was easy
		if ( ! is_array( $events ) || empty( $events ) ) {
			return array( 'events' => null, );
		}

		// Simplify array format for further processing
		$events = collapse_events_array( $events );

		// Select only those events to run in the next sixty seconds
		// Will include missed events as well
		$current_events = $internal_events = array();
		$current_window = strtotime( sprintf( '+%d seconds', JOB_QUEUE_WINDOW_IN_SECONDS ) );

		foreach ( $events as $event ) {
			// Skip events whose time hasn't come
			if ( $event['timestamp'] > $current_window ) {
				continue;
			}

			// Skip events that don't have any callbacks hooked to their actions, unless their execution is requested
			if ( ! $this->action_has_callback_or_should_run_anyway( $event ) ) {
				continue;
			}

			// Necessary data to identify an individual event
			// `$event['action']` is hashed to avoid information disclosure
			// Core hashes `$event['instance']` for us
			$event_data_public = array(
				'timestamp' => $event['timestamp'],
				'action'    => md5( $event['action'] ),
				'instance'  => $event['instance'],
			);

			// Queue internal events separately to avoid them being blocked
			if ( is_internal_event( $event['action'] ) ) {
				$internal_events[] = $event_data_public;
			} else {
				$current_events[] = $event_data_public;
			}
		}

		// Limit batch size to avoid resource exhaustion
		if ( count( $current_events ) > JOB_QUEUE_SIZE ) {
			$current_events = $this->reduce_queue( $current_events );
		}

		// Combine with Internal Events and return necessary data to process the event queue
		return array(
			'events'   => array_merge( $current_events, $internal_events ),
			'endpoint' => get_rest_url( null, REST_API::API_NAMESPACE . '/' . REST_API::ENDPOINT_RUN ),
		);
	}

	/**
	 * Check that an event has a callback to run, and allow the check to be overridden
	 * Empty events are, by default, skipped and removed/rescheduled
	 *
	 * @param $event  array  Event data
	 *
	 * @return bool
	 */
	private function action_has_callback_or_should_run_anyway( $event ) {
		// Event has a callback, so let's get on with it
		if ( false !== has_action( $event['action'] ) ) {
			return true;
		}

		// Run the event anyway, perhaps because callbacks are added using the `all` action
		if ( apply_filters( 'a8c_cron_control_run_event_with_no_callbacks', false, $event ) ) {
			return true;
		}

		// Remove or reschedule the empty event
		if ( false === $event['args']['schedule'] ) {
			wp_unschedule_event( $event['timestamp'], $event['action'], $event['args']['args'] );
		} else {
			$timestamp = $event['timestamp'] + ( isset( $event['args']['interval'] ) ? $event['args']['interval'] : 0 );
			wp_reschedule_event( $timestamp, $event['args']['schedule'], $event['action'], $event['args']['args'] );
			unset( $timestamp );
		}

		return false;
	}

	/**
	 * Trim events queue down to the limit set by JOB_QUEUE_SIZE
	 *
	 * @param $events  array  List of events to be run in the current period
	 *
	 * @return array
	 */
	private function reduce_queue( $events ) {
		// Loop through events, adding one of each action during each iteration
		$reduced_queue = array();
		$action_counts = array();

		$i = 1; // Intentionally not zero-indexed to facilitate comparisons against $action_counts members

		while ( $i <= 15 && count( $reduced_queue ) < JOB_QUEUE_SIZE && ! empty( $events ) ) {
			// Each time the events array is iterated over, move one instance of an action to the current queue
			foreach ( $events as $key => $event ) {
				$action = $event['action'];

				// Prime the count
				if ( ! isset( $action_counts[ $action ] ) ) {
					$action_counts[ $action ] = 0;
				}

				// Check and do the move
				if ( $action_counts[ $action ] < $i ) {
					$reduced_queue[] = $event;
					$action_counts[ $action ]++;
					unset( $events[ $key ] );
				}
			}

			// When done with an iteration and events remain, start again from the beginning of the $events array
			if ( empty( $events ) ) {
				break;
			} else {
				$i++;
				reset( $events );

				continue;
			}
		}

		/**
		 * IMPORTANT: DO NOT re-sort the $reduced_queue array from this point forward.
		 * Doing so defeats the preceding effort.
		 *
		 * While the events are now out of order with respect to timestamp, they're ordered
		 * such that one of each action is run before another of an already-run action.
		 * The timestamp mis-ordering is trivial given that we're only dealing with events
		 * for the current JOB_QUEUE_WINDOW_IN_SECONDS.
		 */

		// Finally, ensure that we don't have more than we need
		if ( count( $reduced_queue ) > JOB_QUEUE_SIZE ) {
			$reduced_queue = array_slice( $reduced_queue, 0, JOB_QUEUE_SIZE );
		}

		return $reduced_queue;
	}

	/**
	 * Execute a specific event
	 *
	 * @param $timestamp  int     Unix timestamp
	 * @param $action     string  md5 hash of the action used when the event is registered
	 * @param $instance   string  md5 hash of the event's arguments array, which Core uses to index the `cron` option
	 * @param $force      bool    Run event regardless of timestamp or lock status? eg, when executing jobs via wp-cli
	 *
	 * @return array|\WP_Error
	 */
	public function run_event( $timestamp, $action, $instance, $force = false ) {
		// Validate input data
		if ( empty( $timestamp ) || empty( $action ) || empty( $instance ) ) {
			return new \WP_Error( 'missing-data', __( 'Invalid or incomplete request data.', 'automattic-cron-control' ), array( 'status' => 400, ) );
		}

		// Ensure we don't run jobs ahead of time
		if ( ! $force && $timestamp > time() ) {
			return new \WP_Error( 'premature', sprintf( __( 'Job with identifier `%1$s` is not scheduled to run yet.', 'automattic-cron-control' ), "$timestamp-$action-$instance" ), array( 'status' => 403, ) );
		}

		// Find the event to retrieve the full arguments
		$event = $this->get_event( $timestamp, $action, $instance );

		// Nothing to do...
		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'no-event', sprintf( __( 'Job with identifier `%1$s` could not be found.', 'automattic-cron-control' ), "$timestamp-$action-$instance" ), array( 'status' => 404, ) );
		}

		unset( $timestamp, $action, $instance );

		// Limit how many events are processed concurrently, unless explicitly bypassed
		if ( ! $force ) {
			// Prepare event-level lock
			$this->prime_event_action_lock( $event );

			if ( ! $this->can_run_event( $event ) ) {
				return new \WP_Error( 'no-free-threads', sprintf( __( 'No resources available to run the job with action action `%1$s` and arguments `%2$s`.', 'automattic-cron-control' ), $event[ 'action' ], maybe_serialize( $event[ 'args' ] ) ), array( 'status' => 429, ) );
			}
		}

		// Mark the event completed, and reschedule if desired
		// Core does this before running the job, so we respect that
		$this->update_event_record( $event );

		// Run the event
		do_action_ref_array( $event['action'], $event['args'] );

		// Free process for the next event, unless it wasn't set to begin with
		if ( ! $force ) {
			$this->do_lock_cleanup( $event );
		}

		return array(
			'success' => true,
			'message' => sprintf( __( 'Job with action `%1$s` and arguments `%2$s` executed.', 'automattic-cron-control' ), $event['action'], maybe_serialize( $event['args'] ) ),
		);
	}

	/**
	 * Find an event's data using its hashed representations
	 *
	 * The `$instance` argument is hashed for us by Core, while we hash the action to avoid information disclosure
	 */
	private function get_event( $timestamp, $action_hashed, $instance ) {
		$events = get_option( 'cron' );
		$event  = false;

		$filtered_events = collapse_events_array( $events, $timestamp );

		foreach ( $filtered_events as $filtered_event ) {
			if ( hash_equals( md5( $filtered_event['action'] ), $action_hashed ) && hash_equals( $filtered_event['instance'], $instance ) ) {
				$event = $filtered_event['args'];
				$event['timestamp'] = $filtered_event['timestamp'];
				$event['action']    = $filtered_event['action'];
				$event['instance']  = $filtered_event['instance'];
				break;
			}
		}

		return $event;
	}

	/**
	 * Prime the event-specific lock
	 *
	 * Used to ensure only one instance of a particular event, such as `wp_version_check` runs at one time
	 *
	 * @param $event array Event data
	 */
	private function prime_event_action_lock( $event ) {
		Lock::prime_lock( $this->get_lock_key_for_event_action( $event ), JOB_LOCK_EXPIRY_IN_MINUTES * \MINUTE_IN_SECONDS );
	}

	/**
	 * Are resources available to run this event?
	 *
	 * @param $event array Event data
	 *
	 * @return bool
	 */
	private function can_run_event( $event ) {
		// Internal Events always run
		if ( is_internal_event( $event['action'] ) ) {
			return true;
		}

		// Check if any resources are available to execute this job
		if ( ! Lock::check_lock( self::LOCK, JOB_CONCURRENCY_LIMIT ) ) {
			return false;
		}

		// Limit to one concurrent execution of a specific action
		if ( ! Lock::check_lock( $this->get_lock_key_for_event_action( $event ), 1, JOB_LOCK_EXPIRY_IN_MINUTES * \MINUTE_IN_SECONDS ) ) {
			return false;
		}

		// Let's go!
		return true;
	}

	/**
	 * Free locks after event completes
	 *
	 * @param $event array Event data
	 */
	private function do_lock_cleanup( $event ) {
		// Lock isn't set when event is Internal, so we don't want to alter it
		if ( ! is_internal_event( $event['action'] ) ) {
			Lock::free_lock( self::LOCK );
		}

		// Reset individual event lock
		Lock::reset_lock( $this->get_lock_key_for_event_action( $event ), JOB_LOCK_EXPIRY_IN_MINUTES * \MINUTE_IN_SECONDS );
	}

	/**
	 * Turn the event action into a string that can be used with a lock
	 *
	 * @param $event array Event data
	 *
	 * @return string
	 */
	public function get_lock_key_for_event_action( $event ) {
		// Hashed solely to constrain overall length
		return md5( 'ev-' . $event['action'] );
	}

	/**
	 * Mark an event completed, and reschedule when requested
	 */
	private function update_event_record( $event ) {
		if ( false !== $event['schedule'] ) {
			// Get the existing ID
			$job_id = Cron_Options_CPT::instance()->job_exists( $event['timestamp'], $event['action'], $event['instance'], true );

			// Re-implements much of the logic from `wp_reschedule_event()`
			$schedules = wp_get_schedules();
			$interval  = 0;

			// First, we try to get it from the schedule
			if ( isset( $schedules[ $event['schedule'] ] ) ) {
				$interval = $schedules[ $event['schedule'] ]['interval'];
			}

			// Now we try to get it from the saved interval, in case the schedule disappears
			if ( 0 == $interval ) {
				$interval = $event['interval'];
			}

			// If we have an interval, update the existing event entry
			if ( 0 != $interval ) {
				// Determine new timestamp, according to how `wp_reschedule_event()` does
				$now           = time();
				$new_timestamp = $event['timestamp'];

				if ( $new_timestamp >= $now ) {
					$new_timestamp = $now + $interval;
				} else {
					$new_timestamp = $now + ( $interval - ( ( $now - $new_timestamp ) % $interval ) );
				}

				// Build the expected arguments format
				$event_args = array(
					'schedule' => $event['schedule'],
					'args'     => $event['args'],
					'interval' => $interval,
				);

				// Update CPT store
				Cron_Options_CPT::instance()->create_or_update_job( $new_timestamp, $event['action'], $event_args, $job_id );

				// If the event could be rescheduled, don't then delete it :)
				if ( is_int( $job_id ) && $job_id > 0 ) {
					return;
				}
			}
		}

		// Either event doesn't recur, or the interval couldn't be determined
		Cron_Options_CPT::instance()->mark_job_completed( $event['timestamp'], $event['action'], $event['instance'] );
	}
}

Events::instance();

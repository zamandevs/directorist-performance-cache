<?php

namespace Directorist\Performance_Cache;

/**
 * Late WordPress adapter for queueing, workers, recovery, and cleanup.
 */
final class Runtime_Controller {
    const WORKER_ACTION = 'directorist_performance_cache_worker';
    const RECOVERY_HOOK = 'directorist_performance_cache_recovery';
    const CLEANUP_HOOK  = 'directorist_performance_cache_cleanup';
    const CRON_SCHEDULE = 'directorist_five_minutes';

    /** @var Cache_Engine */
    private $engine;

    /** @var Warm_Queue */
    private $queue;

    /** @var Cache_Cleaner */
    private $cleaner;

    /** @var Warm_Worker */
    private $worker;

    /** @var callable */
    private $successor;

    /** @var callable */
    private $scheduler;

    /** @var callable */
    private $unscheduler;

    /** @var callable */
    private $schedule_status;

    /** @var bool */
    private $hooks_registered = false;

    /**
     * @param Cache_Engine  $engine Cache engine.
     * @param Warm_Queue    $queue Persistent warm queue.
     * @param Cache_Cleaner $cleaner Bounded cleaner.
     * @param array         $options Testable WordPress boundaries.
     */
    public function __construct( Cache_Engine $engine, Warm_Queue $queue, Cache_Cleaner $cleaner, array $options = [] ) {
        $this->engine          = $engine;
        $this->queue           = $queue;
        $this->cleaner         = $cleaner;
        $requester             = isset( $options['requester'] ) && is_callable( $options['requester'] ) ? $options['requester'] : [ $this, 'request_url' ];
        $this->successor       = isset( $options['successor'] ) && is_callable( $options['successor'] ) ? $options['successor'] : [ $this, 'dispatch_successor' ];
        $this->scheduler       = isset( $options['scheduler'] ) && is_callable( $options['scheduler'] ) ? $options['scheduler'] : [ $this, 'schedule_hook' ];
        $this->unscheduler     = isset( $options['unscheduler'] ) && is_callable( $options['unscheduler'] ) ? $options['unscheduler'] : [ $this, 'unschedule_hook' ];
        $this->schedule_status = isset( $options['schedule_status'] ) && is_callable( $options['schedule_status'] ) ? $options['schedule_status'] : [ $this, 'scheduled_at' ];
        $this->worker          = new Warm_Worker( $queue, $requester, $this->successor );
        $this->engine->set_warm_handler( [ $this, 'enqueue' ] );
    }

    /**
     * @param string[] $urls Public same-origin URLs.
     * @return array
     */
    public function enqueue( array $urls ) {
        call_user_func( $this->scheduler, self::RECOVERY_HOOK, 300 );
        $result = $this->queue->enqueue( $urls );

        if ( ! empty( $result['success'] ) && ! empty( $result['queued'] ) ) {
            $result['successor'] = $this->call_successor();
        } else {
            $result['successor'] = false;
        }

        return $result;
    }

    /** @return array */
    public function run_worker() {
        return $this->worker->run( 3 );
    }

    /** @return array */
    public function run_cleanup() {
        return $this->cleaner->run( 100 );
    }

    /** @return array */
    public function pause() {
        return $this->queue->pause( 'manual' );
    }

    /** @return array */
    public function resume() {
        $result = $this->queue->resume();

        if ( ! empty( $result['success'] ) && $this->queue->has_runnable_items() ) {
            $result['successor'] = $this->call_successor();
        }

        return $result;
    }

    /** @return array */
    public function cancel() {
        return $this->queue->cancel();
    }

    /**
     * @param int $site_id Site ID.
     * @return array
     */
    public function purge_site( $site_id = 1 ) {
        return $this->engine->invalidate(
            [
                'site_id'      => max( 1, (int) $site_id ),
                'urls'         => [],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => true,
            ]
        );
    }

    /** @return array */
    public function status() {
        return [
            'engine'            => $this->engine->get_status(),
            'queue'             => $this->queue->status(),
            'recovery_scheduled'=> (int) call_user_func( $this->schedule_status, self::RECOVERY_HOOK ),
            'cleanup_scheduled' => (int) call_user_func( $this->schedule_status, self::CLEANUP_HOOK ),
        ];
    }

    /** @return array */
    public function verify() {
        $queue    = $this->queue->status();
        $recovery = (int) call_user_func( $this->schedule_status, self::RECOVERY_HOOK );
        $cleanup  = (int) call_user_func( $this->schedule_status, self::CLEANUP_HOOK );
        $engine   = $this->engine->is_available();
        $success  = $engine && ! empty( $queue['success'] ) && 0 < $recovery && 0 < $cleanup;

        return [
            'success'            => $success,
            'code'               => $success ? 'healthy' : 'unhealthy',
            'engine_available'   => $engine,
            'queue_available'    => ! empty( $queue['success'] ),
            'recovery_scheduled' => $recovery,
            'cleanup_scheduled'  => $cleanup,
        ];
    }

    /** @return array */
    public function activate() {
        $recovery = (bool) call_user_func( $this->scheduler, self::RECOVERY_HOOK, 300 );
        $cleanup  = (bool) call_user_func( $this->scheduler, self::CLEANUP_HOOK, 86400 );

        return [
            'success' => $recovery && $cleanup,
            'code'    => $recovery && $cleanup ? 'scheduled' : 'schedule_failed',
        ];
    }

    /** @return array */
    public function deactivate() {
        $recovery = (bool) call_user_func( $this->unscheduler, self::RECOVERY_HOOK );
        $cleanup  = (bool) call_user_func( $this->unscheduler, self::CLEANUP_HOOK );
        $cancel   = $this->queue->cancel();
        $success  = $recovery && $cleanup && ! empty( $cancel['success'] );

        return [
            'success' => $success,
            'code'    => $success ? 'unscheduled' : 'unschedule_failed',
        ];
    }

    /** @return void */
    public function ensure_scheduled() {
        $this->activate();
    }

    /** @return bool */
    public function register_wordpress_hooks() {
        if ( $this->hooks_registered || ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
            return false;
        }

        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
        add_action( 'wp_ajax_' . self::WORKER_ACTION, [ $this, 'handle_worker' ] );
        add_action( 'wp_ajax_nopriv_' . self::WORKER_ACTION, [ $this, 'handle_worker' ] );
        add_action( self::RECOVERY_HOOK, [ $this, 'run_worker' ] );
        add_action( self::CLEANUP_HOOK, [ $this, 'run_cleanup' ] );
        add_action( 'cli_init', [ $this, 'register_cli' ] );

        if ( ( function_exists( 'is_admin' ) && is_admin() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            add_action( 'init', [ $this, 'ensure_scheduled' ], 20 );
        }

        $this->hooks_registered = true;

        return true;
    }

    /**
     * @param array $schedules Cron schedules.
     * @return array
     */
    public function add_cron_schedule( $schedules ) {
        $schedules = is_array( $schedules ) ? $schedules : [];
        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => 300,
            'display'  => 'Every five minutes',
        ];

        return $schedules;
    }

    /** @return void */
    public function handle_worker() {
        $provided = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Token-authenticated loopback worker.

        if ( ! hash_equals( $this->worker_token(), $provided ) ) {
            $this->send_json( [ 'success' => false, 'code' => 'invalid_worker_token' ], 403 );

            return;
        }

        $this->send_json( $this->run_worker(), 200 );
    }

    /** @return void */
    public function register_cli() {
        if ( ! class_exists( 'WP_CLI', false ) || ! method_exists( 'WP_CLI', 'add_command' ) ) {
            return;
        }

        \WP_CLI::add_command( 'directorist cache', new CLI_Command( $this ) );
    }

    /**
     * @param string $url Public URL.
     * @return array
     */
    public function request_url( $url ) {
        if ( ! function_exists( 'wp_safe_remote_get' ) ) {
            return [ 'success' => false, 'code' => 'http_unavailable' ];
        }

        $response = wp_safe_remote_get(
            $url,
            [
                'timeout'     => 20,
                'redirection' => 3,
                'headers'     => [
                    'Accept'                     => 'text/html,application/xhtml+xml',
                    'X-Directorist-Cache-Warm'   => '1',
                ],
            ]
        );

        if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
            return [ 'success' => false, 'code' => 'http_error' ];
        }

        $status  = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
        $success = 200 <= $status && 300 > $status;

        return [
            'success' => $success,
            'code'    => $success ? 'http_' . $status : 'http_failed_' . $status,
        ];
    }

    /** @return bool */
    public function dispatch_successor() {
        if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'admin_url' ) ) {
            return false;
        }

        $response = wp_remote_post(
            admin_url( 'admin-ajax.php' ),
            [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => true,
                'body'      => [
                    'action' => self::WORKER_ACTION,
                    'token'  => $this->worker_token(),
                ],
            ]
        );

        return ! ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) );
    }

    /**
     * @param string $hook Cron hook.
     * @param int    $interval Interval seconds.
     * @return bool
     */
    public function schedule_hook( $hook, $interval ) {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return false;
        }

        if ( wp_next_scheduled( $hook ) ) {
            return true;
        }

        $recurrence = 86400 <= (int) $interval ? 'daily' : self::CRON_SCHEDULE;

        return false !== wp_schedule_event( time() + 60, $recurrence, $hook );
    }

    /**
     * @param string $hook Cron hook.
     * @return bool
     */
    public function unschedule_hook( $hook ) {
        return function_exists( 'wp_clear_scheduled_hook' ) && false !== wp_clear_scheduled_hook( $hook );
    }

    /**
     * @param string $hook Cron hook.
     * @return int
     */
    public function scheduled_at( $hook ) {
        return function_exists( 'wp_next_scheduled' ) ? (int) wp_next_scheduled( $hook ) : 0;
    }

    /** @return bool */
    private function call_successor() {
        try {
            return (bool) call_user_func( $this->successor );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /** @return string */
    private function worker_token() {
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : __FILE__;
        $site = function_exists( 'site_url' ) ? site_url( '/' ) : 'directorist-performance-cache';

        return hash_hmac( 'sha256', self::WORKER_ACTION . '|' . $site, $salt );
    }

    /**
     * @param array $data Response data.
     * @param int   $status HTTP status.
     * @return void
     */
    private function send_json( array $data, $status ) {
        if ( function_exists( 'wp_send_json' ) ) {
            wp_send_json( $data, $status );

            return;
        }

        echo json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON fallback for non-WordPress execution.
    }
}

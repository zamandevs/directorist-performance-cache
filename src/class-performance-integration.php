<?php

namespace Directorist\Performance_Cache;

/**
 * Adapts companion health and controls to the provider-neutral core screen.
 */
final class Performance_Integration {
    /** @var Runtime_Controller */
    private $controller;

    /** @var callable */
    private $lifecycle_resolver;

    /** @var callable */
    private $config_resolver;

    /** @var callable */
    private $inventory_resolver;

    /** @var callable */
    private $generation_bump;

    /** @var callable */
    private $reporter;

    /** @var bool */
    private $registered = false;

    /**
     * @param Runtime_Controller $controller Queue/runtime controls.
     * @param mixed              $lifecycle Lifecycle manager or lazy resolver.
     * @param mixed              $config Config manager or lazy resolver.
     * @param mixed              $inventory Inventory or lazy resolver.
     * @param callable           $generation_bump Lifecycle generation callback.
     * @param callable|null      $reporter Core event callback.
     */
    public function __construct( Runtime_Controller $controller, $lifecycle, $config, $inventory, $generation_bump, $reporter = null ) {
        $this->controller      = $controller;
        $this->lifecycle_resolver = $this->resolver( $lifecycle );
        $this->config_resolver    = $this->resolver( $config );
        $this->inventory_resolver = $this->resolver( $inventory );
        $this->generation_bump = is_callable( $generation_bump ) ? $generation_bump : static function () {
            return [ 'success' => false, 'code' => 'generation_unavailable' ];
        };
        $this->reporter        = is_callable( $reporter ) ? $reporter : static function ( $level, $code, array $context ) {
            if ( function_exists( 'directorist_page_cache_record_performance_event' ) ) {
                directorist_page_cache_record_performance_event( $level, $code, $context );
            }
        };
    }

    /** @return bool */
    public function register_wordpress_hooks() {
        if ( $this->registered || ! function_exists( 'add_filter' ) || ! function_exists( 'add_action' ) ) {
            return false;
        }

        add_filter( 'directorist_page_cache_performance_status', [ $this, 'extend_status' ], 10, 2 );
        add_filter( 'directorist_page_cache_performance_operation', [ $this, 'handle_operation' ], 10, 4 );
        add_action( 'directorist_page_cache_enabled_changed', [ $this, 'sync_enabled' ], 10, 2 );
        add_action( 'plugins_loaded', [ $this, 'sync_current_core_state' ], PHP_INT_MAX - 10 );
        $this->registered = true;

        return true;
    }

    /** @return array */
    public function sync_current_core_state() {
        if ( ! function_exists( 'directorist_page_cache_is_enabled' ) ) {
            return [ 'success' => true, 'code' => 'core_settings_unavailable', 'changed' => false ];
        }

        $enabled = (bool) directorist_page_cache_is_enabled();

        if ( isset( $GLOBALS['directorist_performance_cache_early_config'] ) && is_array( $GLOBALS['directorist_performance_cache_early_config'] ) ) {
            $config_enabled = ! array_key_exists( 'enabled', $GLOBALS['directorist_performance_cache_early_config'] ) || ! empty( $GLOBALS['directorist_performance_cache_early_config']['enabled'] );

            if ( $enabled === $config_enabled ) {
                return [ 'success' => true, 'code' => $enabled ? 'enabled' : 'disabled', 'changed' => false ];
            }
        }

        return $this->sync_enabled( $enabled );
    }

    /**
     * @param array  $status Core status.
     * @param object $provider Selected provider.
     * @return array
     */
    public function extend_status( $status, $provider ) {
        if ( ! is_array( $status ) ) {
            return $status;
        }

        unset( $provider );

        try {
            $lifecycle = $this->lifecycle()->status();
            $config    = $this->config()->status();
            $runtime   = $this->controller->status();
            $inventory = $this->inventory()->status();
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $status['extensions']['directorist-cache'] = [
                'label'  => 'Directorist fallback cache',
                'values' => [ 'Health' => 'diagnostic_exception' ],
            ];

            return $status;
        }

        $queue     = isset( $runtime['queue'] ) && is_array( $runtime['queue'] ) ? $runtime['queue'] : [];
        $totals    = isset( $queue['totals'] ) && is_array( $queue['totals'] ) ? $queue['totals'] : [];
        $pending   = isset( $queue['pending'] ) ? (int) $queue['pending'] : 0;
        $inflight  = isset( $queue['inflight'] ) ? (int) $queue['inflight'] : 0;
        $entries   = isset( $inventory['entries'] ) ? (int) $inventory['entries'] : 0;
        $status['extensions']['directorist-cache'] = [
            'label'  => 'Directorist fallback cache',
            'values' => [
                'Drop-in'          => isset( $lifecycle['dropin'] ) ? $lifecycle['dropin'] : 'unknown',
                'Generated config' => isset( $lifecycle['config'] ) ? $lifecycle['config'] : 'unknown',
                'Early cache'      => ! empty( $config['success'] ) && ! empty( $config['enabled'] ) ? 'enabled' : ( ! empty( $config['success'] ) ? 'disabled' : $config['code'] ),
                'Cache directory'  => ! empty( $inventory['writable'] ) ? 'writable' : 'not writable',
                'Cached entries'   => $entries,
                'Orphan files'     => isset( $inventory['orphans'] ) ? (int) $inventory['orphans'] : 0,
                'Generations'      => isset( $inventory['generations'] ) ? (int) $inventory['generations'] : 0,
                'Queue pending'    => $pending,
                'Queue inflight'   => $inflight,
                'Queue failed'     => isset( $totals['failed'] ) ? (int) $totals['failed'] : 0,
                'Queue state'      => ! empty( $queue['paused'] ) ? 'paused' : ( 0 < ( isset( $queue['circuit_open_until'] ) ? (int) $queue['circuit_open_until'] : 0 ) ? 'circuit open' : 'running' ),
                'Recovery'         => ! empty( $runtime['recovery_scheduled'] ) ? 'scheduled' : 'missing',
                'Cleanup'          => ! empty( $runtime['cleanup_scheduled'] ) ? 'scheduled' : 'missing',
            ],
        ];

        if ( isset( $status['route_flow'] ) && is_array( $status['route_flow'] ) ) {
            $status['route_flow']['queued'] = $pending + $inflight;
            $status['route_flow']['cached'] = $entries;
        }

        return $status;
    }

    /**
     * @param mixed  $result Existing adapter result.
     * @param string $action Operation name.
     * @param array  $input Operation input.
     * @param object $provider Selected provider.
     * @return mixed
     */
    public function handle_operation( $result, $action, $input, $provider ) {
        unset( $input );

        if ( is_array( $result ) || ! $this->owns_provider( $provider ) ) {
            return $result;
        }

        try {
            if ( 'pause' === $action ) {
                return $this->controller->pause();
            }

            if ( 'resume' === $action ) {
                return $this->controller->resume();
            }

            if ( 'cancel' === $action ) {
                return $this->controller->cancel();
            }

            if ( 'verify' === $action ) {
                return $this->verify();
            }
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return [ 'success' => false, 'code' => 'operation_exception' ];
        }

        return $result;
    }

    /**
     * Synchronize core state with the early drop-in without enabling stale data.
     *
     * @param bool  $enabled Desired state.
     * @param array $settings Core settings snapshot.
     * @return array
     */
    public function sync_enabled( $enabled, $settings = [] ) {
        unset( $settings );
        $enabled = (bool) $enabled;
        $status  = $this->config()->status();

        if ( empty( $status['success'] ) ) {
            $this->report( 'error', 'cache-config-sync-failed', [ 'code' => $status['code'] ] );

            return $status;
        }

        if ( (bool) $status['enabled'] === $enabled ) {
            return [ 'success' => true, 'code' => $enabled ? 'enabled' : 'disabled', 'changed' => false ];
        }

        if ( $enabled ) {
            $generation = call_user_func( $this->generation_bump );

            if ( ! is_array( $generation ) || empty( $generation['success'] ) ) {
                $code = is_array( $generation ) && isset( $generation['code'] ) ? $generation['code'] : 'generation_failed';
                $this->report( 'error', 'cache-enable-failed', [ 'code' => $code ] );

                return [ 'success' => false, 'code' => $code ];
            }

            $result = $this->config()->set_enabled( true );

            if ( ! empty( $result['success'] ) ) {
                $this->controller->resume();
            }
        } else {
            $result = $this->config()->set_enabled( false );

            if ( ! empty( $result['success'] ) ) {
                $this->controller->pause();
                $generation = call_user_func( $this->generation_bump );

                if ( ! is_array( $generation ) || empty( $generation['success'] ) ) {
                    $generation_code     = is_array( $generation ) && isset( $generation['code'] ) ? $generation['code'] : 'generation_failed';
                    $result['generation'] = $generation_code;
                    $this->report( 'warning', 'cache-disable-generation-failed', [ 'code' => $generation_code ] );
                }
            }
        }

        $this->report(
            ! empty( $result['success'] ) ? 'success' : 'error',
            ! empty( $result['success'] ) ? ( $enabled ? 'cache-integration-enabled' : 'cache-integration-disabled' ) : 'cache-config-sync-failed',
            [ 'code' => isset( $result['code'] ) ? $result['code'] : 'unknown' ]
        );

        return $result;
    }

    /** @return array */
    private function verify() {
        $runtime   = $this->controller->verify();
        $lifecycle = $this->lifecycle()->status();
        $config    = $this->config()->status();
        $inventory = $this->inventory()->status();
        $success   = ! empty( $runtime['success'] )
            && 'owned' === ( isset( $lifecycle['dropin'] ) ? $lifecycle['dropin'] : '' )
            && 'owned' === ( isset( $lifecycle['config'] ) ? $lifecycle['config'] : '' )
            && ! empty( $config['success'] )
            && ! empty( $config['enabled'] )
            && ! empty( $inventory['success'] )
            && ! empty( $inventory['writable'] );

        return [
            'success'   => $success,
            'code'      => $success ? 'healthy' : ( ! empty( $config['success'] ) && empty( $config['enabled'] ) ? 'integration_disabled' : 'unhealthy' ),
            'runtime'   => isset( $runtime['code'] ) ? $runtime['code'] : 'unknown',
            'config'    => isset( $config['code'] ) ? $config['code'] : 'unknown',
            'inventory' => isset( $inventory['code'] ) ? $inventory['code'] : 'unknown',
        ];
    }

    /**
     * @param object $provider Selected provider.
     * @return bool
     */
    private function owns_provider( $provider ) {
        try {
            return is_object( $provider ) && is_callable( [ $provider, 'get_id' ] ) && 'directorist-cache' === $provider->get_id();
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /**
     * @param mixed $service Service instance or resolver.
     * @return callable
     */
    private function resolver( $service ) {
        if ( is_callable( $service ) ) {
            return $service;
        }

        return static function () use ( $service ) {
            return $service;
        };
    }

    /** @return Lifecycle_Manager */
    private function lifecycle() {
        $service = call_user_func( $this->lifecycle_resolver );

        if ( ! $service instanceof Lifecycle_Manager ) {
            throw new \RuntimeException( 'Lifecycle manager unavailable.' );
        }

        return $service;
    }

    /** @return Early_Config_Manager */
    private function config() {
        $service = call_user_func( $this->config_resolver );

        if ( ! $service instanceof Early_Config_Manager ) {
            throw new \RuntimeException( 'Early config manager unavailable.' );
        }

        return $service;
    }

    /** @return Cache_Inventory */
    private function inventory() {
        $service = call_user_func( $this->inventory_resolver );

        if ( ! $service instanceof Cache_Inventory ) {
            throw new \RuntimeException( 'Cache inventory unavailable.' );
        }

        return $service;
    }

    /**
     * @param string $level Event level.
     * @param string $code Stable event code.
     * @param array  $context Bounded context.
     * @return void
     */
    private function report( $level, $code, array $context ) {
        try {
            call_user_func( $this->reporter, $level, $code, $context );
        } catch ( \Throwable $exception ) {
            unset( $exception );
        }
    }
}

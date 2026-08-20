<?php

namespace Directorist\Performance_Cache;

/**
 * Directorist provider bridge for the optional early cache engine.
 */
final class Core_Provider implements \Directorist\Cache\Cache_Provider {
    /** @var callable */
    private $engine_resolver;

    /** @var callable */
    private $health_resolver;

    /** @var callable */
    private $enabled_resolver;

    /** @var bool */
    private $engine_resolved = false;

    /** @var object|null */
    private $engine;

    /**
     * @param callable|null $engine_resolver Engine resolver.
     * @param callable|null $health_resolver Ownership health resolver.
     * @param callable|null $enabled_resolver Early-cache state resolver.
     */
    public function __construct( $engine_resolver = null, $health_resolver = null, $enabled_resolver = null ) {
        $this->engine_resolver = is_callable( $engine_resolver ) ? $engine_resolver : static function () {
            return function_exists( 'directorist_performance_cache_engine' ) ? directorist_performance_cache_engine() : null;
        };
        $this->health_resolver = is_callable( $health_resolver ) ? $health_resolver : static function () {
            if ( ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'DIRECTORIST_PERFORMANCE_CACHE_DIR' ) ) {
                return false;
            }

            $installer = new Dropin_Installer( WP_CONTENT_DIR, DIRECTORIST_PERFORMANCE_CACHE_DIR );
            $status    = $installer->status();

            return 'owned' === $status['dropin'] && 'owned' === $status['config'];
        };
        $this->enabled_resolver = is_callable( $enabled_resolver ) ? $enabled_resolver : static function () {
            if ( isset( $GLOBALS['directorist_performance_cache_early_config'] ) && is_array( $GLOBALS['directorist_performance_cache_early_config'] ) ) {
                $config = $GLOBALS['directorist_performance_cache_early_config'];

                return ! array_key_exists( 'enabled', $config ) || ! empty( $config['enabled'] );
            }

            if ( ! defined( 'WP_CONTENT_DIR' ) ) {
                return true;
            }

            $status = ( new Early_Config_Manager( WP_CONTENT_DIR . '/cache/directorist-performance-cache/config.json' ) )->status();

            return ! empty( $status['success'] ) && ! empty( $status['enabled'] );
        };
    }

    /** @return string */
    public function get_id() {
        return 'directorist-cache';
    }

    /** @return bool */
    public function is_available() {
        try {
            if ( ! call_user_func( $this->enabled_resolver ) ) {
                return false;
            }

            if ( ! call_user_func( $this->health_resolver ) ) {
                return false;
            }

            $engine = $this->resolve_engine();

            return is_object( $engine )
                && is_callable( [ $engine, 'is_available' ] )
                && $engine->is_available()
                && is_callable( [ $engine, 'invalidate' ] );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return false;
        }
    }

    /** @return string[] */
    public function get_capabilities() {
        $capabilities = [
            'purge_dependencies',
            'purge_generations',
            'purge_site',
            'purge_url',
            'purge_urls',
        ];

        try {
            $engine = $this->resolve_engine();

            if ( is_object( $engine ) && is_callable( [ $engine, 'supports_warm' ] ) && $engine->supports_warm() ) {
                $capabilities[] = 'warm_urls';
            }
        } catch ( \Throwable $exception ) {
            unset( $exception );
        }

        return $capabilities;
    }

    /**
     * @param string $capability Capability identifier.
     * @return bool
     */
    public function supports( $capability ) {
        return in_array( $capability, $this->get_capabilities(), true );
    }

    /**
     * @param array $request Invalidation plan.
     * @return array
     */
    public function invalidate( array $request ) {
        if ( ! $this->is_available() ) {
            return $this->result( false, 'engine_unavailable' );
        }

        return $this->call_engine( 'invalidate', [ $request ] );
    }

    /**
     * @param string[] $urls Public URLs.
     * @return array
     */
    public function warm( array $urls ) {
        if ( ! $this->is_available() ) {
            return $this->result( false, 'engine_unavailable' );
        }

        if ( ! $this->supports( 'warm_urls' ) ) {
            return $this->result( false, 'capability_unavailable' );
        }

        return $this->call_engine( 'warm', [ $urls ] );
    }

    /** @return array */
    public function get_status() {
        try {
            $enabled = (bool) call_user_func( $this->enabled_resolver );
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $enabled = false;
        }

        $status = [
            'id'           => $this->get_id(),
            'available'    => $this->is_available(),
            'capabilities' => $this->get_capabilities(),
            'enabled'      => $enabled,
        ];

        if ( ! $status['available'] ) {
            $status['code'] = $enabled ? 'engine_unavailable' : 'integration_disabled';

            return $status;
        }

        try {
            $engine = $this->resolve_engine();

            if ( is_callable( [ $engine, 'get_status' ] ) ) {
                $status['engine'] = $engine->get_status();
            }
        } catch ( \Throwable $exception ) {
            unset( $exception );
            $status['available'] = false;
            $status['code']      = 'engine_exception';
        }

        return $status;
    }

    /** @return object|null */
    private function resolve_engine() {
        if ( ! $this->engine_resolved ) {
            $this->engine          = call_user_func( $this->engine_resolver );
            $this->engine_resolved = true;
        }

        return $this->engine;
    }

    /**
     * @param string $method Engine method.
     * @param array  $arguments Engine arguments.
     * @return array
     */
    private function call_engine( $method, array $arguments ) {
        try {
            $result = call_user_func_array( [ $this->resolve_engine(), $method ], $arguments );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return $this->result( false, 'engine_exception' );
        }

        return is_array( $result ) ? $result : $this->result( false, 'invalid_engine_result' );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success'  => (bool) $success,
            'code'     => $code,
            'provider' => $this->get_id(),
        ];
    }
}

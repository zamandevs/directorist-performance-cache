<?php

namespace Directorist\Performance_Cache {
    require_once __DIR__ . '/class-request-key.php';
    require_once __DIR__ . '/class-request-guard.php';
    require_once __DIR__ . '/class-cache-paths.php';
    require_once __DIR__ . '/class-atomic-file-writer.php';
    require_once __DIR__ . '/class-generation-store.php';
    require_once __DIR__ . '/class-cache-storage.php';
    require_once __DIR__ . '/class-response-validator.php';

    /**
     * Coordinates fail-open early hits and core-approved response storage.
     */
    final class Cache_Engine {
        /** @var array */
        private $config;

        /** @var Cache_Storage */
        private $storage;

        /** @var Request_Guard */
        private $guard;

        /** @var Request_Key */
        private $request_key;

        /** @var Response_Validator */
        private $validator;

        /** @var callable */
        private $core_begin;

        /** @var callable */
        private $core_finish;

        /** @var array */
        private $current_key = [];

        /** @var string */
        private $method = '';

        /** @var bool */
        private $regeneration = false;

        /** @var bool */
        private $capture_started = false;

        /** @var bool */
        private $buffer_overflow = false;

        /** @var string */
        private $captured_body = '';

        /** @var bool */
        private $hooks_registered = false;

        /** @var array|null */
        private $early_result;

        /** @var callable|null */
        private $warm_handler;

        /**
         * @param array $config Early engine configuration.
         * @param array $options Testable runtime boundaries.
         */
        public function __construct( array $config, array $options = [] ) {
            $this->config      = $this->normalize_config( $config );
            $this->request_key = new Request_Key();
            $this->guard       = new Request_Guard( $this->request_key );
            $clock             = isset( $options['clock'] ) && is_callable( $options['clock'] ) ? $options['clock'] : null;
            $this->storage     = new Cache_Storage( $this->config['cache_dir'], $clock );
            $this->validator   = new Response_Validator();
            $this->core_begin  = isset( $options['core_begin'] ) && is_callable( $options['core_begin'] )
                ? $options['core_begin']
                : static function () {
                    if ( ! function_exists( 'directorist_page_cache_begin_response_capture' ) ) {
                        return [ 'eligible' => false, 'reason' => 'core_unavailable' ];
                    }

                    return directorist_page_cache_begin_response_capture();
                };
            $this->core_finish = isset( $options['core_finish'] ) && is_callable( $options['core_finish'] )
                ? $options['core_finish']
                : static function () {
                    if ( ! function_exists( 'directorist_page_cache_finish_response_capture' ) ) {
                        return [ 'eligible' => false, 'reason' => 'core_unavailable', 'dependencies' => [] ];
                    }

                    return directorist_page_cache_finish_response_capture();
                };
        }

        /** @return bool */
        public function is_available() {
            return $this->storage->is_available();
        }

        /**
         * Resolve one anonymous request before WordPress loads.
         *
         * @param array $server HTTP server values.
         * @param array $cookies Parsed cookies.
         * @return array
         */
        public function boot_early( array $server, array $cookies = [] ) {
            if ( null !== $this->early_result ) {
                return $this->early_result;
            }

            $decision = $this->guard->evaluate( $server, $cookies );

            if ( empty( $decision['eligible'] ) ) {
                $this->early_result = $this->early_result( false, false, $decision['code'] );

                return $this->early_result;
            }

            $this->current_key = $decision['request'];
            $this->method      = $decision['method'];
            $cached            = $this->storage->load( $this->current_key );

            if ( ! empty( $cached['hit'] ) && ! array_key_exists( 'directorist:0:lifecycle', $cached['metadata']['generations'] ) ) {
                $cached = [ 'hit' => false, 'code' => 'legacy_entry' ];
            }

            if ( ! empty( $cached['hit'] ) ) {
                $this->early_result = $this->cached_response( $cached, $server );

                return $this->early_result;
            }

            if ( 'HEAD' === $this->method ) {
                $this->early_result = $this->early_result( false, false, 'head_miss' );

                return $this->early_result;
            }

            $lock = $this->storage->begin_regeneration( $this->current_key );

            if ( empty( $lock['success'] ) ) {
                $this->early_result = $this->early_result( false, false, $lock['code'] );

                return $this->early_result;
            }

            $this->regeneration = true;
            $this->early_result = $this->early_result( false, true, $cached['code'] );

            return $this->early_result;
        }

        /**
         * Begin core route/dependency collection before template rendering.
         *
         * @return array
         */
        public function begin_capture() {
            if ( ! $this->regeneration || 'GET' !== $this->method ) {
                return [ 'eligible' => false, 'reason' => 'request_not_prepared' ];
            }

            try {
                $result = call_user_func( $this->core_begin );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $result = [ 'eligible' => false, 'reason' => 'core_exception' ];
            }

            if ( ! is_array( $result ) || empty( $result['eligible'] ) ) {
                $this->release_request();

                return is_array( $result ) ? $result : [ 'eligible' => false, 'reason' => 'invalid_core_result' ];
            }

            $this->capture_started = true;

            return $result;
        }

        /**
         * Validate and store one complete rendered response.
         *
         * @param string $body Complete body.
         * @param int    $status HTTP status.
         * @param array  $headers Header lines.
         * @return array
         */
        public function finalize_capture( $body, $status, array $headers ) {
            if ( ! $this->capture_started ) {
                $this->release_request();

                return $this->operation_result( false, 'capture_not_started' );
            }

            try {
                $descriptor = call_user_func( $this->core_finish );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $descriptor = [ 'eligible' => false, 'reason' => 'core_exception', 'dependencies' => [] ];
            }

            $descriptor = is_array( $descriptor ) ? $descriptor : [];
            $dependencies = isset( $descriptor['dependencies'] ) && is_array( $descriptor['dependencies'] ) ? $descriptor['dependencies'] : [];
            $dependencies[] = 'directorist:0:lifecycle';
            $descriptor['dependencies'] = array_values( array_unique( $dependencies ) );
            $validated  = $this->validator->validate( $body, $status, $headers, $descriptor );

            if ( empty( $validated['accepted'] ) ) {
                $this->release_request();

                return $this->operation_result( false, $validated['code'] );
            }

            $result = $this->storage->store(
                $this->current_key,
                $body,
                $descriptor,
                $validated['headers'],
                $this->config['ttl'],
                $this->config['stale_ttl']
            );
            $this->release_request();

            return $result;
        }

        /**
         * Register late capture only for a prepared cold request.
         *
         * @return bool
         */
        public function register_wordpress_hooks() {
            if ( $this->hooks_registered || ! $this->regeneration || ! function_exists( 'add_action' ) ) {
                return false;
            }

            add_action( 'template_redirect', [ $this, 'begin_wordpress_capture' ], -1000 );
            $this->hooks_registered = true;

            return true;
        }

        /** @return void */
        public function begin_wordpress_capture() {
            if ( headers_sent() ) {
                $this->release_request();

                return;
            }

            $result = $this->begin_capture();

            if ( empty( $result['eligible'] ) ) {
                return;
            }

            $started = ob_start( [ $this, 'capture_output' ] );

            if ( ! $started ) {
                $this->release_request();
            }
        }

        /**
         * Observe output without changing what WordPress sends.
         *
         * @param string $buffer Output chunk.
         * @param int    $phase Output-handler phase.
         * @return string
         */
        public function capture_output( $buffer, $phase ) {
            if ( ! $this->buffer_overflow ) {
                if ( Cache_Storage::MAX_BODY_BYTES < strlen( $this->captured_body ) + strlen( $buffer ) ) {
                    $this->captured_body   = '';
                    $this->buffer_overflow = true;
                } else {
                    $this->captured_body .= $buffer;
                }
            }

            if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
                $body = $this->buffer_overflow ? '' : $this->captured_body;
                $this->finalize_capture( $body, http_response_code(), headers_list() );
            }

            return $buffer;
        }

        /**
         * Send a previously prepared hit response.
         *
         * @param array $response Hit response.
         * @return bool
         */
        public function send_response( array $response ) {
            if ( empty( $response['served'] ) ) {
                return false;
            }

            http_response_code( $response['status'] );

            foreach ( $response['headers'] as $name => $value ) {
                header( $name . ': ' . $value, true );
            }

            if ( '' !== $response['body'] ) {
                echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integrity-checked cached response.
            }

            return true;
        }

        /**
         * Apply exact and generation-based invalidation.
         *
         * @param array $plan Normalized Directorist invalidation plan.
         * @return array
         */
        public function invalidate( array $plan ) {
            $site_id      = isset( $plan['site_id'] ) ? max( 1, (int) $plan['site_id'] ) : 1;
            $dependencies = $this->string_list( isset( $plan['dependencies'] ) ? $plan['dependencies'] : [] );
            $generations  = $this->string_list( isset( $plan['generations'] ) ? $plan['generations'] : [] );

            if ( ! empty( $plan['conservative'] ) ) {
                $generations[] = 'directorist:' . $site_id . ':site';
            }

            $generation_keys = array_values( array_unique( array_merge( $dependencies, $generations ) ) );
            $generation      = $this->storage->bump_generations( $generation_keys );
            $purged          = 0;
            $valid_urls      = true;

            foreach ( $this->string_list( isset( $plan['urls'] ) ? $plan['urls'] : [] ) as $url ) {
                $key = $this->request_key->from_url( $url );

                if ( empty( $key['success'] ) ) {
                    $valid_urls = false;
                    continue;
                }

                $result = $this->storage->purge( $key );

                if ( empty( $result['success'] ) ) {
                    $valid_urls = false;
                } else {
                    ++$purged;
                }
            }

            $success = ! empty( $generation['success'] ) && $valid_urls;

            return [
                'success'            => $success,
                'code'               => $success ? 'invalidated' : 'invalidation_failed',
                'provider'           => 'directorist-cache',
                'purged_urls'        => $purged,
                'bumped_generations' => count( $generation_keys ),
            ];
        }

        /**
         * Attach the late WordPress queue boundary after the plugin loads.
         *
         * @param callable $handler Warm queue handler.
         * @return bool
         */
        public function set_warm_handler( $handler ) {
            if ( ! is_callable( $handler ) ) {
                return false;
            }

            $this->warm_handler = $handler;

            return true;
        }

        /** @return bool */
        public function supports_warm() {
            return is_callable( $this->warm_handler );
        }

        /**
         * @param string[] $urls Public URLs.
         * @return array
         */
        public function warm( array $urls ) {
            if ( ! $this->supports_warm() ) {
                return $this->operation_result( false, 'warming_unavailable' );
            }

            try {
                $result = call_user_func( $this->warm_handler, $urls );
            } catch ( \Throwable $exception ) {
                unset( $exception );

                return $this->operation_result( false, 'warming_exception' );
            }

            return is_array( $result ) ? $result : $this->operation_result( false, 'invalid_warming_result' );
        }

        /** @return array */
        public function get_status() {
            return [
                'available'  => $this->is_available(),
                'prepared'   => $this->regeneration,
                'capturing'  => $this->capture_started,
                'warm_ready' => $this->supports_warm(),
            ];
        }

        /** @return void */
        public function release_request() {
            $this->storage->release_regeneration();
            $this->regeneration    = false;
            $this->capture_started = false;
        }

        /**
         * @param array $cached Cache storage hit.
         * @param array $server HTTP server values.
         * @return array
         */
        private function cached_response( array $cached, array $server ) {
            $metadata = $cached['metadata'];
            $headers  = [];

            foreach ( $metadata['headers'] as $name => $value ) {
                $headers[ implode( '-', array_map( 'ucfirst', explode( '-', $name ) ) ) ] = $value;
            }

            $headers['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $metadata['created_at'] ) . ' GMT';
            $headers['Cache-Control'] = 'no-cache, must-revalidate';
            $status                   = $this->not_modified( $server, $metadata['created_at'] ) ? 304 : 200;
            $body                     = 304 === $status || 'HEAD' === $this->method ? '' : $cached['body'];

            if ( 304 !== $status ) {
                $headers['Content-Length'] = (string) $metadata['body_size'];
            }

            if ( $this->config['debug'] ) {
                $headers['X-Directorist-Cache'] = ! empty( $cached['stale'] ) ? 'STALE' : 'HIT';
            }

            return [
                'served'       => true,
                'regeneration' => false,
                'code'         => $cached['code'],
                'status'       => $status,
                'headers'      => $headers,
                'body'         => $body,
                'stale'        => ! empty( $cached['stale'] ),
            ];
        }

        /**
         * @param array $server HTTP server values.
         * @param int   $created_at Cache creation timestamp.
         * @return bool
         */
        private function not_modified( array $server, $created_at ) {
            if ( empty( $server['HTTP_IF_MODIFIED_SINCE'] ) || ! is_scalar( $server['HTTP_IF_MODIFIED_SINCE'] ) ) {
                return false;
            }

            $timestamp = strtotime( (string) $server['HTTP_IF_MODIFIED_SINCE'] );

            return false !== $timestamp && $timestamp >= $created_at;
        }

        /**
         * @param bool   $served Hit state.
         * @param bool   $regeneration Lock ownership state.
         * @param string $code Stable result code.
         * @return array
         */
        private function early_result( $served, $regeneration, $code ) {
            return [
                'served'       => (bool) $served,
                'regeneration' => (bool) $regeneration,
                'code'         => (string) $code,
                'status'       => 0,
                'headers'      => [],
                'body'         => '',
                'stale'        => false,
            ];
        }

        /**
         * @param array $config Raw configuration.
         * @return array
         */
        private function normalize_config( array $config ) {
            $cache_dir = isset( $config['cache_dir'] ) && is_string( $config['cache_dir'] ) ? $config['cache_dir'] : '';
            $ttl       = isset( $config['ttl'] ) ? (int) $config['ttl'] : 3600;
            $stale_ttl = isset( $config['stale_ttl'] ) ? (int) $config['stale_ttl'] : 30;

            return [
                'cache_dir' => $cache_dir,
                'ttl'       => min( 86400, max( 1, $ttl ) ),
                'stale_ttl' => min( 300, max( 0, $stale_ttl ) ),
                'debug'     => ! empty( $config['debug'] ),
            ];
        }

        /**
         * @param mixed $values Candidate string list.
         * @return string[]
         */
        private function string_list( $values ) {
            if ( ! is_array( $values ) ) {
                return [];
            }

            return array_values( array_unique( array_filter( array_map( 'strval', $values ) ) ) );
        }

        /**
         * @param bool   $success Operation state.
         * @param string $code Stable result code.
         * @return array
         */
        private function operation_result( $success, $code ) {
            return [
                'success'  => (bool) $success,
                'code'     => (string) $code,
                'provider' => 'directorist-cache',
            ];
        }
    }
}

namespace {
    if ( ! function_exists( 'directorist_performance_cache_engine' ) ) {
        /**
         * Return the request-scoped early cache engine.
         *
         * @param array|null $config Early engine configuration.
         * @return Directorist\Performance_Cache\Cache_Engine
         */
        function directorist_performance_cache_engine( array $config = null ) {
            static $engine;

            if ( ! $engine instanceof Directorist\Performance_Cache\Cache_Engine ) {
                $engine = new Directorist\Performance_Cache\Cache_Engine( null === $config ? [] : $config );
            }

            return $engine;
        }
    }
}

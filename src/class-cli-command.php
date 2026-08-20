<?php

namespace Directorist\Performance_Cache;

/**
 * WP-CLI operational controls for cache status, warming, purge, and verify.
 */
final class CLI_Command {
    /** @var object */
    private $controller;

    /** @var callable */
    private $site_id;

    /**
     * @param object        $controller Runtime controller.
     * @param callable|null $site_id Current site ID provider.
     */
    public function __construct( $controller, $site_id = null ) {
        $this->controller = $controller;
        $this->site_id    = is_callable( $site_id ) ? $site_id : static function () {
            return function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
        };
    }

    /**
     * @param array $args Positional arguments.
     * @param array $assoc_args Named arguments.
     * @return array
     */
    public function status( $args, $assoc_args ) {
        unset( $args, $assoc_args );
        $result = $this->controller->status();
        $this->output( $result, false );

        return $result;
    }

    /**
     * @param array $args Public URLs, or none for core discovery.
     * @param array $assoc_args Discovery limits.
     * @return array
     */
    public function warm( $args, $assoc_args ) {
        $urls = array_values( array_filter( array_map( 'strval', (array) $args ) ) );

        if ( empty( $urls ) && function_exists( 'directorist_page_cache_discover_warm_urls' ) ) {
            $urls = directorist_page_cache_discover_warm_urls(
                [
                    'listing_limit' => isset( $assoc_args['listings'] ) ? (int) $assoc_args['listings'] : 20,
                    'term_limit'    => isset( $assoc_args['terms'] ) ? (int) $assoc_args['terms'] : 30,
                    'page_limit'    => isset( $assoc_args['pages'] ) ? (int) $assoc_args['pages'] : 3,
                ]
            );
        }

        $result = empty( $urls )
            ? [ 'success' => false, 'code' => 'no_warm_urls' ]
            : $this->controller->enqueue( $urls );
        $this->output( $result, true );

        return $result;
    }

    /**
     * @param array $args Positional arguments.
     * @param array $assoc_args Named arguments.
     * @return array
     */
    public function purge( $args, $assoc_args ) {
        unset( $args, $assoc_args );
        $result = $this->controller->purge_site( (int) call_user_func( $this->site_id ) );
        $this->output( $result, true );

        return $result;
    }

    /**
     * @param array $args Positional arguments.
     * @param array $assoc_args Named arguments.
     * @return array
     */
    public function verify( $args, $assoc_args ) {
        unset( $args, $assoc_args );
        $result = $this->controller->verify();
        $this->output( $result, true );

        return $result;
    }

    /**
     * @param array $result Operation result.
     * @param bool  $announce Whether to announce success/failure.
     * @return void
     */
    private function output( array $result, $announce ) {
        if ( ! class_exists( 'WP_CLI', false ) ) {
            return;
        }

        \WP_CLI::log( json_encode( $result, JSON_UNESCAPED_SLASHES ) );

        if ( ! $announce ) {
            return;
        }

        if ( ! empty( $result['success'] ) ) {
            \WP_CLI::success( isset( $result['code'] ) ? $result['code'] : 'complete' );
        } else {
            \WP_CLI::warning( isset( $result['code'] ) ? $result['code'] : 'failed' );
        }
    }
}

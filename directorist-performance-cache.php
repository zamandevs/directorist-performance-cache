<?php
/**
 * Plugin Name: Directorist Performance Cache
 * Plugin URI: https://github.com/zamandevs/directorist-performance-cache
 * Description: Optional dependency-aware fallback page cache for Directorist.
 * Version: 0.4.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Zaman Devs
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: directorist-performance-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DIRECTORIST_PERFORMANCE_CACHE_VERSION', '0.4.0' );
define( 'DIRECTORIST_PERFORMANCE_CACHE_FILE', __FILE__ );
define( 'DIRECTORIST_PERFORMANCE_CACHE_DIR', __DIR__ );

spl_autoload_register(
    static function ( $class_name ) {
        $prefix = 'Directorist\\Performance_Cache\\';

        if ( 0 !== strpos( $class_name, $prefix ) ) {
            return;
        }

        $relative = substr( $class_name, strlen( $prefix ) );
        $filename = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
        $path     = DIRECTORIST_PERFORMANCE_CACHE_DIR . '/src/' . $filename;

        if ( is_file( $path ) ) {
            require_once $path;
        }
    }
);

require_once DIRECTORIST_PERFORMANCE_CACHE_DIR . '/src/class-cache-engine.php';

/** @return string */
function directorist_performance_cache_wp_config_path() {
    $root_path = ABSPATH . 'wp-config.php';

    if ( is_file( $root_path ) || is_link( $root_path ) ) {
        return $root_path;
    }

    return dirname( ABSPATH ) . '/wp-config.php';
}

/** @return Directorist\Performance_Cache\Lifecycle_Manager */
function directorist_performance_cache_lifecycle() {
    static $lifecycle;

    if ( ! $lifecycle instanceof Directorist\Performance_Cache\Lifecycle_Manager ) {
        $writer    = new Directorist\Performance_Cache\Atomic_Writer();
        $installer = new Directorist\Performance_Cache\Dropin_Installer( WP_CONTENT_DIR, DIRECTORIST_PERFORMANCE_CACHE_DIR, $writer );
        $wp_cache  = new Directorist\Performance_Cache\WP_Cache_Config( directorist_performance_cache_wp_config_path(), $writer );
        $lifecycle = new Directorist\Performance_Cache\Lifecycle_Manager( $installer, $wp_cache );
    }

    return $lifecycle;
}

/** @return array */
function directorist_performance_cache_bump_lifecycle_generation() {
    $storage = new Directorist\Performance_Cache\Cache_Storage( WP_CONTENT_DIR . '/cache/directorist-performance-cache' );

    return $storage->bump_generations( [ 'directorist:0:lifecycle' ] );
}

/** @return Directorist\Performance_Cache\Runtime_Controller */
function directorist_performance_cache_controller() {
    static $controller;

    if ( ! $controller instanceof Directorist\Performance_Cache\Runtime_Controller ) {
        $root       = WP_CONTENT_DIR . '/cache/directorist-performance-cache';
        $engine     = directorist_performance_cache_engine();
        $queue      = new Directorist\Performance_Cache\Warm_Queue( $root, home_url( '/' ) );
        $cleaner    = new Directorist\Performance_Cache\Cache_Cleaner( $root );
        $controller = new Directorist\Performance_Cache\Runtime_Controller( $engine, $queue, $cleaner );
    }

    return $controller;
}

/** @return Directorist\Performance_Cache\Early_Config_Manager */
function directorist_performance_cache_config_manager() {
    static $config;

    if ( ! $config instanceof Directorist\Performance_Cache\Early_Config_Manager ) {
        $config = new Directorist\Performance_Cache\Early_Config_Manager( WP_CONTENT_DIR . '/cache/directorist-performance-cache/config.json' );
    }

    return $config;
}

/** @return Directorist\Performance_Cache\Performance_Integration */
function directorist_performance_cache_performance_integration() {
    static $integration;

    if ( ! $integration instanceof Directorist\Performance_Cache\Performance_Integration ) {
        $root        = WP_CONTENT_DIR . '/cache/directorist-performance-cache';
        $integration = new Directorist\Performance_Cache\Performance_Integration(
            directorist_performance_cache_controller(),
            static function () {
                return directorist_performance_cache_lifecycle();
            },
            static function () {
                return directorist_performance_cache_config_manager();
            },
            static function () use ( $root ) {
                return new Directorist\Performance_Cache\Cache_Inventory( $root );
            },
            'directorist_performance_cache_bump_lifecycle_generation'
        );
    }

    return $integration;
}

/**
 * @param bool $network_wide Whether activation is network-wide.
 * @return void
 */
function directorist_performance_cache_activate( $network_wide = false ) {
    $result = directorist_performance_cache_lifecycle()->activate( is_multisite(), (bool) $network_wide );

    if ( ! empty( $result['success'] ) ) {
        $generation = directorist_performance_cache_bump_lifecycle_generation();

        if ( empty( $generation['success'] ) ) {
            directorist_performance_cache_lifecycle()->deactivate();
            $result = [
                'success' => false,
                'code'    => 'lifecycle_generation_failed',
            ];
        } else {
            $result['lifecycle_generation'] = $generation['code'];
            $schedules = directorist_performance_cache_controller()->activate();
            $result['schedules'] = $schedules['code'];
        }
    }

    update_site_option( 'directorist_performance_cache_status', $result );

    if ( ! empty( $result['success'] ) ) {
        return;
    }

    if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    deactivate_plugins( plugin_basename( __FILE__ ), true, $network_wide );
    wp_die( esc_html( 'Directorist Performance Cache activation refused: ' . $result['code'] ) );
}

/** @return void */
function directorist_performance_cache_deactivate() {
    $schedules = directorist_performance_cache_controller()->deactivate();
    $generation = directorist_performance_cache_bump_lifecycle_generation();
    $result = directorist_performance_cache_lifecycle()->deactivate();
    $result['lifecycle_generation'] = $generation['code'];
    $result['schedules'] = $schedules['code'];
    update_site_option( 'directorist_performance_cache_status', $result );
}

/** @return void */
function directorist_performance_cache_uninstall() {
    directorist_performance_cache_controller()->deactivate();
    directorist_performance_cache_bump_lifecycle_generation();
    $result = directorist_performance_cache_lifecycle()->uninstall();

    if ( ! empty( $result['success'] ) ) {
        delete_site_option( 'directorist_performance_cache_status' );
    } else {
        update_site_option( 'directorist_performance_cache_status', $result );
    }
}

/**
 * @param array $providers Directorist provider registrations.
 * @return array
 */
function directorist_performance_cache_register_provider( $providers ) {
    static $bridge;

    if ( ! is_array( $providers ) ) {
        $providers = [];
    }

    if ( ! $bridge instanceof Directorist\Performance_Cache\Core_Bridge ) {
        $bridge = new Directorist\Performance_Cache\Core_Bridge();
    }

    return $bridge->register( $providers );
}

add_filter( 'directorist_page_cache_providers', 'directorist_performance_cache_register_provider' );
directorist_performance_cache_engine(
    [
        'cache_dir' => WP_CONTENT_DIR . '/cache/directorist-performance-cache',
        'ttl'       => 3600,
        'stale_ttl' => 30,
        'debug'     => false,
    ]
)->register_wordpress_hooks();
directorist_performance_cache_controller()->register_wordpress_hooks();
directorist_performance_cache_performance_integration()->register_wordpress_hooks();
register_activation_hook( __FILE__, 'directorist_performance_cache_activate' );
register_deactivation_hook( __FILE__, 'directorist_performance_cache_deactivate' );
register_uninstall_hook( __FILE__, 'directorist_performance_cache_uninstall' );

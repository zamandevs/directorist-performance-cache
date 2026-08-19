<?php
// DIRECTORIST PAGE CACHE DROPIN
// Owner-ID: directorist-performance-cache

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
    return;
}

$directorist_performance_cache_config_file = WP_CONTENT_DIR . '/cache/directorist-performance-cache/config.json';

if ( is_link( $directorist_performance_cache_config_file ) || ! is_file( $directorist_performance_cache_config_file ) || ! is_readable( $directorist_performance_cache_config_file ) ) {
    return;
}

$directorist_performance_cache_config_size = filesize( $directorist_performance_cache_config_file );

if ( false === $directorist_performance_cache_config_size || 32768 < $directorist_performance_cache_config_size ) {
    return;
}

$directorist_performance_cache_config_source = file_get_contents( $directorist_performance_cache_config_file );

if ( false === $directorist_performance_cache_config_source ) {
    return;
}

$directorist_performance_cache_config = json_decode( $directorist_performance_cache_config_source, true );

if (
    ! is_array( $directorist_performance_cache_config )
    || ! isset( $directorist_performance_cache_config['_marker'], $directorist_performance_cache_config['owner_id'], $directorist_performance_cache_config['schema'], $directorist_performance_cache_config['owner'], $directorist_performance_cache_config['bootstrap_file'] )
    || 'DIRECTORIST PAGE CACHE CONFIG' !== $directorist_performance_cache_config['_marker']
    || 'Owner-ID: directorist-performance-cache' !== $directorist_performance_cache_config['owner_id']
    || 1 !== $directorist_performance_cache_config['schema']
    || 'directorist-performance-cache' !== $directorist_performance_cache_config['owner']
    || ! is_string( $directorist_performance_cache_config['bootstrap_file'] )
    || '' === $directorist_performance_cache_config['bootstrap_file']
) {
    return;
}

$directorist_performance_cache_bootstrap = $directorist_performance_cache_config['bootstrap_file'];

if ( is_link( $directorist_performance_cache_bootstrap ) || ! is_file( $directorist_performance_cache_bootstrap ) || ! is_readable( $directorist_performance_cache_bootstrap ) ) {
    return;
}

require_once $directorist_performance_cache_bootstrap;

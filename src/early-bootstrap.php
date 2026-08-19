<?php
/**
 * Fail-open bridge to the optional early cache engine.
 */

if ( ! isset( $directorist_performance_cache_config ) || ! is_array( $directorist_performance_cache_config ) ) {
    return false;
}

$directorist_performance_cache_engine_file = isset( $directorist_performance_cache_config['engine_file'] )
    ? $directorist_performance_cache_config['engine_file']
    : '';

if (
    ! is_string( $directorist_performance_cache_engine_file )
    || '' === $directorist_performance_cache_engine_file
    || is_link( $directorist_performance_cache_engine_file )
    || ! is_file( $directorist_performance_cache_engine_file )
    || ! is_readable( $directorist_performance_cache_engine_file )
) {
    return false;
}

require_once $directorist_performance_cache_engine_file;

return true;

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

try {
    require_once $directorist_performance_cache_engine_file;

    if ( ! function_exists( 'directorist_performance_cache_engine' ) ) {
        return false;
    }

    $directorist_performance_cache_engine = directorist_performance_cache_engine( $directorist_performance_cache_config );

    if ( ! is_object( $directorist_performance_cache_engine ) || ! is_callable( [ $directorist_performance_cache_engine, 'boot_early' ] ) ) {
        return false;
    }

    $directorist_performance_cache_early_result = $directorist_performance_cache_engine->boot_early( $_SERVER, $_COOKIE );

    if ( ! empty( $directorist_performance_cache_early_result['served'] ) ) {
        $directorist_performance_cache_engine->send_response( $directorist_performance_cache_early_result );
        exit;
    }
} catch ( \Throwable $directorist_performance_cache_exception ) {
    unset( $directorist_performance_cache_exception );

    return false;
}

return true;

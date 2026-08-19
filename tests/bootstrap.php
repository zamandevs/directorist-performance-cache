<?php
/**
 * Standalone unit-test bootstrap.
 */

define( 'DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR', dirname( __DIR__ ) );

$autoload = DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR . '/vendor/autoload.php';

if ( ! is_file( $autoload ) ) {
    fwrite( STDERR, "Composer dependencies are missing. Run composer install.\n" );
    exit( 1 );
}

require_once $autoload;

spl_autoload_register(
    static function ( $class_name ) {
        $prefix = 'Directorist\\Performance_Cache\\';

        if ( 0 !== strpos( $class_name, $prefix ) ) {
            return;
        }

        $relative = substr( $class_name, strlen( $prefix ) );
        $filename = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
        $path     = DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR . '/src/' . $filename;

        if ( is_file( $path ) ) {
            require_once $path;
        }
    }
);

<?php

namespace Directorist\Performance_Cache;

/**
 * Complete same-directory temporary write followed by atomic rename.
 */
final class Atomic_File_Writer {
    /**
     * @param string $path Target path.
     * @param string $content Complete content.
     * @return bool
     */
    public function write( $path, $content ) {
        if ( ! is_string( $path ) || ! is_string( $content ) || is_link( $path ) ) {
            return false;
        }

        $directory = dirname( $path );

        if ( ! $this->prepare_directory( $directory ) ) {
            return false;
        }

        $temporary = tempnam( $directory, '.tmp-' );

        if ( false === $temporary ) {
            return false;
        }

        $handle = @fopen( $temporary, 'wb' );

        if ( false === $handle ) {
            @unlink( $temporary );

            return false;
        }

        $length = strlen( $content );
        $offset = 0;
        $valid  = true;

        while ( $offset < $length ) {
            $written = fwrite( $handle, substr( $content, $offset ) );

            if ( false === $written || 0 === $written ) {
                $valid = false;
                break;
            }

            $offset += $written;
        }

        $valid = $valid && fflush( $handle );

        if ( function_exists( 'fsync' ) ) {
            $valid = fsync( $handle ) && $valid;
        }

        fclose( $handle );
        @chmod( $temporary, 0644 );

        if ( ! $valid || $offset !== $length || ! @rename( $temporary, $path ) ) {
            @unlink( $temporary );

            return false;
        }

        return true;
    }

    /**
     * @param string $directory Directory path.
     * @return bool
     */
    public function prepare_directory( $directory ) {
        if ( is_link( $directory ) ) {
            return false;
        }

        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
            return false;
        }

        return is_writable( $directory );
    }
}

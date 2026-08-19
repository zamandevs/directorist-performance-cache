<?php

namespace Directorist\Performance_Cache;

/**
 * Exact ownership fingerprints for files managed by the companion.
 */
final class Ownership {
    const DROPIN_MARKER = 'DIRECTORIST PAGE CACHE DROPIN';
    const CONFIG_MARKER = 'DIRECTORIST PAGE CACHE CONFIG';
    const OWNER_LINE    = 'Owner-ID: directorist-performance-cache';

    /**
     * @param string $path Drop-in path.
     * @return string
     */
    public static function classify_dropin( $path ) {
        if ( is_link( $path ) ) {
            return 'foreign_symlink';
        }

        if ( ! file_exists( $path ) ) {
            return 'missing';
        }

        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return 'foreign_unreadable';
        }

        $content = self::read_prefix( $path );

        return self::owns_dropin_content( $content ) ? 'owned' : 'foreign';
    }

    /**
     * @param string $content File content.
     * @return bool
     */
    public static function owns_dropin_content( $content ) {
        return false !== strpos( (string) $content, self::DROPIN_MARKER )
            && false !== strpos( (string) $content, self::OWNER_LINE );
    }

    /**
     * @param string $path Config path.
     * @return bool
     */
    public static function owns_config( $path ) {
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
            return false;
        }

        $content = self::read_prefix( $path );

        return false !== strpos( $content, self::CONFIG_MARKER )
            && false !== strpos( $content, self::OWNER_LINE );
    }

    /**
     * @param string $path File path.
     * @return string
     */
    private static function read_prefix( $path ) {
        $handle = @fopen( $path, 'rb' );

        if ( false === $handle ) {
            return '';
        }

        $content = fread( $handle, 8192 );
        fclose( $handle );

        return false === $content ? '' : $content;
    }
}

<?php

namespace Directorist\Performance_Cache;

/**
 * Same-directory temporary write followed by atomic replacement.
 */
class Atomic_Writer {
    /**
     * @param string $path Target path.
     * @param string $content Complete file content.
     * @return array
     */
    public function write( $path, $content ) {
        $directory = dirname( $path );
        $prepared  = $this->prepare_directory( $directory );

        if ( empty( $prepared['success'] ) ) {
            return $prepared;
        }

        if ( is_link( $path ) ) {
            return $this->result( false, 'foreign_symlink', $path );
        }

        $permissions = is_file( $path ) ? @fileperms( $path ) : false;
        $permissions = false === $permissions ? 0644 : $permissions & 0777;

        $temporary = tempnam( $directory, '.directorist-cache-' );

        if ( false === $temporary ) {
            return $this->result( false, 'temp_file_failed', $path );
        }

        $handle = @fopen( $temporary, 'wb' );

        if ( false === $handle ) {
            @unlink( $temporary );

            return $this->result( false, 'write_failed', $path );
        }

        $expected = strlen( $content );
        $written  = fwrite( $handle, $content );
        $flushed  = fflush( $handle );

        if ( function_exists( 'fsync' ) ) {
            $flushed = fsync( $handle ) && $flushed;
        }

        fclose( $handle );

        if ( $expected !== $written || ! $flushed ) {
            @unlink( $temporary );

            return $this->result( false, 'write_failed', $path );
        }

        @chmod( $temporary, $permissions );

        if ( ! @rename( $temporary, $path ) ) {
            @unlink( $temporary );

            return $this->result( false, 'replace_failed', $path );
        }

        return $this->result( true, 'written', $path );
    }

    /**
     * @param string $path Owned file path.
     * @return array
     */
    public function remove( $path ) {
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return $this->result( true, 'missing', $path );
        }

        if ( is_link( $path ) || ! @unlink( $path ) ) {
            return $this->result( false, 'remove_failed', $path );
        }

        return $this->result( true, 'removed', $path );
    }

    /**
     * @param string $directory Directory path.
     * @return array
     */
    private function prepare_directory( $directory ) {
        if ( is_link( $directory ) ) {
            return $this->result( false, 'foreign_symlink', $directory );
        }

        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
            return $this->result( false, 'directory_create_failed', $directory );
        }

        if ( ! is_writable( $directory ) ) {
            return $this->result( false, 'directory_not_writable', $directory );
        }

        return $this->result( true, 'directory_ready', $directory );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @param string $path Affected path.
     * @return array
     */
    private function result( $success, $code, $path ) {
        return [
            'success' => (bool) $success,
            'code'    => $code,
            'path'    => $path,
        ];
    }
}

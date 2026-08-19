<?php

namespace Directorist\Performance_Cache;

/**
 * Small atomic generation counters keyed by Directorist dependencies.
 */
final class Generation_Store {
    /** @var Cache_Paths */
    private $paths;

    /** @var Atomic_File_Writer */
    private $writer;

    /**
     * @param Cache_Paths             $paths Contained paths.
     * @param Atomic_File_Writer|null $writer Atomic writer.
     */
    public function __construct( Cache_Paths $paths, Atomic_File_Writer $writer = null ) {
        $this->paths  = $paths;
        $this->writer = $writer ?: new Atomic_File_Writer();
    }

    /**
     * @param string[] $dependencies Dependency keys.
     * @return array<string,int>|false
     */
    public function snapshot( array $dependencies ) {
        $snapshot = [];

        foreach ( array_values( array_unique( $dependencies ) ) as $dependency ) {
            $path = $this->paths->generation( $dependency );

            if ( '' === $path ) {
                return false;
            }

            $generation = $this->read( $path );

            if ( false === $generation ) {
                return false;
            }

            $snapshot[ $dependency ] = $generation;
        }

        ksort( $snapshot, SORT_STRING );

        return $snapshot;
    }

    /**
     * @param string[] $dependencies Dependency keys.
     * @return bool
     */
    public function bump( array $dependencies ) {
        foreach ( array_values( array_unique( $dependencies ) ) as $dependency ) {
            $path = $this->paths->generation( $dependency );

            if ( '' === $path || ! $this->writer->prepare_directory( dirname( $path ) ) ) {
                return false;
            }

            $lock = @fopen( $path . '.lock', 'c+' );

            if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
                if ( is_resource( $lock ) ) {
                    fclose( $lock );
                }

                return false;
            }

            $generation = $this->read( $path );
            $written    = false !== $generation && $this->writer->write( $path, (string) ( $generation + 1 ) );
            flock( $lock, LOCK_UN );
            fclose( $lock );

            if ( ! $written ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $path Generation path.
     * @return int|false
     */
    private function read( $path ) {
        if ( is_link( $path ) ) {
            return false;
        }

        if ( ! file_exists( $path ) ) {
            return 0;
        }

        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return false;
        }

        $value = file_get_contents( $path );

        return is_string( $value ) && preg_match( '/^[0-9]{1,20}$/', $value ) ? (int) $value : false;
    }
}

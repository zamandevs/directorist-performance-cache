<?php

namespace Directorist\Performance_Cache;

/**
 * Bounded cursor-based cleanup for expired and invalidated cache entries.
 */
final class Cache_Cleaner {
    const STATE_SCHEMA       = 1;
    const MAX_FILES          = 500;
    const MAX_DIRECTORIES    = 128;
    const MAX_METADATA_BYTES = 1048576;

    /** @var string */
    private $root;

    /** @var string */
    private $state_path;

    /** @var string */
    private $lock_path;

    /** @var callable */
    private $clock;

    /** @var Atomic_File_Writer */
    private $writer;

    /** @var Cache_Storage */
    private $storage;

    /** @var Request_Key */
    private $request_key;

    /** @var Cache_Paths */
    private $paths;

    /**
     * @param string        $root Cache root.
     * @param callable|null $clock Unix timestamp provider.
     */
    public function __construct( $root, $clock = null ) {
        $this->root        = is_string( $root ) ? rtrim( $root, '/\\' ) : '';
        $this->state_path  = $this->root . '/operations/cleanup-state.json';
        $this->lock_path   = $this->root . '/operations/cleanup.lock';
        $this->clock       = is_callable( $clock ) ? $clock : 'time';
        $this->writer      = new Atomic_File_Writer();
        $this->storage     = new Cache_Storage( $this->root, $this->clock );
        $this->request_key = new Request_Key();
        $this->paths       = new Cache_Paths( $this->root );
    }

    /**
     * @param int $limit Maximum metadata files examined.
     * @return array
     */
    public function run( $limit = 100 ) {
        $limit = min( self::MAX_FILES, max( 1, (int) $limit ) );
        $lock  = $this->acquire_lock();

        if ( false === $lock ) {
            return $this->result( false, 'cleanup_locked' );
        }

        $cursor      = $this->read_cursor();
        $examined    = 0;
        $removed     = 0;
        $errors      = 0;
        $directories = 0;
        $complete    = true;
        $last        = $cursor;
        $first_dirs  = $this->hex_directories( $this->root . '/pages' );

        foreach ( $first_dirs as $first ) {
            if ( '' !== $cursor['first'] && strcmp( $first, $cursor['first'] ) < 0 ) {
                continue;
            }

            $second_dirs = $this->hex_directories( $this->root . '/pages/' . $first );

            foreach ( $second_dirs as $second ) {
                if ( $first === $cursor['first'] && '' !== $cursor['second'] && strcmp( $second, $cursor['second'] ) < 0 ) {
                    continue;
                }

                ++$directories;
                $files = $this->metadata_files( $this->root . '/pages/' . $first . '/' . $second );

                foreach ( $files as $file ) {
                    if ( $first === $cursor['first'] && $second === $cursor['second'] && '' !== $cursor['file'] && strcmp( $file, $cursor['file'] ) <= 0 ) {
                        continue;
                    }

                    $last = [ 'first' => $first, 'second' => $second, 'file' => $file ];
                    ++$examined;
                    $outcome = $this->inspect( $this->root . '/pages/' . $first . '/' . $second . '/' . $file );

                    if ( 'removed' === $outcome ) {
                        ++$removed;
                    } elseif ( 'error' === $outcome ) {
                        ++$errors;
                    }

                    if ( $limit <= $examined ) {
                        $complete = false;
                        break 3;
                    }
                }

                $last = [ 'first' => $first, 'second' => $second, 'file' => '~' ];

                if ( self::MAX_DIRECTORIES <= $directories ) {
                    $complete = false;
                    break 2;
                }
            }
        }

        if ( $complete ) {
            $last = [ 'first' => '', 'second' => '', 'file' => '' ];
        }

        $state_written = $this->write_cursor( $last );
        $this->release_lock( $lock );

        return array_merge(
            $this->result( $state_written, $state_written ? 'cleaned' : 'cleanup_state_failed' ),
            [
                'examined'    => $examined,
                'removed'     => $removed,
                'errors'      => $errors,
                'directories' => $directories,
                'complete'    => $complete,
                'cursor'      => $last,
            ]
        );
    }

    /**
     * @param string $metadata_path Metadata path.
     * @return string kept, removed, or error.
     */
    private function inspect( $metadata_path ) {
        if ( is_link( $metadata_path ) || ! is_file( $metadata_path ) || ! is_readable( $metadata_path ) ) {
            return 'error';
        }

        $size = filesize( $metadata_path );

        if ( false === $size || self::MAX_METADATA_BYTES < $size ) {
            return $this->remove_hash( $this->hash_from_path( $metadata_path ) );
        }

        $source   = file_get_contents( $metadata_path );
        $metadata = is_string( $source ) ? json_decode( $source, true ) : null;
        $hash     = $this->hash_from_path( $metadata_path );

        if ( ! is_array( $metadata ) || 'directorist-performance-cache' !== ( isset( $metadata['owner'] ) ? $metadata['owner'] : '' ) || $hash !== ( isset( $metadata['request_hash'] ) ? $metadata['request_hash'] : '' ) || empty( $metadata['canonical_url'] ) ) {
            return $this->remove_hash( $hash );
        }

        $key = $this->request_key->from_url( $metadata['canonical_url'] );

        if ( empty( $key['success'] ) || $hash !== $key['hash'] ) {
            return $this->remove_hash( $hash );
        }

        $loaded = $this->storage->load( $key );

        if ( ! empty( $loaded['hit'] ) || in_array( $loaded['code'], [ 'expired' ], true ) ) {
            return 'kept';
        }

        if ( in_array( $loaded['code'], [ 'generation_mismatch', 'stale_expired', 'invalid_metadata', 'body_mismatch', 'miss' ], true ) ) {
            return $this->remove_hash( $hash );
        }

        return 'kept';
    }

    /**
     * @param string $hash Entry hash.
     * @return string removed, kept, or error.
     */
    private function remove_hash( $hash ) {
        $paths = $this->paths->entry( $hash );

        if ( empty( $paths ) || is_link( $paths['lock'] ) || ! $this->writer->prepare_directory( dirname( $paths['lock'] ) ) ) {
            return 'error';
        }

        $lock = @fopen( $paths['lock'], 'c+' );

        if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $lock ) ) {
                fclose( $lock );
            }

            return 'kept';
        }

        $removed = $this->remove_regular_file( $paths['metadata'] ) && $this->remove_regular_file( $paths['body'] );
        flock( $lock, LOCK_UN );
        fclose( $lock );

        return $removed ? 'removed' : 'error';
    }

    /**
     * @param string $path File path.
     * @return bool
     */
    private function remove_regular_file( $path ) {
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return true;
        }

        return ! is_link( $path ) && is_file( $path ) && @unlink( $path );
    }

    /**
     * @param string $path Metadata path.
     * @return string
     */
    private function hash_from_path( $path ) {
        $name = basename( $path, '.json' );

        return preg_match( '/^[a-f0-9]{64}$/', $name ) ? $name : '';
    }

    /**
     * @param string $path Parent directory.
     * @return string[]
     */
    private function hex_directories( $path ) {
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            return [];
        }

        $directories = [];

        foreach ( scandir( $path ) as $name ) {
            $candidate = $path . '/' . $name;

            if ( preg_match( '/^[a-f0-9]{2}$/', $name ) && is_dir( $candidate ) && ! is_link( $candidate ) ) {
                $directories[] = $name;
            }
        }

        sort( $directories, SORT_STRING );

        return $directories;
    }

    /**
     * @param string $path Entry directory.
     * @return string[]
     */
    private function metadata_files( $path ) {
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            return [];
        }

        $files = [];

        foreach ( scandir( $path ) as $name ) {
            if ( preg_match( '/^[a-f0-9]{64}\.json$/', $name ) ) {
                $files[] = $name;
            }
        }

        sort( $files, SORT_STRING );

        return $files;
    }

    /** @return array */
    private function read_cursor() {
        $default = [ 'first' => '', 'second' => '', 'file' => '' ];

        if ( ! file_exists( $this->state_path ) ) {
            return $default;
        }

        if ( is_link( $this->state_path ) || ! is_file( $this->state_path ) || ! is_readable( $this->state_path ) || 4096 < filesize( $this->state_path ) ) {
            return $default;
        }

        $source = file_get_contents( $this->state_path );
        $state  = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! is_array( $state ) || self::STATE_SCHEMA !== ( isset( $state['schema'] ) ? $state['schema'] : null ) || ! isset( $state['cursor'] ) || ! is_array( $state['cursor'] ) ) {
            return $default;
        }

        return array_merge( $default, array_intersect_key( $state['cursor'], $default ) );
    }

    /**
     * @param array $cursor Cleanup cursor.
     * @return bool
     */
    private function write_cursor( array $cursor ) {
        $encoded = json_encode(
            [
                'schema'     => self::STATE_SCHEMA,
                'cursor'     => $cursor,
                'updated_at' => (int) call_user_func( $this->clock ),
            ],
            JSON_UNESCAPED_SLASHES
        );

        return is_string( $encoded ) && $this->writer->write( $this->state_path, $encoded );
    }

    /** @return resource|false */
    private function acquire_lock() {
        if ( '' === $this->root || ! $this->writer->prepare_directory( dirname( $this->lock_path ) ) || is_link( $this->lock_path ) ) {
            return false;
        }

        $lock = @fopen( $this->lock_path, 'c+' );

        if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $lock ) ) {
                fclose( $lock );
            }

            return false;
        }

        return $lock;
    }

    /**
     * @param resource $lock Cleanup lock.
     * @return void
     */
    private function release_lock( $lock ) {
        flock( $lock, LOCK_UN );
        fclose( $lock );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable result code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success' => (bool) $success,
            'code'    => (string) $code,
        ];
    }
}

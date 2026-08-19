<?php

namespace Directorist\Performance_Cache;

/**
 * Integrity-checked response and generation storage.
 */
final class Cache_Storage {
    const METADATA_SCHEMA = 1;
    const MAX_BODY_BYTES  = 10485760;
    const MAX_META_BYTES  = 1048576;

    /** @var Cache_Paths */
    private $paths;

    /** @var Atomic_File_Writer */
    private $writer;

    /** @var Generation_Store */
    private $generations;

    /** @var callable */
    private $clock;

    /** @var resource|null */
    private $regeneration_lock;

    /** @var string */
    private $regeneration_hash = '';

    /**
     * @param string        $root Cache root.
     * @param callable|null $clock Unix timestamp provider.
     */
    public function __construct( $root, $clock = null ) {
        $this->paths       = new Cache_Paths( $root );
        $this->writer      = new Atomic_File_Writer();
        $this->generations = new Generation_Store( $this->paths, $this->writer );
        $this->clock       = is_callable( $clock ) ? $clock : 'time';
    }

    /** @return bool */
    public function is_available() {
        $root = $this->paths->root();

        return '' !== $root && $this->writer->prepare_directory( $root );
    }

    /**
     * @param array  $key Canonical request key.
     * @param string $body Complete HTML body.
     * @param array  $descriptor Core response descriptor.
     * @param array  $headers Safe headers.
     * @param int    $ttl Fresh lifetime.
     * @param int    $stale_ttl Bounded stale lifetime.
     * @return array
     */
    public function store( array $key, $body, array $descriptor, array $headers, $ttl, $stale_ttl ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || ! is_string( $body ) || '' === $body || self::MAX_BODY_BYTES < strlen( $body ) ) {
            return $this->result( false, 'invalid_entry', $paths );
        }

        $dependencies = isset( $descriptor['dependencies'] ) && is_array( $descriptor['dependencies'] ) ? array_values( array_unique( $descriptor['dependencies'] ) ) : [];
        $generations  = $this->generations->snapshot( $dependencies );

        if ( false === $generations ) {
            return $this->result( false, 'invalid_dependencies', $paths );
        }

        $owned_lock = $this->regeneration_hash === $key['hash'] && is_resource( $this->regeneration_lock );
        $lock       = $owned_lock ? $this->regeneration_lock : $this->acquire_lock( $paths['lock'], true );

        if ( false === $lock ) {
            return $this->result( false, 'lock_contended', $paths );
        }

        $now      = $this->now();
        $metadata = [
            'schema'         => self::METADATA_SCHEMA,
            'owner'          => 'directorist-performance-cache',
            'request_hash'   => $key['hash'],
            'canonical_url'  => $key['canonical_url'],
            'route_cache_key'=> isset( $descriptor['cache_key'] ) ? (string) $descriptor['cache_key'] : '',
            'site_id'        => isset( $descriptor['site_id'] ) ? (int) $descriptor['site_id'] : 0,
            'route_type'     => isset( $descriptor['route_type'] ) ? (string) $descriptor['route_type'] : '',
            'created_at'     => $now,
            'expires_at'     => $now + max( 1, (int) $ttl ),
            'stale_until'    => $now + max( 1, (int) $ttl ) + max( 0, (int) $stale_ttl ),
            'body_size'      => strlen( $body ),
            'body_hash'      => hash( 'sha256', $body ),
            'headers'        => $headers,
            'generations'    => $generations,
        ];
        $encoded  = json_encode( $metadata, JSON_UNESCAPED_SLASHES );
        $stored   = is_string( $encoded )
            && $this->writer->write( $paths['body'], $body )
            && $this->writer->write( $paths['metadata'], $encoded );

        if ( ! $owned_lock ) {
            $this->release_lock( $lock );
        }

        return $this->result( $stored, $stored ? 'stored' : 'write_failed', $paths );
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    public function load( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || ! $this->readable_regular_file( $paths['metadata'] ) || ! $this->readable_regular_file( $paths['body'] ) ) {
            return $this->miss( 'miss' );
        }

        $metadata_size = filesize( $paths['metadata'] );

        if ( false === $metadata_size || self::MAX_META_BYTES < $metadata_size ) {
            return $this->miss( 'invalid_metadata' );
        }

        $source   = file_get_contents( $paths['metadata'] );
        $metadata = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! $this->valid_metadata( $metadata, $key ) ) {
            return $this->miss( 'invalid_metadata' );
        }

        $snapshot = $this->generations->snapshot( array_keys( $metadata['generations'] ) );

        if ( false === $snapshot || $snapshot !== $metadata['generations'] ) {
            return $this->miss( 'generation_mismatch' );
        }

        $now   = $this->now();
        $stale = false;
        $code  = 'hit';

        if ( $now > $metadata['stale_until'] ) {
            return $this->miss( 'stale_expired' );
        }

        if ( $now > $metadata['expires_at'] ) {
            if ( ! $this->is_locked( $paths['lock'] ) ) {
                return $this->miss( 'expired' );
            }

            $stale = true;
            $code  = 'stale_while_regenerating';
        }

        $body_size = filesize( $paths['body'] );

        if ( false === $body_size || $body_size !== $metadata['body_size'] || self::MAX_BODY_BYTES < $body_size ) {
            return $this->miss( 'body_mismatch' );
        }

        $body = file_get_contents( $paths['body'] );

        if ( ! is_string( $body ) || hash( 'sha256', $body ) !== $metadata['body_hash'] ) {
            return $this->miss( 'body_mismatch' );
        }

        return [
            'hit'      => true,
            'stale'    => $stale,
            'code'     => $code,
            'body'     => $body,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    public function begin_regeneration( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) || is_resource( $this->regeneration_lock ) ) {
            return $this->result( false, 'lock_unavailable', $paths );
        }

        $lock = $this->acquire_lock( $paths['lock'], true );

        if ( false === $lock ) {
            return $this->result( false, 'lock_contended', $paths );
        }

        $this->regeneration_lock = $lock;
        $this->regeneration_hash = $key['hash'];

        return $this->result( true, 'lock_acquired', $paths );
    }

    /** @return void */
    public function release_regeneration() {
        if ( is_resource( $this->regeneration_lock ) ) {
            $this->release_lock( $this->regeneration_lock );
        }

        $this->regeneration_lock = null;
        $this->regeneration_hash = '';
    }

    /**
     * @param string[] $dependencies Dependency and generation keys.
     * @return array
     */
    public function bump_generations( array $dependencies ) {
        $dependencies = array_values( array_unique( array_filter( array_map( 'strval', $dependencies ) ) ) );

        if ( empty( $dependencies ) ) {
            return $this->result( true, 'no_generations' );
        }

        $bumped = $this->generations->bump( $dependencies );

        return $this->result( $bumped, $bumped ? 'generations_bumped' : 'generation_write_failed' );
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    public function purge( array $key ) {
        $paths = $this->entry_paths( $key );

        if ( empty( $paths ) ) {
            return $this->result( false, 'invalid_key' );
        }

        $lock = $this->acquire_lock( $paths['lock'], false );

        if ( false === $lock ) {
            return $this->result( false, 'lock_failed', $paths );
        }

        $success = $this->remove_regular_file( $paths['metadata'] ) && $this->remove_regular_file( $paths['body'] );
        $this->release_lock( $lock );

        return $this->result( $success, $success ? 'purged' : 'purge_failed', $paths );
    }

    /**
     * @param array $key Canonical request key.
     * @return array
     */
    private function entry_paths( array $key ) {
        return ! empty( $key['success'] ) && ! empty( $key['hash'] ) ? $this->paths->entry( $key['hash'] ) : [];
    }

    /**
     * @param mixed $metadata Decoded metadata.
     * @param array $key Canonical request key.
     * @return bool
     */
    private function valid_metadata( $metadata, array $key ) {
        return is_array( $metadata )
            && isset( $metadata['schema'], $metadata['owner'], $metadata['request_hash'], $metadata['canonical_url'], $metadata['created_at'], $metadata['expires_at'], $metadata['stale_until'], $metadata['body_size'], $metadata['body_hash'], $metadata['headers'], $metadata['generations'] )
            && self::METADATA_SCHEMA === $metadata['schema']
            && 'directorist-performance-cache' === $metadata['owner']
            && $key['hash'] === $metadata['request_hash']
            && $key['canonical_url'] === $metadata['canonical_url']
            && is_int( $metadata['created_at'] )
            && is_int( $metadata['expires_at'] )
            && is_int( $metadata['stale_until'] )
            && is_int( $metadata['body_size'] )
            && is_string( $metadata['body_hash'] )
            && preg_match( '/^[a-f0-9]{64}$/', $metadata['body_hash'] )
            && 0 < $metadata['body_size']
            && self::MAX_BODY_BYTES >= $metadata['body_size']
            && $metadata['expires_at'] >= $metadata['created_at']
            && $metadata['stale_until'] >= $metadata['expires_at']
            && $this->valid_headers( $metadata['headers'] )
            && is_array( $metadata['generations'] );
    }

    /**
     * @param mixed $headers Persisted replay headers.
     * @return bool
     */
    private function valid_headers( $headers ) {
        if ( ! is_array( $headers ) || empty( $headers['content-type'] ) ) {
            return false;
        }

        foreach ( $headers as $name => $value ) {
            if ( ! in_array( $name, [ 'content-type', 'content-language' ], true ) || ! is_string( $value ) || '' === $value || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
                return false;
            }
        }

        $content_type = strtolower( $headers['content-type'] );

        return 0 === strpos( $content_type, 'text/html' ) || 0 === strpos( $content_type, 'application/xhtml+xml' );
    }

    /**
     * @param string $path Lock path.
     * @param bool   $nonblocking Whether lock acquisition is nonblocking.
     * @return resource|false
     */
    private function acquire_lock( $path, $nonblocking ) {
        if ( ! $this->writer->prepare_directory( dirname( $path ) ) || is_link( $path ) ) {
            return false;
        }

        $handle = @fopen( $path, 'c+' );
        $mode   = LOCK_EX | ( $nonblocking ? LOCK_NB : 0 );

        if ( false === $handle || ! flock( $handle, $mode ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }

            return false;
        }

        return $handle;
    }

    /**
     * @param resource $lock Lock handle.
     * @return void
     */
    private function release_lock( $lock ) {
        flock( $lock, LOCK_UN );
        fclose( $lock );
    }

    /**
     * @param string $path Lock path.
     * @return bool
     */
    private function is_locked( $path ) {
        $lock = $this->acquire_lock( $path, true );

        if ( false === $lock ) {
            return true;
        }

        $this->release_lock( $lock );

        return false;
    }

    /**
     * @param string $path File path.
     * @return bool
     */
    private function readable_regular_file( $path ) {
        return ! is_link( $path ) && is_file( $path ) && is_readable( $path );
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

    /** @return int */
    private function now() {
        return (int) call_user_func( $this->clock );
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @param array  $paths Entry paths.
     * @return array
     */
    private function result( $success, $code, array $paths = [] ) {
        return [
            'success' => (bool) $success,
            'code'    => $code,
            'paths'   => $paths,
        ];
    }

    /**
     * @param string $code Miss code.
     * @return array
     */
    private function miss( $code ) {
        return [
            'hit'      => false,
            'stale'    => false,
            'code'     => $code,
            'body'     => '',
            'metadata' => [],
        ];
    }
}

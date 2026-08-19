<?php
/**
 * Atomic cache storage, expiry, generation, and locking behavior locks.
 */

use Directorist\Performance_Cache\Cache_Storage;
use Directorist\Performance_Cache\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Cache_Storage_Test extends TestCase {
    private $root;

    private $now = 1000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc06-storage-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_complete_entry_round_trip_validates_body_and_metadata_integrity() {
        $storage = $this->storage();
        $key     = $this->key();
        $body    = $this->html();

        $stored = $storage->store( $key, $body, $this->descriptor(), [ 'content-type' => 'text/html; charset=UTF-8' ], 60, 30 );
        $loaded = $storage->load( $key );

        $this->assertTrue( $stored['success'] );
        $this->assertSame( 'stored', $stored['code'] );
        $this->assertTrue( $loaded['hit'] );
        $this->assertSame( 'hit', $loaded['code'] );
        $this->assertSame( $body, $loaded['body'] );
        $this->assertSame( hash( 'sha256', $body ), $loaded['metadata']['body_hash'] );
        $this->assertSame( [], glob( $this->root . '/pages/*/*/*.tmp-*' ) );
    }

    public function test_expired_entry_misses_without_a_regeneration_lock() {
        $storage = $this->storage();
        $key     = $this->key();
        $storage->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 10, 30 );
        $this->now = 1011;

        $this->assertSame( 'expired', $storage->load( $key )['code'] );
    }

    public function test_bounded_stale_is_served_only_while_another_request_holds_regeneration_lock() {
        $writer = $this->storage();
        $reader = $this->storage();
        $key    = $this->key();
        $writer->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 10, 30 );
        $this->now = 1011;

        $this->assertTrue( $writer->begin_regeneration( $key )['success'] );
        $stale = $reader->load( $key );

        $this->assertTrue( $stale['hit'] );
        $this->assertTrue( $stale['stale'] );
        $this->assertSame( 'stale_while_regenerating', $stale['code'] );

        $writer->release_regeneration();
        $this->assertSame( 'expired', $reader->load( $key )['code'] );
        $this->now = 1041;
        $this->assertSame( 'stale_expired', $reader->load( $key )['code'] );
    }

    public function test_generation_change_invalidates_entry_even_inside_stale_window() {
        $storage = $this->storage();
        $key     = $this->key();
        $storage->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 60, 30 );

        $this->assertSame( 'generations_bumped', $storage->bump_generations( [ 'directorist:1:listing:91' ] )['code'] );
        $this->assertSame( 'generation_mismatch', $storage->load( $key )['code'] );
    }

    public function test_corrupt_or_partial_entry_fails_open() {
        $storage = $this->storage();
        $key     = $this->key();
        $stored  = $storage->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 60, 30 );
        file_put_contents( $stored['paths']['body'], 'corrupt' );

        $this->assertSame( 'body_mismatch', $storage->load( $key )['code'] );

        file_put_contents( $stored['paths']['metadata'], '{malformed' );
        $this->assertSame( 'invalid_metadata', $storage->load( $key )['code'] );
    }

    public function test_corrupt_generation_state_invalidates_instead_of_becoming_generation_zero() {
        $storage = $this->storage();
        $key     = $this->key();
        $stored  = $storage->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 60, 30 );
        $hash    = hash( 'sha256', 'directorist:1:site' );
        $path    = $this->root . '/generations/' . substr( $hash, 0, 2 ) . '/' . $hash . '.gen';

        mkdir( dirname( $path ), 0777, true );
        file_put_contents( $path, 'corrupt' );

        $this->assertTrue( $stored['success'] );
        $this->assertSame( 'generation_mismatch', $storage->load( $key )['code'] );
    }

    public function test_unsafe_persisted_header_metadata_fails_open() {
        $storage  = $this->storage();
        $key      = $this->key();
        $stored   = $storage->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 60, 30 );
        $metadata = json_decode( file_get_contents( $stored['paths']['metadata'] ), true );

        $metadata['headers'] = [ 'content-type' => "text/html\r\nSet-Cookie: private=1" ];
        file_put_contents( $stored['paths']['metadata'], json_encode( $metadata ) );

        $this->assertSame( 'invalid_metadata', $storage->load( $key )['code'] );
    }

    public function test_exact_purge_removes_owned_entry_only() {
        $storage = $this->storage();
        $key     = $this->key();
        $storage->store( $key, $this->html(), $this->descriptor(), [ 'content-type' => 'text/html' ], 60, 30 );

        $this->assertSame( 'purged', $storage->purge( $key )['code'] );
        $this->assertSame( 'miss', $storage->load( $key )['code'] );
    }

    private function storage() {
        return new Cache_Storage(
            $this->root,
            function () {
                return $this->now;
            }
        );
    }

    private function key() {
        return ( new Request_Key() )->from_url( 'https://example.test/directory/' );
    }

    private function descriptor() {
        return [
            'eligible'     => true,
            'site_id'      => 1,
            'route_type'   => 'listing',
            'cache_key'    => 'directorist:page:v1:site:1:' . str_repeat( 'a', 64 ),
            'dependencies' => [ 'directorist:1:site', 'directorist:1:listing:91' ],
        ];
    }

    private function html() {
        return '<!doctype html><html><body>Directory</body></html>';
    }

    private function remove_tree( $path ) {
        if ( is_link( $path ) || is_file( $path ) ) {
            unlink( $path );

            return;
        }

        if ( ! is_dir( $path ) ) {
            return;
        }

        foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $item ) {
            $this->remove_tree( $path . '/' . $item );
        }

        rmdir( $path );
    }
}

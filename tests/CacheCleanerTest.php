<?php
/**
 * Bounded expired and generation-mismatched entry cleanup behavior locks.
 */

use Directorist\Performance_Cache\Cache_Cleaner;
use Directorist\Performance_Cache\Cache_Storage;
use Directorist\Performance_Cache\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Cache_Cleaner_Test extends TestCase {
    private $root;

    private $now = 1000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc07-cleaner-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_cleanup_removes_only_a_bounded_number_of_stale_expired_entries_per_run() {
        $this->seed( 'https://example.test/one/' );
        $this->seed( 'https://example.test/two/' );
        $this->seed( 'https://example.test/three/' );
        $this->now = 1100;
        $cleaner   = $this->cleaner();

        $first = $cleaner->run( 2 );

        $this->assertSame( 2, $first['examined'] );
        $this->assertSame( 2, $first['removed'] );
        $this->assertCount( 1, glob( $this->root . '/pages/*/*/*.json' ) );

        $second = $cleaner->run( 2 );

        $this->assertSame( 1, $second['removed'] );
        $this->assertSame( [], glob( $this->root . '/pages/*/*/*.json' ) );
    }

    public function test_cleanup_removes_generation_mismatch_but_preserves_current_entries() {
        $stale_key = $this->seed( 'https://example.test/stale/' );
        $fresh_key = $this->seed( 'https://example.test/fresh/', [ 'directorist:1:listing:92' ] );
        $storage   = $this->storage();
        $storage->bump_generations( [ 'directorist:1:listing:91' ] );

        $result = $this->cleaner()->run( 20 );

        $this->assertSame( 2, $result['examined'] );
        $this->assertSame( 1, $result['removed'] );
        $this->assertSame( 'miss', $storage->load( $stale_key )['code'] );
        $this->assertSame( 'hit', $storage->load( $fresh_key )['code'] );
    }

    public function test_cleanup_refuses_symlinked_metadata() {
        $foreign = $this->root . '-foreign.json';
        $hash    = str_repeat( 'a', 64 );
        $path    = $this->root . '/pages/aa/aa/' . $hash . '.json';
        mkdir( dirname( $path ), 0777, true );
        file_put_contents( $foreign, '{"foreign":true}' );
        symlink( $foreign, $path );

        $result = $this->cleaner()->run( 20 );

        $this->assertSame( 1, $result['errors'] );
        $this->assertTrue( is_link( $path ) );
        $this->assertFileExists( $foreign );
        unlink( $foreign );
    }

    private function seed( $url, array $dependencies = [ 'directorist:1:listing:91' ] ) {
        $key = ( new Request_Key() )->from_url( $url );
        $this->storage()->store(
            $key,
            '<!doctype html><html><body>Cached</body></html>',
            [
                'eligible'     => true,
                'site_id'      => 1,
                'route_type'   => 'listing',
                'cache_key'    => 'directorist:test',
                'dependencies' => $dependencies,
            ],
            [ 'content-type' => 'text/html' ],
            10,
            20
        );

        return $key;
    }

    private function storage() {
        return new Cache_Storage(
            $this->root,
            function () {
                return $this->now;
            }
        );
    }

    private function cleaner() {
        return new Cache_Cleaner(
            $this->root,
            function () {
                return $this->now;
            }
        );
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

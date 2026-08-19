<?php
/**
 * Cache namespace containment behavior locks.
 */

use Directorist\Performance_Cache\Cache_Paths;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Cache_Paths_Test extends TestCase {
    public function test_only_fixed_hex_segments_are_derived_from_a_valid_hash() {
        $root  = sys_get_temp_dir() . '/directorist-pc06-paths';
        $hash  = hash( 'sha256', 'https://example.test/directory/' );
        $paths = ( new Cache_Paths( $root ) )->entry( $hash );

        $this->assertSame( $root . '/pages/' . substr( $hash, 0, 2 ) . '/' . substr( $hash, 2, 2 ) . '/' . $hash . '.body', $paths['body'] );
        $this->assertSame( dirname( $paths['body'] ) . '/' . $hash . '.json', $paths['metadata'] );
        $this->assertSame( $root . '/locks/' . substr( $hash, 0, 2 ) . '/' . $hash . '.lock', $paths['lock'] );
    }

    public function test_invalid_hash_or_unsafe_root_produces_no_paths() {
        $this->assertSame( [], ( new Cache_Paths( '/' ) )->entry( str_repeat( 'a', 64 ) ) );
        $this->assertSame( [], ( new Cache_Paths( '/tmp/cache' ) )->entry( '../foreign' ) );
        $this->assertSame( '', ( new Cache_Paths( '/tmp/cache' ) )->generation( '' ) );
    }

    public function test_generation_path_hashes_dependency_material() {
        $root = '/tmp/directorist-pc06-generations';
        $key  = 'directorist:1:listing:91';
        $hash = hash( 'sha256', $key );

        $this->assertSame( $root . '/generations/' . substr( $hash, 0, 2 ) . '/' . $hash . '.gen', ( new Cache_Paths( $root ) )->generation( $key ) );
    }
}

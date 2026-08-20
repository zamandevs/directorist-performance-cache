<?php
/**
 * Bounded read-only cache inventory behavior locks.
 */

use Directorist\Performance_Cache\Cache_Inventory;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Cache_Inventory_Test extends TestCase {
    private $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc08-inventory-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->root . '/pages/aa/bb', 0777, true );
        mkdir( $this->root . '/generations/cc', 0777, true );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_inventory_counts_complete_entries_orphans_and_generations_without_mutation() {
        $complete = str_repeat( 'a', 64 );
        $orphan   = str_repeat( 'b', 64 );
        file_put_contents( $this->root . '/pages/aa/bb/' . $complete . '.json', '{}' );
        file_put_contents( $this->root . '/pages/aa/bb/' . $complete . '.body', 'body' );
        file_put_contents( $this->root . '/pages/aa/bb/' . $orphan . '.json', '{}' );
        file_put_contents( $this->root . '/generations/cc/' . str_repeat( 'c', 64 ) . '.gen', '1' );

        $status = ( new Cache_Inventory( $this->root ) )->status();

        $this->assertTrue( $status['success'] );
        $this->assertSame( 1, $status['entries'] );
        $this->assertSame( 1, $status['orphans'] );
        $this->assertSame( 1, $status['generations'] );
        $this->assertFalse( $status['truncated'] );
    }

    public function test_missing_or_symlinked_root_fails_closed() {
        $missing = $this->root . '/missing';
        $this->assertSame( 'cache_root_missing', ( new Cache_Inventory( $missing ) )->status()['code'] );

        $link = $this->root . '-link';
        symlink( $this->root, $link );
        $this->assertSame( 'cache_root_symlink', ( new Cache_Inventory( $link ) )->status()['code'] );
        unlink( $link );
    }

    public function test_inventory_never_examines_more_than_the_hard_file_limit() {
        for ( $index = 0; $index <= Cache_Inventory::MAX_FILES; ++$index ) {
            $hash = hash( 'sha256', 'entry-' . $index );
            file_put_contents( $this->root . '/pages/aa/bb/' . $hash . '.json', '{}' );
        }

        $status = ( new Cache_Inventory( $this->root ) )->status();

        $this->assertTrue( $status['truncated'] );
        $this->assertLessThanOrEqual( Cache_Inventory::MAX_FILES, $status['examined'] );
    }

    private function remove_tree( $path ) {
        if ( is_file( $path ) || is_link( $path ) ) {
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

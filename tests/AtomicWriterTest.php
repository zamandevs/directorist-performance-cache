<?php
/**
 * Atomic writer behavior locks.
 */

use Directorist\Performance_Cache\Atomic_Writer;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Atomic_Writer_Test extends TestCase {
    private $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/directorist-pc05-writer-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->directory, 0777, true );
    }

    protected function tearDown(): void {
        foreach ( glob( $this->directory . '/*' ) as $path ) {
            is_link( $path ) || is_file( $path ) ? unlink( $path ) : rmdir( $path );
        }
        rmdir( $this->directory );
    }

    public function test_new_and_replacement_writes_leave_complete_content_and_no_temp_files() {
        $path   = $this->directory . '/owned.php';
        $writer = new Atomic_Writer();

        $this->assertTrue( $writer->write( $path, 'first' )['success'] );
        $this->assertTrue( $writer->write( $path, 'second-complete' )['success'] );
        $this->assertSame( 'second-complete', file_get_contents( $path ) );
        $this->assertSame( [], glob( $this->directory . '/.directorist-cache-*' ) );
    }

    public function test_symlink_target_is_refused_and_remove_does_not_follow_it() {
        $target = $this->directory . '/target.php';
        $link   = $this->directory . '/link.php';
        file_put_contents( $target, 'foreign' );
        symlink( $target, $link );
        $writer = new Atomic_Writer();

        $this->assertSame( 'foreign_symlink', $writer->write( $link, 'replacement' )['code'] );
        $this->assertSame( 'remove_failed', $writer->remove( $link )['code'] );
        $this->assertSame( 'foreign', file_get_contents( $target ) );
    }

    public function test_replacement_preserves_existing_file_permissions() {
        $path = $this->directory . '/restricted.php';
        file_put_contents( $path, 'before' );
        chmod( $path, 0640 );
        clearstatcache( true, $path );

        $result = ( new Atomic_Writer() )->write( $path, 'after' );

        clearstatcache( true, $path );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 0640, fileperms( $path ) & 0777 );
    }
}

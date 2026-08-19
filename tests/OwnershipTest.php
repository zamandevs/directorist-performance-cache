<?php
/**
 * Exact file-ownership behavior locks.
 */

use Directorist\Performance_Cache\Ownership;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Ownership_Test extends TestCase {
    private $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/directorist-pc05-owner-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->directory, 0777, true );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->directory );
    }

    public function test_missing_owned_foreign_and_symlink_states_are_distinct() {
        $path = $this->directory . '/advanced-cache.php';

        $this->assertSame( 'missing', Ownership::classify_dropin( $path ) );

        file_put_contents( $path, "<?php\n// DIRECTORIST PAGE CACHE DROPIN\n// Owner-ID: directorist-performance-cache\n" );
        $this->assertSame( 'owned', Ownership::classify_dropin( $path ) );

        file_put_contents( $path, "<?php\n// another cache provider\n" );
        $this->assertSame( 'foreign', Ownership::classify_dropin( $path ) );

        unlink( $path );
        $target = $this->directory . '/foreign.php';
        file_put_contents( $target, '<?php' );
        symlink( $target, $path );
        $this->assertSame( 'foreign_symlink', Ownership::classify_dropin( $path ) );
    }

    public function test_partial_or_forged_markers_do_not_claim_ownership() {
        $this->assertFalse( Ownership::owns_dropin_content( '// DIRECTORIST PAGE CACHE DROPIN' ) );
        $this->assertFalse( Ownership::owns_dropin_content( '// Owner-ID: directorist-performance-cache' ) );
        $this->assertTrue(
            Ownership::owns_dropin_content(
                "// DIRECTORIST PAGE CACHE DROPIN\n// Owner-ID: directorist-performance-cache\n"
            )
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

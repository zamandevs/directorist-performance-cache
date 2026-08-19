<?php
/**
 * Drop-in/config installation lifecycle behavior locks.
 */

use Directorist\Performance_Cache\Dropin_Installer;
use Directorist\Performance_Cache\Ownership;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Dropin_Installer_Test extends TestCase {
    private $root;

    private $content_dir;

    private $plugin_dir;

    protected function setUp(): void {
        $this->root        = sys_get_temp_dir() . '/directorist-pc05-installer-' . bin2hex( random_bytes( 6 ) );
        $this->content_dir = $this->root . '/wp-content';
        $this->plugin_dir  = $this->content_dir . '/plugins/directorist-performance-cache';

        mkdir( $this->plugin_dir . '/src', 0777, true );
        file_put_contents( $this->plugin_dir . '/src/early-bootstrap.php', '<?php return false;' );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_foreign_dropin_is_refused_without_creating_or_changing_any_file() {
        $dropin = $this->content_dir . '/advanced-cache.php';
        $source = "<?php\n// foreign provider\n";
        file_put_contents( $dropin, $source );

        $result = $this->installer()->install();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'foreign_dropin', $result['code'] );
        $this->assertSame( $source, file_get_contents( $dropin ) );
        $this->assertFileDoesNotExist( $this->config_path() );
    }

    public function test_symlink_dropin_is_refused_without_touching_its_target() {
        $target = $this->root . '/foreign-target.php';
        file_put_contents( $target, '<?php // foreign target' );
        symlink( $target, $this->content_dir . '/advanced-cache.php' );

        $result = $this->installer()->install();

        $this->assertSame( 'foreign_symlink', $result['code'] );
        $this->assertSame( '<?php // foreign target', file_get_contents( $target ) );
        $this->assertFileDoesNotExist( $this->config_path() );
    }

    public function test_fresh_install_writes_owned_dropin_and_valid_config() {
        $result = $this->installer()->install();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'installed', $result['code'] );
        $this->assertSame( 'owned', Ownership::classify_dropin( $this->content_dir . '/advanced-cache.php' ) );
        $this->assertFileExists( $this->config_path() );

        $config = json_decode( file_get_contents( $this->config_path() ), true );
        $this->assertSame( 1, $config['schema'] );
        $this->assertSame( 'directorist-performance-cache', $config['owner'] );
        $this->assertSame( $this->plugin_dir . '/src/early-bootstrap.php', $config['bootstrap_file'] );
        $this->assertSame( $this->content_dir . '/cache/directorist-performance-cache', $config['cache_dir'] );
    }

    public function test_owned_files_are_upgraded_and_removed_but_foreign_files_are_preserved() {
        $installer = $this->installer();
        $installer->install();

        file_put_contents(
            $this->content_dir . '/advanced-cache.php',
            "<?php\n// DIRECTORIST PAGE CACHE DROPIN\n// Owner-ID: directorist-performance-cache\n// stale\n"
        );
        file_put_contents(
            $this->config_path(),
            '{"_marker":"DIRECTORIST PAGE CACHE CONFIG","owner_id":"Owner-ID: directorist-performance-cache","schema":0}'
        );

        $this->assertSame( 'upgraded', $installer->install()['code'] );
        $this->assertStringNotContainsString( '// stale', file_get_contents( $this->content_dir . '/advanced-cache.php' ) );
        $this->assertSame( 1, json_decode( file_get_contents( $this->config_path() ), true )['schema'] );

        $this->assertSame( 'removed', $installer->remove()['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertFileDoesNotExist( $this->config_path() );

        file_put_contents( $this->content_dir . '/advanced-cache.php', '<?php // foreign' );
        $this->assertSame( 'foreign_dropin', $installer->remove()['code'] );
        $this->assertFileExists( $this->content_dir . '/advanced-cache.php' );
    }

    public function test_unwritable_target_reports_diagnostic_without_partial_dropin() {
        $cache_root = $this->content_dir . '/cache';
        mkdir( $cache_root, 0555, true );
        chmod( $cache_root, 0555 );

        $result = $this->installer()->install();

        chmod( $cache_root, 0777 );

        $this->assertFalse( $result['success'] );
        $this->assertContains( $result['code'], [ 'directory_create_failed', 'directory_not_writable', 'write_failed' ] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
    }

    public function test_missing_packaged_dropin_source_fails_without_partial_files() {
        $installer = new Dropin_Installer(
            $this->content_dir,
            $this->plugin_dir,
            null,
            $this->root . '/missing-packaged-dropin.php'
        );

        $result = $installer->install();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'dropin_source_unreadable', $result['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertFileDoesNotExist( $this->config_path() );
    }

    private function installer() {
        return new Dropin_Installer( $this->content_dir, $this->plugin_dir );
    }

    private function config_path() {
        return $this->content_dir . '/cache/directorist-performance-cache/config.json';
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

<?php
/**
 * Companion activation/deactivation transaction behavior locks.
 */

use Directorist\Performance_Cache\Dropin_Installer;
use Directorist\Performance_Cache\Lifecycle_Manager;
use Directorist\Performance_Cache\Atomic_Writer;
use Directorist\Performance_Cache\WP_Cache_Config;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Lifecycle_Manager_Test extends TestCase {
    private $root;

    private $content_dir;

    private $plugin_dir;

    private $wp_config;

    protected function setUp(): void {
        $this->root        = sys_get_temp_dir() . '/directorist-pc05-lifecycle-' . bin2hex( random_bytes( 6 ) );
        $this->content_dir = $this->root . '/wp-content';
        $this->plugin_dir  = $this->content_dir . '/plugins/directorist-performance-cache';
        $this->wp_config   = $this->root . '/wp-config.php';
        mkdir( $this->plugin_dir . '/src', 0777, true );
        file_put_contents( $this->plugin_dir . '/src/early-bootstrap.php', '<?php return false;' );
        file_put_contents( $this->wp_config, "<?php\n// WordPress config.\n" );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_foreign_dropin_and_per_site_multisite_activation_make_no_changes() {
        $dropin = $this->content_dir . '/advanced-cache.php';
        file_put_contents( $dropin, '<?php // foreign' );
        $before_config = file_get_contents( $this->wp_config );

        $foreign = $this->manager()->activate( false, false );
        $network = $this->manager()->activate( true, false );

        $this->assertSame( 'foreign_dropin', $foreign['code'] );
        $this->assertSame( 'network_activation_required', $network['code'] );
        $this->assertSame( '<?php // foreign', file_get_contents( $dropin ) );
        $this->assertSame( $before_config, file_get_contents( $this->wp_config ) );
    }

    public function test_activation_and_deactivation_manage_only_owned_files_and_wp_cache_block() {
        $manager = $this->manager();

        $this->assertSame( 'activated', $manager->activate( false, false )['code'] );
        $this->assertFileExists( $this->content_dir . '/advanced-cache.php' );
        $this->assertStringContainsString( 'DIRECTORIST PERFORMANCE CACHE WP_CACHE', file_get_contents( $this->wp_config ) );

        $this->assertSame( 'deactivated', $manager->deactivate()['code'] );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertStringNotContainsString( 'DIRECTORIST PERFORMANCE CACHE WP_CACHE', file_get_contents( $this->wp_config ) );
    }

    public function test_external_true_wp_cache_definition_is_preserved_on_deactivation() {
        $source = "<?php\ndefine( 'WP_CACHE', true ); // foreign owner\n";
        file_put_contents( $this->wp_config, $source );
        $manager = $this->manager();

        $this->assertSame( 'activated', $manager->activate( false, false )['code'] );
        $this->assertSame( 'deactivated', $manager->deactivate()['code'] );
        $this->assertSame( $source, file_get_contents( $this->wp_config ) );
    }

    public function test_foreign_replacement_after_activation_prevents_any_deactivation_mutation() {
        $manager = $this->manager();
        $manager->activate( false, false );
        $dropin = $this->content_dir . '/advanced-cache.php';
        file_put_contents( $dropin, '<?php // replacement provider' );
        $wp_config_before = file_get_contents( $this->wp_config );

        $result = $manager->deactivate();

        $this->assertSame( 'foreign_dropin', $result['code'] );
        $this->assertSame( '<?php // replacement provider', file_get_contents( $dropin ) );
        $this->assertSame( $wp_config_before, file_get_contents( $this->wp_config ) );
    }

    public function test_dropin_write_failure_rolls_back_generated_config_and_owned_wp_cache_block() {
        $source  = file_get_contents( $this->wp_config );
        $manager = $this->manager( new Directorist_Performance_Cache_Failing_Dropin_Writer() );

        $result = $manager->activate( false, false );

        $this->assertSame( 'forced_dropin_failure', $result['code'] );
        $this->assertSame( $source, file_get_contents( $this->wp_config ) );
        $this->assertFileDoesNotExist( $this->content_dir . '/advanced-cache.php' );
        $this->assertFileDoesNotExist( $this->content_dir . '/cache/directorist-performance-cache/config.json' );
    }

    private function manager( Atomic_Writer $writer = null ) {
        return new Lifecycle_Manager(
            new Dropin_Installer( $this->content_dir, $this->plugin_dir, $writer ),
            new WP_Cache_Config( $this->wp_config )
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

final class Directorist_Performance_Cache_Failing_Dropin_Writer extends Atomic_Writer {
    public function write( $path, $content ) {
        if ( 'advanced-cache.php' === basename( $path ) ) {
            return [
                'success' => false,
                'code'    => 'forced_dropin_failure',
                'path'    => $path,
            ];
        }

        return parent::write( $path, $content );
    }
}

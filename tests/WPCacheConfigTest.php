<?php
/**
 * Exact wp-config.php ownership behavior locks.
 */

use Directorist\Performance_Cache\WP_Cache_Config;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_WP_Cache_Config_Test extends TestCase {
    private $directory;

    private $path;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/directorist-pc05-wpconfig-' . bin2hex( random_bytes( 6 ) );
        $this->path      = $this->directory . '/wp-config.php';
        mkdir( $this->directory, 0777, true );
    }

    protected function tearDown(): void {
        if ( is_link( $this->path ) || is_file( $this->path ) ) {
            unlink( $this->path );
        }
        rmdir( $this->directory );
    }

    public function test_missing_wp_cache_is_added_and_only_owned_block_is_removed() {
        $source = "<?php\n// WordPress configuration.\n";
        file_put_contents( $this->path, $source );
        $manager = new WP_Cache_Config( $this->path );

        $this->assertSame( 'enabled', $manager->enable()['code'] );
        $this->assertStringContainsString( 'DIRECTORIST PERFORMANCE CACHE WP_CACHE', file_get_contents( $this->path ) );
        $this->assertStringContainsString( "define( 'WP_CACHE', true );", file_get_contents( $this->path ) );

        $this->assertSame( 'removed', $manager->disable()['code'] );
        $this->assertStringNotContainsString( "define( 'WP_CACHE', true );", file_get_contents( $this->path ) );
        $this->assertStringContainsString( '// WordPress configuration.', file_get_contents( $this->path ) );
        $this->assertSame( $source, file_get_contents( $this->path ) );
    }

    public function test_external_true_definition_is_accepted_and_never_removed() {
        $source = "<?php\ndefine( 'WP_CACHE', true ); // another provider\n";
        file_put_contents( $this->path, $source );
        $manager = new WP_Cache_Config( $this->path );

        $this->assertSame( 'already_enabled_external', $manager->enable()['code'] );
        $this->assertSame( 'not_owned', $manager->disable()['code'] );
        $this->assertSame( $source, file_get_contents( $this->path ) );
    }

    public function test_separated_or_forged_markers_never_claim_external_definition() {
        $source = implode(
            "\n",
            [
                '<?php',
                WP_Cache_Config::BLOCK_BEGIN,
                '// Unrelated configuration between marker comments.',
                WP_Cache_Config::BLOCK_END,
                "define( 'WP_CACHE', true ); // External owner.",
                '',
            ]
        );
        file_put_contents( $this->path, $source );
        $manager = new WP_Cache_Config( $this->path );

        $this->assertSame( 'already_enabled_external', $manager->enable()['code'] );
        $this->assertSame( 'not_owned', $manager->disable()['code'] );
        $this->assertSame( $source, file_get_contents( $this->path ) );
    }

    public function test_false_unknown_or_multiple_definitions_are_refused_unchanged() {
        foreach (
            [
                "<?php\ndefine( 'WP_CACHE', false );\n",
                "<?php\ndefine( 'WP_CACHE', SOME_CONSTANT );\n",
                "<?php\ndefine( 'WP_CACHE', true );\ndefine( 'WP_CACHE', true );\n",
            ] as $source
        ) {
            file_put_contents( $this->path, $source );
            $result = ( new WP_Cache_Config( $this->path ) )->enable();

            $this->assertFalse( $result['success'] );
            $this->assertSame( 'conflicting_wp_cache', $result['code'] );
            $this->assertSame( $source, file_get_contents( $this->path ) );
        }
    }

    public function test_symlink_config_is_refused_without_touching_target() {
        $target = $this->directory . '/target.php';
        file_put_contents( $target, '<?php // target' );
        symlink( $target, $this->path );

        $result = ( new WP_Cache_Config( $this->path ) )->enable();

        $this->assertSame( 'foreign_symlink', $result['code'] );
        $this->assertSame( '<?php // target', file_get_contents( $target ) );

        unlink( $target );
    }
}

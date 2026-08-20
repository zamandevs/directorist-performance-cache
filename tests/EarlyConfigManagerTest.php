<?php
/**
 * Atomic early-config operational state behavior locks.
 */

use Directorist\Performance_Cache\Early_Config;
use Directorist\Performance_Cache\Early_Config_Manager;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Early_Config_Manager_Test extends TestCase {
    private $root;

    private $path;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc08-config-' . bin2hex( random_bytes( 6 ) );
        $this->path = $this->root . '/config.json';
        mkdir( $this->root, 0777, true );
        file_put_contents( $this->path, ( new Early_Config( $this->root, DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR ) )->render() );
    }

    protected function tearDown(): void {
        if ( is_file( $this->path ) || is_link( $this->path ) ) {
            unlink( $this->path );
        }

        if ( is_dir( $this->root ) ) {
            rmdir( $this->root );
        }
    }

    public function test_owned_config_defaults_enabled_and_updates_atomically() {
        $manager = new Early_Config_Manager( $this->path );

        $this->assertTrue( $manager->status()['enabled'] );
        $disabled = $manager->set_enabled( false );
        $this->assertTrue( $disabled['success'] );
        $this->assertTrue( $disabled['changed'] );
        $this->assertFalse( json_decode( file_get_contents( $this->path ), true )['enabled'] );

        $unchanged = $manager->set_enabled( false );
        $this->assertTrue( $unchanged['success'] );
        $this->assertFalse( $unchanged['changed'] );
    }

    public function test_legacy_owned_config_without_enabled_key_is_treated_as_enabled() {
        $config = json_decode( file_get_contents( $this->path ), true );
        unset( $config['enabled'] );
        file_put_contents( $this->path, json_encode( $config ) );

        $this->assertTrue( ( new Early_Config_Manager( $this->path ) )->status()['enabled'] );
    }

    public function test_missing_foreign_invalid_and_symlink_configs_are_never_rewritten() {
        unlink( $this->path );
        $manager = new Early_Config_Manager( $this->path );
        $this->assertSame( 'config_missing', $manager->set_enabled( false )['code'] );

        file_put_contents( $this->path, '{"owner":"foreign"}' );
        $this->assertSame( 'config_foreign', $manager->set_enabled( false )['code'] );
        $this->assertSame( '{"owner":"foreign"}', file_get_contents( $this->path ) );

        file_put_contents( $this->path, '{"_marker":"DIRECTORIST PAGE CACHE CONFIG","owner_id":"Owner-ID: directorist-performance-cache","schema":0}' );
        $this->assertSame( 'config_invalid', $manager->set_enabled( false )['code'] );

        unlink( $this->path );
        $target = $this->root . '/target.json';
        file_put_contents( $target, ( new Early_Config( $this->root, DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR ) )->render() );
        symlink( $target, $this->path );
        $this->assertSame( 'config_symlink', $manager->set_enabled( false )['code'] );
        $this->assertTrue( json_decode( file_get_contents( $target ), true )['enabled'] );
        unlink( $this->path );
        unlink( $target );
    }
}

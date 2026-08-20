<?php
/**
 * Core dashboard adapter and fail-open control behavior locks.
 */

use Directorist\Performance_Cache\Atomic_Writer;
use Directorist\Performance_Cache\Cache_Cleaner;
use Directorist\Performance_Cache\Cache_Engine;
use Directorist\Performance_Cache\Cache_Inventory;
use Directorist\Performance_Cache\Dropin_Installer;
use Directorist\Performance_Cache\Early_Config_Manager;
use Directorist\Performance_Cache\Lifecycle_Manager;
use Directorist\Performance_Cache\Performance_Integration;
use Directorist\Performance_Cache\Runtime_Controller;
use Directorist\Performance_Cache\Warm_Queue;
use Directorist\Performance_Cache\WP_Cache_Config;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_PC08_Test_Provider {
    private $id;

    public function __construct( $id = 'directorist-cache' ) {
        $this->id = $id;
    }

    public function get_id() {
        return $this->id;
    }
}

final class Directorist_Performance_Cache_Performance_Integration_Test extends TestCase {
    private $root;

    private $content_dir;

    private $cache_root;

    private $config;

    private $controller;

    private $lifecycle;

    private $inventory;

    protected function setUp(): void {
        $this->root        = sys_get_temp_dir() . '/directorist-pc08-integration-' . bin2hex( random_bytes( 6 ) );
        $this->content_dir = $this->root . '/wp-content';
        $this->cache_root  = $this->content_dir . '/cache/directorist-performance-cache';
        mkdir( $this->content_dir, 0777, true );
        file_put_contents( $this->root . '/wp-config.php', "<?php\n" );

        $writer          = new Atomic_Writer();
        $installer       = new Dropin_Installer( $this->content_dir, DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR, $writer );
        $this->lifecycle = new Lifecycle_Manager( $installer, new WP_Cache_Config( $this->root . '/wp-config.php', $writer ) );
        $this->assertTrue( $installer->install()['success'] );
        $this->config    = new Early_Config_Manager( $this->cache_root . '/config.json', $writer );
        $engine          = new Cache_Engine( [ 'cache_dir' => $this->cache_root, 'ttl' => 60, 'stale_ttl' => 30 ] );
        $queue           = new Warm_Queue( $this->cache_root, 'https://example.test/' );
        $cleaner         = new Cache_Cleaner( $this->cache_root );
        $this->controller = new Runtime_Controller(
            $engine,
            $queue,
            $cleaner,
            [
                'successor'       => static function () {
                    return true;
                },
                'schedule_status' => static function ( $hook ) {
                    return Runtime_Controller::RECOVERY_HOOK === $hook ? 1100 : 1200;
                },
            ]
        );
        $this->inventory = new Cache_Inventory( $this->cache_root );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_status_adds_bounded_provider_health_and_route_counts() {
        $this->controller->enqueue( [ 'https://example.test/directory/' ] );
        $integration = $this->integration();
        $status      = $integration->extend_status(
            [
                'settings'   => [ 'enabled' => true ],
                'extensions' => [],
                'route_flow' => [ 'eligible' => 0, 'bypassed' => 0, 'queued' => 0, 'cached' => 0, 'invalidated' => 0 ],
            ],
            new Directorist_Performance_Cache_PC08_Test_Provider()
        );

        $this->assertArrayHasKey( 'directorist-cache', $status['extensions'] );
        $values = $status['extensions']['directorist-cache']['values'];
        $this->assertSame( 'owned', $values['Drop-in'] );
        $this->assertSame( 'enabled', $values['Early cache'] );
        $this->assertSame( 1, $values['Queue pending'] );
        $this->assertSame( 1, $status['route_flow']['queued'] );
        $this->assertSame( 0, $status['route_flow']['cached'] );

        unset( $status['extensions']['directorist-cache'] );
        $disabled_status = $integration->extend_status( $status, new Directorist_Performance_Cache_PC08_Test_Provider( 'none' ) );
        $this->assertArrayHasKey( 'directorist-cache', $disabled_status['extensions'] );
    }

    public function test_provider_operations_delegate_and_other_provider_results_are_preserved() {
        $integration = $this->integration();
        $provider    = new Directorist_Performance_Cache_PC08_Test_Provider();

        $this->assertSame( 'paused', $integration->handle_operation( null, 'pause', [], $provider )['code'] );
        $this->assertSame( 'resumed', $integration->handle_operation( null, 'resume', [], $provider )['code'] );
        $this->assertSame( 'cancelled', $integration->handle_operation( null, 'cancel', [], $provider )['code'] );
        $this->assertSame( 'healthy', $integration->handle_operation( null, 'verify', [], $provider )['code'] );

        $existing = [ 'success' => true, 'code' => 'claimed-by-other-adapter' ];
        $this->assertSame( $existing, $integration->handle_operation( $existing, 'pause', [], $provider ) );
        $this->assertNull( $integration->handle_operation( null, 'pause', [], new Directorist_Performance_Cache_PC08_Test_Provider( 'other' ) ) );
    }

    public function test_disable_writes_fail_open_config_before_generation_and_enable_bumps_first() {
        $order  = [];
        $events = [];
        $config = $this->config;
        $integration = $this->integration(
            static function () use ( &$order, $config ) {
                $order[] = $config->status()['enabled'] ? 'generation-enabled' : 'generation-disabled';

                return [ 'success' => true, 'code' => 'generations_bumped' ];
            },
            static function ( $level, $code ) use ( &$events ) {
                $events[] = [ $level, $code ];
            }
        );

        $disabled = $integration->sync_enabled( false, [ 'enabled' => false ] );
        $this->assertSame( 'disabled', $disabled['code'] );
        $this->assertFalse( $this->config->status()['enabled'] );
        $this->assertTrue( $this->controller->status()['queue']['paused'] );

        $enabled = $integration->sync_enabled( true, [ 'enabled' => true ] );
        $this->assertSame( 'enabled', $enabled['code'] );
        $this->assertTrue( $this->config->status()['enabled'] );
        $this->assertFalse( $this->controller->status()['queue']['paused'] );
        $this->assertSame( [ 'generation-disabled', 'generation-disabled' ], $order );
        $this->assertSame( [ [ 'success', 'cache-integration-disabled' ], [ 'success', 'cache-integration-enabled' ] ], $events );
    }

    public function test_failed_generation_never_reenables_early_cache() {
        $this->config->set_enabled( false );
        $integration = $this->integration(
            static function () {
                return [ 'success' => false, 'code' => 'generation_failed' ];
            }
        );

        $result = $integration->sync_enabled( true, [ 'enabled' => true ] );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'generation_failed', $result['code'] );
        $this->assertFalse( $this->config->status()['enabled'] );
    }

    public function test_disable_remains_fail_open_when_post_disable_generation_bump_fails() {
        $events      = [];
        $integration = $this->integration(
            static function () {
                return [ 'success' => false, 'code' => 'generation_failed' ];
            },
            static function ( $level, $code ) use ( &$events ) {
                $events[] = [ $level, $code ];
            }
        );

        $result = $integration->sync_enabled( false );

        $this->assertTrue( $result['success'] );
        $this->assertFalse( $this->config->status()['enabled'] );
        $this->assertContains( [ 'warning', 'cache-disable-generation-failed' ], $events );
    }

    public function test_old_core_without_settings_contract_is_a_noop() {
        $result = $this->integration()->sync_current_core_state();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'core_settings_unavailable', $result['code'] );
        $this->assertFalse( $result['changed'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_companion_upgrade_repairs_a_preexisting_core_disable_state() {
        eval( 'function directorist_page_cache_is_enabled() { return false; }' );
        $GLOBALS['directorist_performance_cache_early_config'] = [ 'enabled' => true ];

        $result = $this->integration()->sync_current_core_state();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'disabled', $result['code'] );
        $this->assertFalse( $this->config->status()['enabled'] );
    }

    private function integration( $generation = null, $reporter = null ) {
        return new Performance_Integration(
            $this->controller,
            $this->lifecycle,
            $this->config,
            $this->inventory,
            $generation ?: static function () {
                return [ 'success' => true, 'code' => 'generations_bumped' ];
            },
            $reporter
        );
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

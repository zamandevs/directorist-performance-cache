<?php
/**
 * Runtime queue wiring, schedule, and operational control behavior locks.
 */

use Directorist\Performance_Cache\Cache_Cleaner;
use Directorist\Performance_Cache\Cache_Engine;
use Directorist\Performance_Cache\Runtime_Controller;
use Directorist\Performance_Cache\Warm_Queue;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Runtime_Controller_Test extends TestCase {
    private $root;

    private $now = 1000;

    private $engine;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc07-controller-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_controller_attaches_queue_capability_and_enqueue_dispatches_async_work() {
        $successors = 0;
        $controller = $this->controller(
            [
                'successor' => static function () use ( &$successors ) {
                    ++$successors;

                    return true;
                },
            ]
        );
        $engine = $this->engine;

        $this->assertTrue( $engine->supports_warm() );
        $this->assertSame( [], glob( $this->root . '/operations/sites/*/warm-queue.json' ), 'Controller construction must not perform queue I/O.' );
        $result = $engine->warm( [ 'https://example.test/one/', 'https://example.test/two/' ] );

        $this->assertSame( 'queued', $result['code'] );
        $this->assertSame( 2, $result['queued'] );
        $this->assertSame( 1, $successors );
        $this->assertSame( 2, $controller->status()['queue']['pending'] );
    }

    public function test_recovery_runs_one_batch_and_pause_resume_cancel_controls_are_delegated() {
        $requested  = [];
        $controller = $this->controller(
            [
                'requester' => static function ( $url ) use ( &$requested ) {
                    $requested[] = $url;

                    return [ 'success' => true, 'code' => 'http_200' ];
                },
            ]
        );
        $controller->enqueue( [ 'https://example.test/one/', 'https://example.test/two/' ] );

        $this->assertSame( 'paused', $controller->pause()['code'] );
        $this->assertSame( 'paused', $controller->run_worker()['code'] );
        $this->assertSame( 'resumed', $controller->resume()['code'] );
        $this->assertSame( 'batch_complete', $controller->run_worker()['code'] );
        $this->assertCount( 2, $requested );

        $controller->enqueue( [ 'https://example.test/three/' ] );
        $this->assertSame( 'cancelled', $controller->cancel()['code'] );
        $this->assertSame( 0, $controller->status()['queue']['total'] );
    }

    public function test_activation_and_deactivation_manage_recovery_and_cleanup_schedules() {
        $scheduled   = [];
        $unscheduled = [];
        $controller  = $this->controller(
            [
                'scheduler' => static function ( $hook, $interval ) use ( &$scheduled ) {
                    $scheduled[ $hook ] = $interval;

                    return true;
                },
                'unscheduler' => static function ( $hook ) use ( &$unscheduled ) {
                    $unscheduled[] = $hook;

                    return true;
                },
            ]
        );

        $activated   = $controller->activate();
        $deactivated = $controller->deactivate();

        $this->assertTrue( $activated['success'] );
        $this->assertSame( 300, $scheduled[ Runtime_Controller::RECOVERY_HOOK ] );
        $this->assertSame( 86400, $scheduled[ Runtime_Controller::CLEANUP_HOOK ] );
        $this->assertTrue( $deactivated['success'] );
        $this->assertSame( [ Runtime_Controller::RECOVERY_HOOK, Runtime_Controller::CLEANUP_HOOK ], $unscheduled );
    }

    public function test_verify_reports_engine_queue_and_scheduler_health_without_mutation() {
        $controller = $this->controller(
            [
                'schedule_status' => static function ( $hook ) {
                    return Runtime_Controller::RECOVERY_HOOK === $hook ? 1100 : 1200;
                },
            ]
        );
        $verified = $controller->verify();

        $this->assertTrue( $verified['success'] );
        $this->assertTrue( $verified['engine_available'] );
        $this->assertTrue( $verified['queue_available'] );
        $this->assertSame( 1100, $verified['recovery_scheduled'] );
        $this->assertSame( 1200, $verified['cleanup_scheduled'] );
    }

    public function test_worker_and_cleanup_report_only_operational_failures() {
        $events     = [];
        $controller = $this->controller(
            [
                'requester' => static function () {
                    return [ 'success' => false, 'code' => 'http_failed_500' ];
                },
                'reporter'  => static function ( $level, $code, $context ) use ( &$events ) {
                    $events[] = [ $level, $code, $context ];
                },
            ]
        );

        $controller->enqueue( [ 'https://example.test/fails/' ] );
        $controller->run_worker();

        $this->assertCount( 1, $events );
        $this->assertSame( 'warning', $events[0][0] );
        $this->assertSame( 'warm-worker-retry', $events[0][1] );
    }

    private function controller( array $options = [] ) {
        $clock = function () {
            return $this->now;
        };
        $this->engine = new Cache_Engine(
            [
                'cache_dir' => $this->root,
                'ttl'       => 60,
                'stale_ttl' => 30,
            ],
            [ 'clock' => $clock ]
        );
        $queue   = new Warm_Queue( $this->root, 'https://example.test/', $clock );
        $cleaner = new Cache_Cleaner( $this->root, $clock );

        return new Runtime_Controller( $this->engine, $queue, $cleaner, $options );
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

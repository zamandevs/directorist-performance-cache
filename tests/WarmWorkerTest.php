<?php
/**
 * One-batch worker, successor, and recovery behavior locks.
 */

use Directorist\Performance_Cache\Warm_Queue;
use Directorist\Performance_Cache\Warm_Worker;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Warm_Worker_Test extends TestCase {
    private $root;

    private $now = 1000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc07-worker-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_worker_processes_one_bounded_batch_and_dispatches_one_successor() {
        $queue = $this->queue();
        $queue->enqueue(
            [
                'https://example.test/one/',
                'https://example.test/two/',
                'https://example.test/three/',
                'https://example.test/four/',
            ]
        );
        $requested  = [];
        $successors = 0;
        $worker     = new Warm_Worker(
            $queue,
            static function ( $url ) use ( &$requested ) {
                $requested[] = $url;

                return [ 'success' => true, 'code' => 'http_200' ];
            },
            static function () use ( &$successors ) {
                ++$successors;

                return true;
            }
        );

        $result = $worker->run( 3 );

        $this->assertSame( 'batch_complete', $result['code'] );
        $this->assertCount( 3, $requested );
        $this->assertSame( 1, $successors );
        $this->assertSame( 1, $queue->status()['pending'] );

        $worker->run( 3 );
        $this->assertCount( 4, $requested );
        $this->assertSame( 1, $successors, 'The final batch must not dispatch an empty successor.' );
    }

    public function test_failed_request_waits_for_backoff_and_a_later_recovery_worker_can_finish_it() {
        $queue = $this->queue();
        $queue->enqueue( [ 'https://example.test/retry/' ] );
        $attempts   = 0;
        $successors = 0;
        $worker     = new Warm_Worker(
            $queue,
            static function () use ( &$attempts ) {
                ++$attempts;

                return [ 'success' => 1 < $attempts, 'code' => 1 < $attempts ? 'http_200' : 'timeout' ];
            },
            static function () use ( &$successors ) {
                ++$successors;

                return true;
            }
        );

        $first = $worker->run( 1 );

        $this->assertSame( 'batch_complete', $first['code'] );
        $this->assertSame( 1, $attempts );
        $this->assertSame( 0, $successors );
        $this->assertSame( 'backoff', $worker->run( 1 )['code'] );

        $this->now += 15;
        $recovery = $worker->run( 1 );

        $this->assertSame( 'batch_complete', $recovery['code'] );
        $this->assertSame( 2, $attempts );
        $this->assertSame( 0, $queue->status()['total'] );
    }

    private function queue() {
        return new Warm_Queue(
            $this->root,
            'https://example.test/',
            function () {
                return $this->now;
            }
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

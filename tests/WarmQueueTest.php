<?php
/**
 * Persistent bounded warm queue behavior locks.
 */

use Directorist\Performance_Cache\Warm_Queue;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Warm_Queue_Test extends TestCase {
    private $root;

    private $now = 1000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc07-queue-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_enqueue_uses_canonical_hash_idempotency_same_origin_and_hard_capacity() {
        $queue  = $this->queue( 2 );
        $result = $queue->enqueue(
            [
                'https://example.test/directory/',
                'https://EXAMPLE.test:443/directory/',
                'https://example.test/location/dhaka/',
                'https://example.test/category/food/',
                'https://foreign.test/private/',
                'https://user@example.test/private/',
                'javascript:alert(1)',
            ]
        );
        $status = $queue->status();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 2, $result['queued'] );
        $this->assertSame( 1, $result['duplicates'] );
        $this->assertSame( 3, $result['rejected'] );
        $this->assertSame( 1, $result['full'] );
        $this->assertSame( 2, $status['pending'] );
        $this->assertSame( 2, $status['capacity'] );
        $this->assertCount( 1, glob( $this->root . '/operations/sites/*/warm-queue.json' ) );
    }

    public function test_claims_are_disjoint_and_abandoned_claims_recover_after_the_lease() {
        $queue = $this->queue();
        $queue->enqueue(
            [
                'https://example.test/one/',
                'https://example.test/two/',
                'https://example.test/three/',
            ]
        );

        $first  = $queue->claim( 2 );
        $second = $queue->claim( 2 );

        $this->assertSame( 'claimed', $first['code'] );
        $this->assertCount( 2, $first['items'] );
        $this->assertSame( 'claimed', $second['code'] );
        $this->assertCount( 1, $second['items'] );
        $this->assertSame( 0, $queue->status()['pending'] );
        $this->assertSame( 3, $queue->status()['inflight'] );

        $this->now += Warm_Queue::CLAIM_TTL + 1;
        $recovered = $queue->claim( 3 );

        $this->assertSame( 'claimed', $recovered['code'] );
        $this->assertCount( 3, $recovered['items'] );
        $this->assertNotSame( $first['claim'], $recovered['claim'] );
    }

    public function test_failures_retry_with_backoff_and_open_a_bounded_circuit() {
        $queue = $this->queue();
        $queue->enqueue(
            [
                'https://example.test/one/',
                'https://example.test/two/',
                'https://example.test/three/',
                'https://example.test/four/',
            ]
        );
        $claim = $queue->claim( 4 );
        $failed = [];

        foreach ( $claim['items'] as $item ) {
            $failed[ $item['hash'] ] = [ 'success' => false, 'code' => 'http_500' ];
        }

        $completed = $queue->complete( $claim['claim'], $failed );
        $status    = $queue->status();

        $this->assertSame( 'circuit_open', $completed['code'] );
        $this->assertSame( 4, $status['pending'] );
        $this->assertSame( 0, $status['inflight'] );
        $this->assertSame( 4, $status['consecutive_failures'] );
        $this->assertSame( 1300, $status['circuit_open_until'] );
        $this->assertSame( 'circuit_open', $queue->claim( 1 )['code'] );

        $this->now = 1300;
        $this->assertSame( 'claimed', $queue->claim( 1 )['code'] );
    }

    public function test_pause_resume_and_cancel_are_persisted_and_idempotent() {
        $queue = $this->queue();
        $queue->enqueue( [ 'https://example.test/one/' ] );

        $this->assertSame( 'paused', $queue->pause( 'manual' )['code'] );
        $this->assertSame( 'paused', $queue->claim( 1 )['code'] );
        $this->assertSame( 'resumed', $queue->resume()['code'] );
        $this->assertSame( 'claimed', $queue->claim( 1 )['code'] );
        $this->assertSame( 'cancelled', $queue->cancel()['code'] );
        $this->assertSame( 0, $queue->status()['total'] );
        $this->assertSame( 'cancelled', $queue->cancel()['code'] );
    }

    public function test_each_multisite_origin_has_an_independent_queue_namespace() {
        $first  = $this->queue();
        $second = new Warm_Queue(
            $this->root,
            'https://subsite.example.test/',
            function () {
                return $this->now;
            }
        );

        $this->assertSame( 1, $first->enqueue( [ 'https://example.test/one/' ] )['queued'] );
        $this->assertSame( 1, $second->enqueue( [ 'https://subsite.example.test/two/' ] )['queued'] );
        $this->assertSame( 1, $first->status()['total'] );
        $this->assertSame( 1, $second->status()['total'] );
        $this->assertCount( 2, glob( $this->root . '/operations/sites/*/warm-queue.json' ) );
    }

    private function queue( $capacity = 500 ) {
        return new Warm_Queue(
            $this->root,
            'https://example.test/',
            function () {
                return $this->now;
            },
            $capacity
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

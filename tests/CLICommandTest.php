<?php
/**
 * Operational CLI command behavior locks.
 */

use Directorist\Performance_Cache\CLI_Command;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_CLI', false ) ) {
    final class WP_CLI {
        public static $lines = [];

        public static $successes = [];

        public static $warnings = [];

        public static function log( $message ) {
            self::$lines[] = $message;
        }

        public static function success( $message ) {
            self::$successes[] = $message;
        }

        public static function warning( $message ) {
            self::$warnings[] = $message;
        }
    }
}

final class Directorist_Performance_Cache_CLI_Controller_Fixture {
    public $calls = [];

    public function status() {
        $this->calls[] = 'status';

        return [ 'queue' => [ 'pending' => 2 ] ];
    }

    public function enqueue( array $urls ) {
        $this->calls[] = [ 'warm', $urls ];

        return [ 'success' => true, 'code' => 'queued', 'queued' => count( $urls ) ];
    }

    public function purge_site( $site_id ) {
        $this->calls[] = [ 'purge', $site_id ];

        return [ 'success' => true, 'code' => 'invalidated' ];
    }

    public function verify() {
        $this->calls[] = 'verify';

        return [ 'success' => true, 'code' => 'healthy' ];
    }
}

final class Directorist_Performance_Cache_CLI_Command_Test extends TestCase {
    protected function setUp(): void {
        WP_CLI::$lines     = [];
        WP_CLI::$successes = [];
        WP_CLI::$warnings  = [];
    }

    public function test_status_warm_purge_and_verify_delegate_and_report_structured_results() {
        $controller = new Directorist_Performance_Cache_CLI_Controller_Fixture();
        $command    = new CLI_Command( $controller, static function () {
            return 7;
        } );

        $status = $command->status( [], [] );
        $warm   = $command->warm( [ 'https://example.test/one/' ], [] );
        $purge  = $command->purge( [], [] );
        $verify = $command->verify( [], [] );

        $this->assertSame( 2, $status['queue']['pending'] );
        $this->assertSame( 'queued', $warm['code'] );
        $this->assertSame( 'invalidated', $purge['code'] );
        $this->assertSame( 'healthy', $verify['code'] );
        $this->assertSame(
            [
                'status',
                [ 'warm', [ 'https://example.test/one/' ] ],
                [ 'purge', 7 ],
                'verify',
            ],
            $controller->calls
        );
        $this->assertCount( 4, WP_CLI::$lines );
        $this->assertCount( 3, WP_CLI::$successes );
        $this->assertSame( [], WP_CLI::$warnings );
    }
}

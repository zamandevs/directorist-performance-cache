<?php
/**
 * Early hit, late capture, and invalidation behavior locks.
 */

use Directorist\Performance_Cache\Cache_Engine;
use Directorist\Performance_Cache\Cache_Storage;
use Directorist\Performance_Cache\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Cache_Engine_Test extends TestCase {
    private $root;

    private $now = 1000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/directorist-pc06-engine-' . bin2hex( random_bytes( 6 ) );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->root );
    }

    public function test_core_approved_complete_response_is_stored_and_served_on_next_request() {
        $engine = $this->engine();
        $miss   = $engine->boot_early( $this->server(), [] );

        $this->assertFalse( $miss['served'] );
        $this->assertTrue( $miss['regeneration'] );
        $this->assertSame( 'miss', $miss['code'] );
        $this->assertTrue( $engine->begin_capture()['eligible'] );

        $stored = $engine->finalize_capture(
            $this->html( 'fresh' ),
            200,
            [ 'Content-Type: text/html; charset=UTF-8', 'Content-Language: en-US' ]
        );

        $this->assertTrue( $stored['success'] );
        $this->assertSame( 'stored', $stored['code'] );

        $hit = $this->engine()->boot_early( $this->server(), [] );

        $this->assertTrue( $hit['served'] );
        $this->assertSame( 200, $hit['status'] );
        $this->assertSame( $this->html( 'fresh' ), $hit['body'] );
        $this->assertSame( 'text/html; charset=UTF-8', $hit['headers']['Content-Type'] );
        $this->assertSame( (string) strlen( $this->html( 'fresh' ) ), $hit['headers']['Content-Length'] );
        $this->assertArrayNotHasKey( 'X-Directorist-Cache', $hit['headers'] );

        $metadata_path = glob( $this->root . '/pages/*/*/*.json' )[0];
        $metadata      = json_decode( file_get_contents( $metadata_path ), true );

        $this->assertArrayHasKey( 'directorist:0:lifecycle', $metadata['generations'] );
    }

    public function test_head_and_if_modified_since_are_served_without_a_body() {
        $this->seed( 'conditional' );
        $head_server                       = $this->server( [ 'REQUEST_METHOD' => 'HEAD' ] );
        $head                              = $this->engine()->boot_early( $head_server, [] );
        $conditional_server                = $this->server();
        $conditional_server['HTTP_IF_MODIFIED_SINCE'] = gmdate( 'D, d M Y H:i:s', $this->now ) . ' GMT';
        $conditional                       = $this->engine()->boot_early( $conditional_server, [] );

        $this->assertTrue( $head['served'] );
        $this->assertSame( 200, $head['status'] );
        $this->assertSame( '', $head['body'] );
        $this->assertSame( (string) strlen( $this->html( 'conditional' ) ), $head['headers']['Content-Length'] );
        $this->assertTrue( $conditional['served'] );
        $this->assertSame( 304, $conditional['status'] );
        $this->assertSame( '', $conditional['body'] );
        $this->assertArrayNotHasKey( 'Content-Length', $conditional['headers'] );
    }

    public function test_bypass_and_core_rejection_never_capture_and_release_any_lock() {
        $bypass = $this->engine()->boot_early( $this->server( [ 'HTTP_AUTHORIZATION' => 'Bearer private' ] ), [] );

        $this->assertFalse( $bypass['served'] );
        $this->assertFalse( $bypass['regeneration'] );
        $this->assertSame( 'authorization_header', $bypass['code'] );

        $engine = $this->engine(
            [
                'core_begin' => static function () {
                    return [ 'eligible' => false, 'reason' => 'unknown_route' ];
                },
            ]
        );

        $this->assertTrue( $engine->boot_early( $this->server(), [] )['regeneration'] );
        $this->assertFalse( $engine->begin_capture()['eligible'] );

        $replacement_engine = $this->engine();
        $replacement        = $replacement_engine->boot_early( $this->server(), [] );

        $this->assertTrue( $replacement['regeneration'], 'A rejected core route must release its regeneration lock.' );
        $replacement_engine->release_request();
    }

    public function test_private_or_incomplete_response_is_not_stored_and_lock_is_released() {
        $engine = $this->engine(
            [
                'core_finish' => static function () {
                    return [
                        'eligible'     => false,
                        'reason'       => 'private_render',
                        'dependencies' => [],
                    ];
                },
            ]
        );

        $engine->boot_early( $this->server(), [] );
        $engine->begin_capture();
        $rejected = $engine->finalize_capture( '<html>partial', 200, [ 'Content-Type: text/html' ] );

        $this->assertFalse( $rejected['success'] );
        $this->assertSame( 'core_ineligible', $rejected['code'] );

        $replacement_engine = $this->engine();
        $replacement        = $replacement_engine->boot_early( $this->server(), [] );

        $this->assertTrue( $replacement['regeneration'] );
        $replacement_engine->release_request();
    }

    public function test_only_one_regenerator_owns_a_cold_key() {
        $writer = $this->engine();
        $reader = $this->engine();

        $this->assertTrue( $writer->boot_early( $this->server(), [] )['regeneration'] );
        $contended = $reader->boot_early( $this->server(), [] );

        $this->assertFalse( $contended['served'] );
        $this->assertFalse( $contended['regeneration'] );
        $this->assertSame( 'lock_contended', $contended['code'] );
        $writer->release_request();
    }

    public function test_legacy_entry_without_lifecycle_generation_is_never_served() {
        $this->seed( 'legacy', 'https://example.test/directory/', false );
        $result = $this->engine()->boot_early( $this->server(), [] );

        $this->assertFalse( $result['served'] );
        $this->assertTrue( $result['regeneration'] );
        $this->assertSame( 'legacy_entry', $result['code'] );
    }

    public function test_dependency_generation_and_exact_url_invalidation_are_composed() {
        $this->seed( 'first', 'https://example.test/directory/' );
        $this->seed( 'second', 'https://example.test/location/dhaka/' );
        $engine = $this->engine();
        $result = $engine->invalidate(
            [
                'site_id'      => 1,
                'urls'         => [ 'https://example.test/directory/' ],
                'dependencies' => [ 'directorist:1:listing:91' ],
                'generations'  => [ 'directorist:1:collection:listings' ],
                'conservative' => false,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['purged_urls'] );
        $this->assertSame( 2, $result['bumped_generations'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [] )['served'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server( [ 'REQUEST_URI' => '/location/dhaka/' ] ), [] )['served'] );
    }

    public function test_conservative_plan_bumps_the_site_generation() {
        $this->seed( 'site' );
        $result = $this->engine()->invalidate(
            [
                'site_id'      => 1,
                'urls'         => [],
                'dependencies' => [],
                'generations'  => [],
                'conservative' => true,
            ]
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['bumped_generations'] );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [] )['served'] );
    }

    public function test_lifecycle_generation_invalidates_entries_without_recursive_file_deletion() {
        $engine = $this->engine();
        $engine->boot_early( $this->server(), [] );
        $engine->begin_capture();
        $engine->finalize_capture( $this->html( 'lifecycle' ), 200, [ 'Content-Type: text/html' ] );

        $this->assertTrue( $this->engine()->boot_early( $this->server(), [] )['served'] );
        $this->assertTrue(
            $this->engine()->invalidate(
                [
                    'site_id'      => 1,
                    'urls'         => [],
                    'dependencies' => [ 'directorist:0:lifecycle' ],
                    'generations'  => [],
                    'conservative' => false,
                ]
            )['success']
        );
        $this->assertFalse( $this->engine()->boot_early( $this->server(), [] )['served'] );
    }

    public function test_debug_header_is_opt_in_and_warming_is_not_advertised_in_pc06() {
        $this->seed( 'debug' );
        $engine = $this->engine( [ 'config' => [ 'debug' => true ] ] );
        $hit    = $engine->boot_early( $this->server(), [] );

        $this->assertSame( 'HIT', $hit['headers']['X-Directorist-Cache'] );
        $this->assertFalse( $engine->supports_warm() );
        $this->assertSame( 'warming_unavailable', $engine->warm( [ 'https://example.test/directory/' ] )['code'] );
    }

    private function engine( array $overrides = [] ) {
        $config = array_merge(
            [
                'cache_dir' => $this->root,
                'ttl'       => 10,
                'stale_ttl' => 30,
                'debug'     => false,
            ],
            isset( $overrides['config'] ) ? $overrides['config'] : []
        );
        $clock = function () {
            return $this->now;
        };

        return new Cache_Engine(
            $config,
            [
                'clock'       => $clock,
                'core_begin'  => isset( $overrides['core_begin'] ) ? $overrides['core_begin'] : function () {
                    return $this->descriptor();
                },
                'core_finish' => isset( $overrides['core_finish'] ) ? $overrides['core_finish'] : function () {
                    return $this->descriptor();
                },
            ]
        );
    }

    private function seed( $marker, $url = 'https://example.test/directory/', $lifecycle = true ) {
        $key     = ( new Request_Key() )->from_url( $url );
        $storage = new Cache_Storage(
            $this->root,
            function () {
                return $this->now;
            }
        );

        $descriptor = $this->descriptor();

        if ( $lifecycle ) {
            $descriptor['dependencies'][] = 'directorist:0:lifecycle';
        }

        $this->assertTrue(
            $storage->store(
                $key,
                $this->html( $marker ),
                $descriptor,
                [ 'content-type' => 'text/html; charset=UTF-8' ],
                10,
                30
            )['success']
        );
    }

    private function descriptor() {
        return [
            'eligible'     => true,
            'reason'       => 'eligible',
            'site_id'      => 1,
            'route_type'   => 'listings',
            'cache_key'    => 'directorist:page:v1:site:1:listings',
            'dependencies' => [
                'directorist:1:site',
                'directorist:1:collection:listings',
                'directorist:1:listing:91',
            ],
        ];
    }

    private function server( array $overrides = [] ) {
        return array_merge(
            [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.test',
                'REQUEST_URI'    => '/directory/',
                'HTTPS'          => 'on',
                'SERVER_PORT'    => '443',
                'HTTP_ACCEPT'    => 'text/html,application/xhtml+xml',
            ],
            $overrides
        );
    }

    private function html( $marker ) {
        return '<!doctype html><html><body>' . $marker . '</body></html>';
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

<?php
/**
 * Physical advanced-cache.php hit and miss process behavior locks.
 */

use Directorist\Performance_Cache\Cache_Storage;
use Directorist\Performance_Cache\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Early_Engine_Process_Test extends TestCase {
    private $content_dir;

    private $cache_dir;

    protected function setUp(): void {
        $this->content_dir = sys_get_temp_dir() . '/directorist-pc06-process-' . bin2hex( random_bytes( 6 ) );
        $this->cache_dir   = $this->content_dir . '/cache/directorist-performance-cache';
        mkdir( $this->cache_dir, 0777, true );
        $this->write_config();
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->content_dir );
    }

    public function test_seeded_get_hit_exits_before_wordpress_bootstrap() {
        $body = '<!doctype html><html><body>early-hit</body></html>';
        $this->seed( $body );

        $result = $this->run_dropin();

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertSame( $body, $result['output'] );
        $this->assertStringNotContainsString( 'wordpress-booted', $result['output'] );
    }

    public function test_seeded_head_hit_exits_without_a_body() {
        $this->seed( '<!doctype html><html><body>head-hit</body></html>' );

        $result = $this->run_dropin( [ 'REQUEST_METHOD' => 'HEAD' ] );

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertSame( '', $result['output'] );
    }

    public function test_cold_miss_falls_through_to_wordpress_without_cache_output() {
        $result = $this->run_dropin( [ 'REQUEST_URI' => '/uncached/' ] );

        $this->assertSame( 0, $result['status'], $result['output'] );
        $this->assertSame( '|wordpress-booted|', $result['output'] );
    }

    private function seed( $body ) {
        $storage = new Cache_Storage( $this->cache_dir );
        $key     = ( new Request_Key() )->from_url( 'https://example.test/directory/' );
        $result  = $storage->store(
            $key,
            $body,
            [
                'eligible'     => true,
                'site_id'      => 1,
                'route_type'   => 'listings',
                'cache_key'    => 'directorist:page:v1:site:1:listings',
                'dependencies' => [ 'directorist:0:lifecycle', 'directorist:1:site' ],
            ],
            [ 'content-type' => 'text/html; charset=UTF-8' ],
            3600,
            30
        );

        $this->assertTrue( $result['success'] );
    }

    private function run_dropin( array $server = [] ) {
        $script = $this->content_dir . '/run.php';
        $dropin = DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR . '/dropin/advanced-cache.php';
        $server = array_merge(
            [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.test',
                'REQUEST_URI'    => '/directory/',
                'HTTPS'          => 'on',
                'SERVER_PORT'    => '443',
                'HTTP_ACCEPT'    => 'text/html',
            ],
            $server
        );
        $source = implode(
            '',
            [
                '<?php define("ABSPATH", ' . var_export( $this->content_dir . '/', true ) . ');',
                'define("WP_CONTENT_DIR", ' . var_export( $this->content_dir, true ) . ');',
                '$_SERVER = ' . var_export( $server, true ) . '; $_COOKIE = [];',
                'require ' . var_export( $dropin, true ) . ';',
                'echo "|wordpress-booted|";',
            ]
        );
        file_put_contents( $script, $source );

        $output = [];
        $status = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' -d xdebug.mode=off ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );

        return [
            'status' => $status,
            'output' => implode( "\n", $output ),
        ];
    }

    private function write_config() {
        $config = [
            '_marker'        => 'DIRECTORIST PAGE CACHE CONFIG',
            'owner_id'       => 'Owner-ID: directorist-performance-cache',
            'schema'         => 1,
            'owner'          => 'directorist-performance-cache',
            'plugin_dir'     => DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR,
            'bootstrap_file' => DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR . '/src/early-bootstrap.php',
            'engine_file'    => DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR . '/src/class-cache-engine.php',
            'cache_dir'      => $this->cache_dir,
            'ttl'            => 3600,
            'stale_ttl'      => 30,
            'debug'          => false,
        ];

        file_put_contents( $this->cache_dir . '/config.json', json_encode( $config ) );
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

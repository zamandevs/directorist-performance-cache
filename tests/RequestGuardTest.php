<?php
/**
 * Early request guard and canonical cache-key behavior locks.
 */

use Directorist\Performance_Cache\Request_Guard;
use Directorist\Performance_Cache\Request_Key;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Request_Guard_Test extends TestCase {
    public function test_anonymous_html_get_and_head_requests_are_early_candidates() {
        $guard = new Request_Guard();

        foreach ( [ 'GET', 'HEAD' ] as $method ) {
            $result = $guard->evaluate( $this->server( [ 'REQUEST_METHOD' => $method ] ), [] );

            $this->assertTrue( $result['eligible'], $method );
            $this->assertSame( 'candidate', $result['code'] );
            $this->assertSame( 'https://example.test/directory/', $result['request']['canonical_url'] );
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['request']['hash'] );
        }
    }

    /**
     * @dataProvider rejected_request_provider
     */
    public function test_unsafe_early_requests_are_rejected( $server, $cookies, $code ) {
        $result = ( new Request_Guard() )->evaluate( $this->server( $server ), $cookies );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( $code, $result['code'] );
    }

    public function rejected_request_provider() {
        return [
            'POST'                => [ [ 'REQUEST_METHOD' => 'POST' ], [], 'unsafe_method' ],
            'authorization'       => [ [ 'HTTP_AUTHORIZATION' => 'Bearer private' ], [], 'authorization_header' ],
            'no-store'            => [ [ 'HTTP_CACHE_CONTROL' => 'no-store' ], [], 'bypass_header' ],
            'pragma'              => [ [ 'HTTP_PRAGMA' => 'no-cache' ], [], 'bypass_header' ],
            'REST nonce'          => [ [ 'HTTP_X_WP_NONCE' => 'private' ], [], 'bypass_header' ],
            'XHR'                 => [ [ 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest' ], [], 'bypass_header' ],
            'JSON accept'         => [ [ 'HTTP_ACCEPT' => 'application/json' ], [], 'non_html_accept' ],
            'cookie map'          => [ [], [ '_ga' => 'still-varied' ], 'cookie_present' ],
            'raw cookie header'   => [ [ 'HTTP_COOKIE' => 'unknown=value' ], [], 'cookie_present' ],
            'admin'               => [ [ 'REQUEST_URI' => '/wp-admin/edit.php' ], [], 'private_path' ],
            'login'               => [ [ 'REQUEST_URI' => '/wp-login.php' ], [], 'private_path' ],
            'REST path'           => [ [ 'REQUEST_URI' => '/wp-json/wp/v2/posts' ], [], 'private_path' ],
            'nonce query'         => [ [ 'REQUEST_URI' => '/directory/?_wpnonce=private' ], [], 'private_query' ],
            'security query'      => [ [ 'REQUEST_URI' => '/directory/?security=private' ], [], 'private_query' ],
            'preview query'       => [ [ 'REQUEST_URI' => '/directory/?preview=true' ], [], 'private_query' ],
            'fragment'            => [ [ 'REQUEST_URI' => '/directory/#fragment' ], [], 'invalid_request_target' ],
            'control byte'        => [ [ 'REQUEST_URI' => "/directory/\r\nX-Test: injected" ], [], 'invalid_request_target' ],
            'encoded traversal'   => [ [ 'REQUEST_URI' => '/directory/%2e%2e/private/' ], [], 'invalid_request_target' ],
            'encoded slash'       => [ [ 'REQUEST_URI' => '/directory/%2fprivate/' ], [], 'invalid_request_target' ],
            'malformed encoding'  => [ [ 'REQUEST_URI' => '/directory/%zz/' ], [], 'invalid_request_target' ],
            'host injection'      => [ [ 'HTTP_HOST' => "example.test\r\nX-Test: injected" ], [], 'invalid_host' ],
            'host userinfo'       => [ [ 'HTTP_HOST' => 'user@example.test' ], [], 'invalid_host' ],
        ];
    }

    public function test_key_normalizes_scheme_host_default_port_and_unreserved_encoding() {
        $key    = new Request_Key();
        $first  = $key->from_server( $this->server( [ 'HTTP_HOST' => 'EXAMPLE.TEST:443', 'REQUEST_URI' => '/directory/%7eplace/' ] ) );
        $second = $key->from_server( $this->server( [ 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/directory/~place/' ] ) );
        $http   = $key->from_server( $this->server( [ 'HTTPS' => 'off', 'SERVER_PORT' => '80' ] ) );

        $this->assertTrue( $first['success'] );
        $this->assertSame( 'https://example.test/directory/~place/', $first['canonical_url'] );
        $this->assertSame( $first['hash'], $second['hash'] );
        $this->assertNotSame( $second['hash'], $http['hash'] );
    }

    public function test_url_and_server_key_paths_share_one_canonicalizer() {
        $key     = new Request_Key();
        $server  = $key->from_server( $this->server( [ 'REQUEST_URI' => '/directory/?q=hotel' ] ) );
        $url     = $key->from_url( 'https://example.test/directory/?q=hotel' );
        $foreign = $key->from_url( 'ftp://example.test/directory/' );

        $this->assertSame( $server['hash'], $url['hash'] );
        $this->assertFalse( $foreign['success'] );
        $this->assertSame( 'invalid_scheme', $foreign['code'] );
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
}

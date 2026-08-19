<?php
/**
 * Late response acceptance behavior locks.
 */

use Directorist\Performance_Cache\Response_Validator;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Response_Validator_Test extends TestCase {
    public function test_complete_public_html_response_is_accepted_with_safe_headers_only() {
        $result = ( new Response_Validator() )->validate(
            '<!doctype html><html><body>Directory</body></html>',
            200,
            [ 'Content-Type: text/html; charset=UTF-8', 'X-Unrelated: private-value' ],
            $this->descriptor()
        );

        $this->assertTrue( $result['accepted'] );
        $this->assertSame( 'accepted', $result['code'] );
        $this->assertSame( [ 'content-type' => 'text/html; charset=UTF-8' ], $result['headers'] );
    }

    /**
     * @dataProvider rejected_response_provider
     */
    public function test_unsafe_or_incomplete_response_is_rejected( $body, $status, $headers, $descriptor, $code ) {
        $result = ( new Response_Validator() )->validate( $body, $status, $headers, $descriptor );

        $this->assertFalse( $result['accepted'] );
        $this->assertSame( $code, $result['code'] );
    }

    public function rejected_response_provider() {
        return [
            'private descriptor' => [ $this->html(), 200, [ 'Content-Type: text/html' ], $this->descriptor( [ 'eligible' => false ] ), 'core_ineligible' ],
            'empty dependencies' => [ $this->html(), 200, [ 'Content-Type: text/html' ], $this->descriptor( [ 'dependencies' => [] ] ), 'missing_dependencies' ],
            'redirect'           => [ $this->html(), 302, [ 'Location: /login/' ], $this->descriptor(), 'invalid_status' ],
            'not found'          => [ $this->html(), 404, [ 'Content-Type: text/html' ], $this->descriptor(), 'invalid_status' ],
            'JSON'               => [ '{"ok":true}', 200, [ 'Content-Type: application/json' ], $this->descriptor(), 'invalid_content_type' ],
            'set cookie'         => [ $this->html(), 200, [ 'Content-Type: text/html', 'Set-Cookie: session=private' ], $this->descriptor(), 'set_cookie' ],
            'private control'    => [ $this->html(), 200, [ 'Content-Type: text/html', 'Cache-Control: private, no-store' ], $this->descriptor(), 'private_cache_control' ],
            'location header'    => [ $this->html(), 200, [ 'Content-Type: text/html', 'Location: /other/' ], $this->descriptor(), 'location_header' ],
            'blank'              => [ '', 200, [ 'Content-Type: text/html' ], $this->descriptor(), 'empty_body' ],
            'fragment'           => [ '<div>card</div>', 200, [ 'Content-Type: text/html' ], $this->descriptor(), 'incomplete_html' ],
            'too many deps'      => [ $this->html(), 200, [ 'Content-Type: text/html' ], $this->descriptor( [ 'dependencies' => array_fill( 0, 513, 'directorist:1:listing:1' ) ] ), 'too_many_dependencies' ],
            'invalid dep'        => [ $this->html(), 200, [ 'Content-Type: text/html' ], $this->descriptor( [ 'dependencies' => [ '../foreign' ] ] ), 'invalid_dependency' ],
            'header control'     => [ $this->html(), 200, [ "Content-Type: text/html\r\nSet-Cookie: private=1" ], $this->descriptor(), 'invalid_header' ],
            'no-cache fields'    => [ $this->html(), 200, [ 'Content-Type: text/html', 'Cache-Control: public, no-cache="Set-Cookie"' ], $this->descriptor(), 'private_cache_control' ],
        ];
    }

    private function html() {
        return '<!doctype html><html><body>Directory</body></html>';
    }

    private function descriptor( array $overrides = [] ) {
        return array_merge(
            [
                'eligible'     => true,
                'reason'       => 'eligible',
                'site_id'      => 1,
                'route_type'   => 'listings',
                'cache_key'    => 'directorist:page:v1:site:1:' . str_repeat( 'a', 64 ),
                'dependencies' => [ 'directorist:1:site', 'directorist:1:collection:listings' ],
            ],
            $overrides
        );
    }
}

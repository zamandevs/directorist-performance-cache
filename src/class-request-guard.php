<?php

namespace Directorist\Performance_Cache;

/**
 * Conservative pre-WordPress request guard.
 */
final class Request_Guard {
    /** @var Request_Key */
    private $request_key;

    /**
     * @param Request_Key|null $request_key URL canonicalizer.
     */
    public function __construct( Request_Key $request_key = null ) {
        $this->request_key = $request_key ?: new Request_Key();
    }

    /**
     * @param array $server HTTP server values.
     * @param array $cookies Parsed cookies.
     * @return array
     */
    public function evaluate( array $server, array $cookies = [] ) {
        $method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( trim( (string) $server['REQUEST_METHOD'] ) ) : '';

        if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
            return $this->result( false, 'unsafe_method' );
        }

        if ( $this->has_header( $server, [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] ) ) {
            return $this->result( false, 'authorization_header' );
        }

        $cache_control = $this->header( $server, 'HTTP_CACHE_CONTROL' );

        foreach ( [ 'no-cache', 'no-store', 'max-age=0' ] as $directive ) {
            if ( false !== strpos( strtolower( $cache_control ), $directive ) ) {
                return $this->result( false, 'bypass_header' );
            }
        }

        if ( false !== strpos( strtolower( $this->header( $server, 'HTTP_PRAGMA' ) ), 'no-cache' ) || $this->has_header( $server, [ 'HTTP_X_WP_NONCE' ] ) || 'xmlhttprequest' === strtolower( $this->header( $server, 'HTTP_X_REQUESTED_WITH' ) ) ) {
            return $this->result( false, 'bypass_header' );
        }

        $accept = strtolower( $this->header( $server, 'HTTP_ACCEPT' ) );

        if ( '' !== $accept && false === strpos( $accept, 'text/html' ) && false === strpos( $accept, 'application/xhtml+xml' ) && false === strpos( $accept, '*/*' ) ) {
            return $this->result( false, 'non_html_accept' );
        }

        if ( ! empty( $cookies ) || '' !== trim( $this->header( $server, 'HTTP_COOKIE' ) ) ) {
            return $this->result( false, 'cookie_present' );
        }

        $request = $this->request_key->from_server( $server );

        if ( empty( $request['success'] ) ) {
            return $this->result( false, $request['code'] );
        }

        if ( $this->is_private_path( $request['path'] ) ) {
            return $this->result( false, 'private_path' );
        }

        if ( $this->has_private_query( $request['query'] ) ) {
            return $this->result( false, 'private_query' );
        }

        $result            = $this->result( true, 'candidate' );
        $result['request'] = $request;
        $result['method']  = $method;

        return $result;
    }

    /**
     * @param string $path Canonical path.
     * @return bool
     */
    private function is_private_path( $path ) {
        $path = strtolower( $path );

        foreach ( [ '/wp-admin', '/wp-login.php', '/wp-cron.php', '/wp-json', '/xmlrpc.php', '/wp-comments-post.php' ] as $private_path ) {
            if ( $private_path === $path || 0 === strpos( $path, $private_path . '/' ) || 0 === strpos( $path, $private_path . '?' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $query Canonical raw query.
     * @return bool
     */
    private function has_private_query( $query ) {
        if ( '' === $query ) {
            return false;
        }

        foreach ( explode( '&', $query ) as $pair ) {
            $name = strtolower( rawurldecode( str_replace( '+', ' ', explode( '=', $pair, 2 )[0] ) ) );
            $name = preg_replace( '/\[.*$/', '', $name );

            if ( false !== strpos( $name, 'nonce' ) || in_array( $name, [ 'security', 'preview', 'customize_changeset_uuid', 'rest_route' ], true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array    $server HTTP server values.
     * @param string[] $names Header server keys.
     * @return bool
     */
    private function has_header( array $server, array $names ) {
        foreach ( $names as $name ) {
            if ( '' !== trim( $this->header( $server, $name ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array  $server HTTP server values.
     * @param string $name Header server key.
     * @return string
     */
    private function header( array $server, $name ) {
        return isset( $server[ $name ] ) && is_scalar( $server[ $name ] ) ? (string) $server[ $name ] : '';
    }

    /**
     * @param bool   $eligible Candidate state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $eligible, $code ) {
        return [
            'eligible' => (bool) $eligible,
            'code'     => $code,
            'request'  => [],
            'method'   => '',
        ];
    }
}

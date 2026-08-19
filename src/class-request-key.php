<?php

namespace Directorist\Performance_Cache;

/**
 * Canonicalizes public HTTP URLs without WordPress bootstrap dependencies.
 */
final class Request_Key {
    const MAX_TARGET_BYTES = 8192;

    /**
     * @param array $server HTTP server variables.
     * @return array
     */
    public function from_server( array $server ) {
        $https  = isset( $server['HTTPS'] ) ? strtolower( (string) $server['HTTPS'] ) : '';
        $scheme = in_array( $https, [ '1', 'on' ], true ) || ( isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'] ) ? 'https' : 'http';
        $host   = isset( $server['HTTP_HOST'] ) ? $server['HTTP_HOST'] : ( isset( $server['SERVER_NAME'] ) ? $server['SERVER_NAME'] : '' );
        $target = isset( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '';

        return $this->canonicalize( $scheme, $host, $target );
    }

    /**
     * @param string $url Absolute HTTP URL.
     * @return array
     */
    public function from_url( $url ) {
        if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
            return $this->failure( 'invalid_url' );
        }

        $parts = parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return $this->failure( 'invalid_url' );
        }

        $scheme = strtolower( (string) $parts['scheme'] );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return $this->failure( 'invalid_scheme' );
        }

        $host = false !== strpos( $parts['host'], ':' ) ? '[' . $parts['host'] . ']' : $parts['host'];

        if ( isset( $parts['port'] ) ) {
            $host .= ':' . $parts['port'];
        }

        $target = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

        if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
            $target .= '?' . $parts['query'];
        }

        return $this->canonicalize( $scheme, $host, $target );
    }

    /**
     * @param string $scheme HTTP scheme.
     * @param mixed  $authority Host and optional port.
     * @param mixed  $target Request target.
     * @return array
     */
    private function canonicalize( $scheme, $authority, $target ) {
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return $this->failure( 'invalid_scheme' );
        }

        $authority = $this->normalize_authority( $authority, $scheme );

        if ( false === $authority ) {
            return $this->failure( 'invalid_host' );
        }

        $target = $this->normalize_target( $target );

        if ( false === $target ) {
            return $this->failure( 'invalid_request_target' );
        }

        $canonical_url = $scheme . '://' . $authority . $target;

        return [
            'success'       => true,
            'code'          => 'keyed',
            'canonical_url' => $canonical_url,
            'hash'          => hash( 'sha256', $canonical_url ),
            'path'          => false === strpos( $target, '?' ) ? $target : explode( '?', $target, 2 )[0],
            'query'         => false === strpos( $target, '?' ) ? '' : explode( '?', $target, 2 )[1],
        ];
    }

    /**
     * @param mixed  $authority Host and optional port.
     * @param string $scheme HTTP scheme.
     * @return string|false
     */
    private function normalize_authority( $authority, $scheme ) {
        if ( ! is_scalar( $authority ) ) {
            return false;
        }

        $authority = (string) $authority;

        if ( '' === $authority || 255 < strlen( $authority ) || preg_match( '~[^\x21-\x7e]|[\\\\/@?#]~', $authority ) ) {
            return false;
        }

        $host = '';
        $port = null;

        if ( '[' === substr( $authority, 0, 1 ) ) {
            if ( ! preg_match( '/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/i', $authority, $matches ) || ! filter_var( $matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                return false;
            }

            $host = '[' . strtolower( $matches[1] ) . ']';
            $port = isset( $matches[2] ) ? (int) $matches[2] : null;
        } else {
            if ( 1 < substr_count( $authority, ':' ) || ! preg_match( '/^([^:]+)(?::([0-9]{1,5}))?$/', $authority, $matches ) ) {
                return false;
            }

            $host = strtolower( rtrim( $matches[1], '.' ) );
            $port = isset( $matches[2] ) ? (int) $matches[2] : null;

            if ( '' === $host || 253 < strlen( $host ) ) {
                return false;
            }

            foreach ( explode( '.', $host ) as $label ) {
                if ( '' === $label || 63 < strlen( $label ) || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label ) ) {
                    return false;
                }
            }
        }

        if ( null !== $port && ( 1 > $port || 65535 < $port ) ) {
            return false;
        }

        if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
            $port = null;
        }

        return $host . ( null === $port ? '' : ':' . $port );
    }

    /**
     * @param mixed $target Request target.
     * @return string|false
     */
    private function normalize_target( $target ) {
        if ( ! is_scalar( $target ) ) {
            return false;
        }

        $target = (string) $target;

        if ( '' === $target || self::MAX_TARGET_BYTES < strlen( $target ) || '/' !== substr( $target, 0, 1 ) || preg_match( '~[\x00-\x20\x7f\\\\#]~', $target ) || preg_match( '/%(?![0-9a-f]{2})/i', $target ) ) {
            return false;
        }

        $parts = explode( '?', $target, 2 );
        $path  = $parts[0];
        $query = isset( $parts[1] ) ? $parts[1] : '';

        foreach ( explode( '/', $path ) as $segment ) {
            if ( ! $this->is_safe_segment( $segment ) ) {
                return false;
            }
        }

        if ( '' !== $query && ( preg_match( '~[\x00-\x20\x7f\\\\#]~', $query ) || preg_match( '/%(?![0-9a-f]{2})/i', $query ) ) ) {
            return false;
        }

        $path = $this->normalize_percent_encoding( $path );

        return $path . ( '' === $query ? '' : '?' . $this->normalize_percent_encoding( $query ) );
    }

    /**
     * @param string $segment Encoded path segment.
     * @return bool
     */
    private function is_safe_segment( $segment ) {
        $decoded = $segment;

        for ( $depth = 0; $depth < 3; ++$depth ) {
            $next = rawurldecode( $decoded );

            if ( preg_match( '~[\x00-\x1f\x7f\\\\/]~', $next ) || in_array( $next, [ '.', '..' ], true ) ) {
                return false;
            }

            if ( $next === $decoded ) {
                break;
            }

            $decoded = $next;
        }

        return true;
    }

    /**
     * @param string $value Encoded URL component.
     * @return string
     */
    private function normalize_percent_encoding( $value ) {
        return preg_replace_callback(
            '/%([0-9a-f]{2})/i',
            static function ( $matches ) {
                $decoded = chr( hexdec( $matches[1] ) );

                return preg_match( '/[a-z0-9\-._~]/i', $decoded ) ? $decoded : '%' . strtoupper( $matches[1] );
            },
            $value
        );
    }

    /**
     * @param string $code Failure code.
     * @return array
     */
    private function failure( $code ) {
        return [
            'success'       => false,
            'code'          => $code,
            'canonical_url' => '',
            'hash'          => '',
            'path'          => '',
            'query'         => '',
        ];
    }
}

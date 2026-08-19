<?php

namespace Directorist\Performance_Cache;

/**
 * Accepts only complete non-private Directorist HTML responses.
 */
final class Response_Validator {
    const MAX_DEPENDENCIES = 512;
    const MAX_BODY_BYTES   = 10485760;

    /**
     * @param string $body Complete response body.
     * @param int    $status HTTP status.
     * @param array  $headers Response header lines.
     * @param array  $descriptor Final core descriptor.
     * @return array
     */
    public function validate( $body, $status, array $headers, array $descriptor ) {
        if ( empty( $descriptor['eligible'] ) ) {
            return $this->result( false, 'core_ineligible' );
        }

        $dependencies = isset( $descriptor['dependencies'] ) && is_array( $descriptor['dependencies'] ) ? $descriptor['dependencies'] : [];

        if ( empty( $dependencies ) ) {
            return $this->result( false, 'missing_dependencies' );
        }

        if ( self::MAX_DEPENDENCIES < count( $dependencies ) ) {
            return $this->result( false, 'too_many_dependencies' );
        }

        foreach ( $dependencies as $dependency ) {
            if ( ! is_string( $dependency ) || ! preg_match( '/^directorist:[0-9]+:[a-z0-9-]+(?::[a-z0-9-]+)*$/', $dependency ) ) {
                return $this->result( false, 'invalid_dependency' );
            }
        }

        if ( 200 !== (int) $status ) {
            return $this->result( false, 'invalid_status' );
        }

        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            return $this->result( false, 'do_not_cache' );
        }

        if ( ! is_string( $body ) || '' === $body ) {
            return $this->result( false, 'empty_body' );
        }

        if ( self::MAX_BODY_BYTES < strlen( $body ) ) {
            return $this->result( false, 'body_too_large' );
        }

        $normalized = $this->normalize_headers( $headers );

        if ( false === $normalized ) {
            return $this->result( false, 'invalid_header' );
        }

        if ( isset( $normalized['set-cookie'] ) ) {
            return $this->result( false, 'set_cookie' );
        }

        if ( isset( $normalized['location'] ) ) {
            return $this->result( false, 'location_header' );
        }

        if ( isset( $normalized['cache-control'] ) && preg_match( '/(?:^|,)\s*(?:private|no-store|no-cache)(?:\s*=|\s|,|$)/i', $normalized['cache-control'] ) ) {
            return $this->result( false, 'private_cache_control' );
        }

        $content_type = isset( $normalized['content-type'] ) ? strtolower( $normalized['content-type'] ) : '';

        if ( 0 !== strpos( $content_type, 'text/html' ) && 0 !== strpos( $content_type, 'application/xhtml+xml' ) ) {
            return $this->result( false, 'invalid_content_type' );
        }

        if ( false === stripos( $body, '<html' ) || false === stripos( $body, '</html>' ) ) {
            return $this->result( false, 'incomplete_html' );
        }

        $safe_headers = [ 'content-type' => $normalized['content-type'] ];

        if ( isset( $normalized['content-language'] ) ) {
            $safe_headers['content-language'] = $normalized['content-language'];
        }

        return $this->result( true, 'accepted', $safe_headers );
    }

    /**
     * @param array $headers Header lines.
     * @return array|false
     */
    private function normalize_headers( array $headers ) {
        $normalized = [];

        foreach ( $headers as $header ) {
            if ( ! is_string( $header ) || false === strpos( $header, ':' ) ) {
                continue;
            }

            list( $name, $value ) = explode( ':', $header, 2 );
            $name                 = strtolower( trim( $name ) );
            $value                = trim( $value );

            if ( '' === $name || ! preg_match( '/^[a-z0-9!#$%&\'*+.^_`|~-]+$/', $name ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
                return false;
            }

            $normalized[ $name ] = $value;
        }

        return $normalized;
    }

    /**
     * @param bool   $accepted Acceptance state.
     * @param string $code Stable code.
     * @param array  $headers Replay-safe headers.
     * @return array
     */
    private function result( $accepted, $code, array $headers = [] ) {
        return [
            'accepted' => (bool) $accepted,
            'code'     => $code,
            'headers'  => $headers,
        ];
    }
}

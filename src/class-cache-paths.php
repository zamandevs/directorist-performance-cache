<?php

namespace Directorist\Performance_Cache;

/**
 * Derives contained filesystem paths from fixed SHA-256 material only.
 */
final class Cache_Paths {
    /** @var string */
    private $root;

    /**
     * @param string $root Directorist cache root.
     */
    public function __construct( $root ) {
        $root        = is_string( $root ) ? rtrim( $root, '/\\' ) : '';
        $is_absolute = '/' === substr( $root, 0, 1 ) || (bool) preg_match( '~^[a-z]:[\\\\/]~i', $root );
        $this->root  = $is_absolute && ! in_array( $root, [ '', '/', '.', '..' ], true ) && false === strpos( $root, "\0" ) ? $root : '';
    }

    /** @return string */
    public function root() {
        return $this->root;
    }

    /**
     * @param string $hash Request hash.
     * @return array
     */
    public function entry( $hash ) {
        if ( '' === $this->root || ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            return [];
        }

        $directory = $this->root . '/pages/' . substr( $hash, 0, 2 ) . '/' . substr( $hash, 2, 2 );

        return [
            'body'     => $directory . '/' . $hash . '.body',
            'metadata' => $directory . '/' . $hash . '.json',
            'lock'     => $this->root . '/locks/' . substr( $hash, 0, 2 ) . '/' . $hash . '.lock',
        ];
    }

    /**
     * @param string $dependency Dependency key.
     * @return string
     */
    public function generation( $dependency ) {
        if ( '' === $this->root || ! is_string( $dependency ) || ! preg_match( '/^directorist:[0-9]+:[a-z0-9-]+(?::[a-z0-9-]+)*$/', $dependency ) ) {
            return '';
        }

        $hash = hash( 'sha256', $dependency );

        return $this->root . '/generations/' . substr( $hash, 0, 2 ) . '/' . $hash . '.gen';
    }
}

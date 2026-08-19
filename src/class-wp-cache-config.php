<?php

namespace Directorist\Performance_Cache;

/**
 * Manages only the exact WP_CACHE block owned by the companion.
 */
final class WP_Cache_Config {
    const BLOCK_BEGIN = '/* DIRECTORIST PERFORMANCE CACHE WP_CACHE BEGIN */';
    const BLOCK_END   = '/* DIRECTORIST PERFORMANCE CACHE WP_CACHE END */';

    /** @var string */
    private $path;

    /** @var Atomic_Writer */
    private $writer;

    /**
     * @param string             $path wp-config.php path.
     * @param Atomic_Writer|null $writer Atomic writer.
     */
    public function __construct( $path, Atomic_Writer $writer = null ) {
        $this->path   = (string) $path;
        $this->writer = $writer ?: new Atomic_Writer();
    }

    /** @return array */
    public function enable() {
        if ( is_link( $this->path ) ) {
            return $this->result( false, 'foreign_symlink' );
        }

        if ( ! is_file( $this->path ) || ! is_readable( $this->path ) ) {
            return $this->result( false, 'wp_config_unreadable' );
        }

        $source      = file_get_contents( $this->path );
        $definitions = $this->definitions( $source );

        if ( count( $definitions ) > 1 || ( 1 === count( $definitions ) && 'true' !== $definitions[0] ) ) {
            return $this->result( false, 'conflicting_wp_cache' );
        }

        if ( 1 === count( $definitions ) ) {
            return $this->result( true, $this->owns_block( $source ) ? 'already_enabled_owned' : 'already_enabled_external' );
        }

        if ( false === strpos( $source, '<?php' ) ) {
            return $this->result( false, 'wp_config_malformed' );
        }

        $source = preg_replace( '/<\?php\s*/', "<?php\n" . $this->block() . "\n\n", $source, 1 );
        $result = $this->writer->write( $this->path, $source );

        return empty( $result['success'] ) ? $result : $this->result( true, 'enabled' );
    }

    /** @return array */
    public function disable() {
        if ( is_link( $this->path ) ) {
            return $this->result( false, 'foreign_symlink' );
        }

        if ( ! is_file( $this->path ) || ! is_readable( $this->path ) ) {
            return $this->result( false, 'wp_config_unreadable' );
        }

        $source = file_get_contents( $this->path );

        if ( ! $this->owns_block( $source ) ) {
            return $this->result( true, 'not_owned' );
        }

        $block_position = strpos( $source, $this->block() );
        $block_length   = strlen( $this->block() );
        $after          = substr( $source, $block_position + $block_length );

        if ( 0 === strpos( $after, "\r\n\r\n" ) ) {
            $block_length += 4;
        } elseif ( 0 === strpos( $after, "\n\n" ) ) {
            $block_length += 2;
        } elseif ( 0 === strpos( $after, "\r\n" ) ) {
            $block_length += 2;
        } elseif ( 0 === strpos( $after, "\n" ) ) {
            ++$block_length;
        }

        $source = substr_replace( $source, '', $block_position, $block_length );
        $result = $this->writer->write( $this->path, $source );

        return empty( $result['success'] ) ? $result : $this->result( true, 'removed' );
    }

    /**
     * @param string $source wp-config source.
     * @return string[]
     */
    private function definitions( $source ) {
        $source = $this->without_comments( $source );
        preg_match_all( '/define\s*\(\s*([\'\"])WP_CACHE\1\s*,\s*([^\)]+)\)/i', $source, $matches );
        $values = [];

        foreach ( isset( $matches[2] ) ? $matches[2] : [] as $value ) {
            $value    = strtolower( trim( $value ) );
            $values[] = in_array( $value, [ 'true', 'false' ], true ) ? $value : 'unknown';
        }

        return $values;
    }

    /**
     * @param string $source PHP source.
     * @return string
     */
    private function without_comments( $source ) {
        $clean = '';

        foreach ( token_get_all( $source ) as $token ) {
            if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                continue;
            }

            $clean .= is_array( $token ) ? $token[1] : $token;
        }

        return $clean;
    }

    /**
     * @param string $source wp-config source.
     * @return bool
     */
    private function owns_block( $source ) {
        return 1 === substr_count( $source, $this->block() );
    }

    /** @return string */
    private function block() {
        return self::BLOCK_BEGIN . "\ndefine( 'WP_CACHE', true );\n" . self::BLOCK_END;
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success' => (bool) $success,
            'code'    => $code,
            'path'    => $this->path,
        ];
    }
}

<?php

namespace Directorist\Performance_Cache;

/**
 * Reads and atomically updates the owned early-loader configuration.
 */
final class Early_Config_Manager {
    const MAX_BYTES = 32768;

    /** @var string */
    private $path;

    /** @var Atomic_Writer */
    private $writer;

    /**
     * @param string             $path Generated config path.
     * @param Atomic_Writer|null $writer Atomic writer.
     */
    public function __construct( $path, Atomic_Writer $writer = null ) {
        $this->path   = (string) $path;
        $this->writer = $writer ?: new Atomic_Writer();
    }

    /** @return array */
    public function status() {
        $read = $this->read();

        if ( empty( $read['success'] ) ) {
            return $read;
        }

        return [
            'success' => true,
            'code'    => ! empty( $read['config']['enabled'] ) || ! array_key_exists( 'enabled', $read['config'] ) ? 'config_enabled' : 'config_disabled',
            'enabled' => ! array_key_exists( 'enabled', $read['config'] ) || ! empty( $read['config']['enabled'] ),
            'path'    => $this->path,
        ];
    }

    /**
     * @param bool $enabled Desired early-cache state.
     * @return array
     */
    public function set_enabled( $enabled ) {
        $read = $this->read();

        if ( empty( $read['success'] ) ) {
            return $read;
        }

        $enabled = (bool) $enabled;
        $current = ! array_key_exists( 'enabled', $read['config'] ) || ! empty( $read['config']['enabled'] );

        if ( $current === $enabled ) {
            return [
                'success' => true,
                'code'    => $enabled ? 'enabled' : 'disabled',
                'enabled' => $enabled,
                'changed' => false,
                'path'    => $this->path,
            ];
        }

        $config            = $read['config'];
        $config['enabled'] = $enabled;
        $encoded           = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! is_string( $encoded ) || self::MAX_BYTES < strlen( $encoded ) ) {
            return $this->result( false, 'config_encode_failed' );
        }

        $written = $this->writer->write( $this->path, $encoded . "\n" );

        if ( empty( $written['success'] ) ) {
            return $this->result( false, isset( $written['code'] ) ? $written['code'] : 'config_write_failed' );
        }

        return [
            'success' => true,
            'code'    => $enabled ? 'enabled' : 'disabled',
            'enabled' => $enabled,
            'changed' => true,
            'path'    => $this->path,
        ];
    }

    /** @return array */
    private function read() {
        if ( is_link( $this->path ) ) {
            return $this->result( false, 'config_symlink' );
        }

        if ( ! file_exists( $this->path ) ) {
            return $this->result( false, 'config_missing' );
        }

        if ( ! is_file( $this->path ) || ! is_readable( $this->path ) ) {
            return $this->result( false, 'config_unreadable' );
        }

        $size = filesize( $this->path );

        if ( false === $size || self::MAX_BYTES < $size ) {
            return $this->result( false, 'config_invalid' );
        }

        $source = file_get_contents( $this->path );
        $config = is_string( $source ) ? json_decode( $source, true ) : null;

        if ( ! Ownership::owns_config( $this->path ) ) {
            return $this->result( false, 'config_foreign' );
        }

        if ( ! Early_Config::is_valid( $config ) ) {
            return $this->result( false, 'config_invalid' );
        }

        return [
            'success' => true,
            'code'    => 'config_owned',
            'config'  => $config,
            'path'    => $this->path,
        ];
    }

    /**
     * @param bool   $success Result state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success' => (bool) $success,
            'code'    => (string) $code,
            'enabled' => false,
            'path'    => $this->path,
        ];
    }
}

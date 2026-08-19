<?php

namespace Directorist\Performance_Cache;

/**
 * Owns only the companion drop-in and generated early config.
 */
final class Dropin_Installer {
    /** @var string */
    private $content_dir;

    /** @var string */
    private $plugin_dir;

    /** @var Atomic_Writer */
    private $writer;

    /** @var string */
    private $dropin_source_path;

    /**
     * @param string             $content_dir WordPress content directory.
     * @param string             $plugin_dir Companion plugin directory.
     * @param Atomic_Writer|null $writer Atomic writer.
     * @param string|null        $dropin_source_path Packaged drop-in source.
     */
    public function __construct( $content_dir, $plugin_dir, Atomic_Writer $writer = null, $dropin_source_path = null ) {
        $this->content_dir        = rtrim( (string) $content_dir, '/\\' );
        $this->plugin_dir         = rtrim( (string) $plugin_dir, '/\\' );
        $this->writer             = $writer ?: new Atomic_Writer();
        $this->dropin_source_path = null === $dropin_source_path ? dirname( __DIR__ ) . '/dropin/advanced-cache.php' : (string) $dropin_source_path;
    }

    /** @return array */
    public function preflight() {
        $state = Ownership::classify_dropin( $this->dropin_path() );

        if ( ! in_array( $state, [ 'missing', 'owned' ], true ) ) {
            return $this->result( false, 'foreign' === $state ? 'foreign_dropin' : $state );
        }

        if ( file_exists( $this->config_path() ) && ! Ownership::owns_config( $this->config_path() ) ) {
            return $this->result( false, is_link( $this->config_path() ) ? 'foreign_symlink' : 'foreign_config' );
        }

        $result          = $this->result( true, 'ready' );
        $result['state'] = $state;

        return $result;
    }

    /** @return array */
    public function install() {
        $preflight = $this->preflight();

        if ( empty( $preflight['success'] ) ) {
            return $preflight;
        }

        $state = $preflight['state'];

        $dropin_source = $this->dropin_source();

        if ( false === $dropin_source ) {
            return $this->result( false, 'dropin_source_unreadable' );
        }

        $config_source = ( new Early_Config( $this->content_dir, $this->plugin_dir ) )->render();

        if ( '' === $config_source ) {
            return $this->result( false, 'generated_config_failed' );
        }

        $previous_config = is_file( $this->config_path() ) ? file_get_contents( $this->config_path() ) : false;
        $config_result   = $this->writer->write( $this->config_path(), $config_source );

        if ( empty( $config_result['success'] ) ) {
            return $config_result;
        }

        $dropin_result = $this->writer->write( $this->dropin_path(), $dropin_source );

        if ( empty( $dropin_result['success'] ) ) {
            $this->restore_config( $previous_config );

            return $dropin_result;
        }

        return $this->result( true, 'owned' === $state ? 'upgraded' : 'installed' );
    }

    /** @return array */
    public function remove() {
        $state = Ownership::classify_dropin( $this->dropin_path() );

        if ( ! in_array( $state, [ 'missing', 'owned' ], true ) ) {
            return $this->result( false, 'foreign' === $state ? 'foreign_dropin' : $state );
        }

        if ( 'owned' === $state ) {
            $dropin_result = $this->writer->remove( $this->dropin_path() );

            if ( empty( $dropin_result['success'] ) ) {
                return $dropin_result;
            }
        }

        if ( Ownership::owns_config( $this->config_path() ) ) {
            $config_result = $this->writer->remove( $this->config_path() );

            if ( empty( $config_result['success'] ) ) {
                return $config_result;
            }
        }

        $config_dir = dirname( $this->config_path() );

        if ( is_dir( $config_dir ) ) {
            @rmdir( $config_dir );
        }

        return $this->result( true, 'removed' );
    }

    /** @return array */
    public function status() {
        return [
            'dropin' => Ownership::classify_dropin( $this->dropin_path() ),
            'config' => Ownership::owns_config( $this->config_path() ) ? 'owned' : ( file_exists( $this->config_path() ) ? 'foreign' : 'missing' ),
        ];
    }

    /** @return string */
    public function dropin_path() {
        return $this->content_dir . '/advanced-cache.php';
    }

    /** @return string */
    public function config_path() {
        return $this->content_dir . '/cache/directorist-performance-cache/config.json';
    }

    /** @return string|false */
    private function dropin_source() {
        if ( is_link( $this->dropin_source_path ) || ! is_file( $this->dropin_source_path ) || ! is_readable( $this->dropin_source_path ) ) {
            return false;
        }

        return file_get_contents( $this->dropin_source_path );
    }

    /**
     * @param string|false $previous_config Previous config or false.
     * @return void
     */
    private function restore_config( $previous_config ) {
        if ( false === $previous_config ) {
            if ( Ownership::owns_config( $this->config_path() ) ) {
                $this->writer->remove( $this->config_path() );
            }

            return;
        }

        $this->writer->write( $this->config_path(), $previous_config );
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
            'dropin'  => $this->dropin_path(),
            'config'  => $this->config_path(),
        ];
    }
}

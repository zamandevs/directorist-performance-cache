<?php

namespace Directorist\Performance_Cache;

/**
 * Coordinates wp-config and drop-in changes as one owned transaction.
 */
final class Lifecycle_Manager {
    /** @var Dropin_Installer */
    private $installer;

    /** @var WP_Cache_Config */
    private $wp_cache_config;

    /**
     * @param Dropin_Installer $installer Drop-in installer.
     * @param WP_Cache_Config  $wp_cache_config WP_CACHE manager.
     */
    public function __construct( Dropin_Installer $installer, WP_Cache_Config $wp_cache_config ) {
        $this->installer       = $installer;
        $this->wp_cache_config = $wp_cache_config;
    }

    /**
     * @param bool $is_multisite Whether WordPress is multisite.
     * @param bool $network_wide Whether activation is network-wide.
     * @return array
     */
    public function activate( $is_multisite, $network_wide ) {
        if ( ! Activation_Policy::allows( $is_multisite, $network_wide ) ) {
            return $this->result( false, 'network_activation_required' );
        }

        $preflight = $this->installer->preflight();

        if ( empty( $preflight['success'] ) ) {
            return $preflight;
        }

        $wp_cache = $this->wp_cache_config->enable();

        if ( empty( $wp_cache['success'] ) ) {
            return $wp_cache;
        }

        $dropin = $this->installer->install();

        if ( empty( $dropin['success'] ) ) {
            if ( 'enabled' === $wp_cache['code'] ) {
                $this->wp_cache_config->disable();
            }

            return $dropin;
        }

        return $this->result(
            true,
            'activated',
            [
                'wp_cache' => $wp_cache['code'],
                'dropin'   => $dropin['code'],
            ]
        );
    }

    /** @return array */
    public function deactivate() {
        $dropin = $this->installer->remove();

        if ( empty( $dropin['success'] ) ) {
            return $dropin;
        }

        $wp_cache = $this->wp_cache_config->disable();

        if ( empty( $wp_cache['success'] ) ) {
            return $wp_cache;
        }

        return $this->result(
            true,
            'deactivated',
            [
                'wp_cache' => $wp_cache['code'],
                'dropin'   => $dropin['code'],
            ]
        );
    }

    /** @return array */
    public function uninstall() {
        $result = $this->deactivate();

        if ( ! empty( $result['success'] ) ) {
            $result['code'] = 'uninstalled';
        }

        return $result;
    }

    /** @return array */
    public function status() {
        return $this->installer->status();
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable code.
     * @param array  $extra Additional details.
     * @return array
     */
    private function result( $success, $code, array $extra = [] ) {
        return array_merge(
            [
                'success' => (bool) $success,
                'code'    => $code,
            ],
            $extra
        );
    }
}

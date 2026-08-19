<?php

namespace Directorist\Performance_Cache;

/**
 * Shared drop-in ownership requires network activation on multisite.
 */
final class Activation_Policy {
    /**
     * @param bool $is_multisite Whether WordPress is multisite.
     * @param bool $network_wide Whether activation is network-wide.
     * @return bool
     */
    public static function allows( $is_multisite, $network_wide ) {
        return ! $is_multisite || $network_wide;
    }
}

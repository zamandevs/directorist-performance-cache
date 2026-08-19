<?php

namespace Directorist\Performance_Cache;

/**
 * Registers the companion only when current Directorist cache contracts exist.
 */
final class Core_Bridge {
    /** @var callable|null */
    private $provider_factory;

    /**
     * @param callable|null $provider_factory Provider factory.
     */
    public function __construct( $provider_factory = null ) {
        $this->provider_factory = is_callable( $provider_factory ) ? $provider_factory : null;
    }

    /**
     * @param array $providers Existing provider registrations.
     * @return array
     */
    public function register( array $providers ) {
        if ( ! interface_exists( 'Directorist\\Cache\\Cache_Provider' ) ) {
            return $providers;
        }

        $provider = $this->provider_factory
            ? call_user_func( $this->provider_factory )
            : new Core_Provider();

        if ( ! $provider instanceof \Directorist\Cache\Cache_Provider ) {
            return $providers;
        }

        $providers[] = [
            'provider' => $provider,
            'priority' => 50,
        ];

        return $providers;
    }
}

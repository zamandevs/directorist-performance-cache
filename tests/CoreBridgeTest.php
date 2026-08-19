<?php
/**
 * Mixed-version Directorist core provider bridge behavior locks.
 */

use Directorist\Performance_Cache\Core_Bridge;
use Directorist\Performance_Cache\Core_Provider;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Test_Engine {
    public $invalidations = [];

    public $warms = [];

    public $warming = true;

    public function is_available() {
        return true;
    }

    public function invalidate( array $plan ) {
        $this->invalidations[] = $plan;

        return [ 'success' => true, 'code' => 'engine_invalidated' ];
    }

    public function warm( array $urls ) {
        $this->warms[] = $urls;

        return [ 'success' => true, 'code' => 'engine_warmed' ];
    }

    public function supports_warm() {
        return $this->warming;
    }

    public function get_status() {
        return [ 'ready' => true ];
    }
}

final class Directorist_Performance_Cache_Core_Bridge_Test extends TestCase {
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_older_core_without_provider_interface_is_unchanged_and_provider_class_stays_unloaded() {
        $bridge = new Core_Bridge();

        $this->assertSame( [], $bridge->register( [] ) );
        $this->assertFalse( class_exists( Core_Provider::class, false ) );
    }

    public function test_current_core_receives_available_companion_provider_with_safe_capabilities() {
        $this->define_core_interface();
        $engine   = new Directorist_Performance_Cache_Test_Engine();
        $provider = new Core_Provider(
            static function () use ( $engine ) {
                return $engine;
            },
            static function () {
                return true;
            }
        );
        $bridge = new Core_Bridge(
            static function () use ( $provider ) {
                return $provider;
            }
        );

        $registered = $bridge->register( [] );

        $this->assertCount( 1, $registered );
        $this->assertSame( $provider, $registered[0]['provider'] );
        $this->assertSame( 50, $registered[0]['priority'] );
        $this->assertTrue( $provider->is_available() );
        $this->assertTrue( $provider->supports( 'purge_generations' ) );
        $this->assertTrue( $provider->supports( 'purge_site' ) );
    }

    public function test_provider_delegates_invalidation_and_warm_without_transforming_payload() {
        $this->define_core_interface();
        $engine   = new Directorist_Performance_Cache_Test_Engine();
        $provider = new Core_Provider(
            static function () use ( $engine ) {
                return $engine;
            },
            static function () {
                return true;
            }
        );
        $plan = [ 'site_id' => 1, 'generations' => [ 'directorist:1:site' ] ];
        $urls = [ 'https://example.org/directory/' ];

        $this->assertSame( 'engine_invalidated', $provider->invalidate( $plan )['code'] );
        $this->assertSame( 'engine_warmed', $provider->warm( $urls )['code'] );
        $this->assertSame( [ $plan ], $engine->invalidations );
        $this->assertSame( [ $urls ], $engine->warms );
    }

    public function test_missing_unhealthy_or_throwing_engine_fails_open() {
        $this->define_core_interface();
        $missing = new Core_Provider(
            static function () {
                return null;
            },
            static function () {
                return true;
            }
        );
        $unhealthy = new Core_Provider(
            static function () {
                return new Directorist_Performance_Cache_Test_Engine();
            },
            static function () {
                return false;
            }
        );
        $throwing = new Core_Provider(
            static function () {
                throw new RuntimeException( 'resolver failed' );
            },
            static function () {
                return true;
            }
        );

        $this->assertFalse( $missing->is_available() );
        $this->assertFalse( $unhealthy->is_available() );
        $this->assertFalse( $throwing->is_available() );
        $this->assertSame( 'engine_unavailable', $throwing->invalidate( [] )['code'] );
    }

    public function test_provider_does_not_advertise_or_dispatch_unimplemented_warming() {
        $this->define_core_interface();
        $engine          = new Directorist_Performance_Cache_Test_Engine();
        $engine->warming = false;
        $provider        = new Core_Provider(
            static function () use ( $engine ) {
                return $engine;
            },
            static function () {
                return true;
            }
        );

        $this->assertTrue( $provider->is_available() );
        $this->assertFalse( $provider->supports( 'warm_urls' ) );
        $this->assertSame( 'capability_unavailable', $provider->warm( [ 'https://example.org/directory/' ] )['code'] );
        $this->assertSame( [], $engine->warms );
    }

    private function define_core_interface() {
        if ( interface_exists( 'Directorist\\Cache\\Cache_Provider', false ) ) {
            return;
        }

        eval(
            implode(
                '',
                [
                    'namespace Directorist\\Cache; interface Cache_Provider {',
                    'public function get_id(); public function is_available(); public function get_capabilities();',
                    'public function supports($capability); public function invalidate(array $request);',
                    'public function warm(array $urls); public function get_status();}',
                ]
            )
        );
    }
}

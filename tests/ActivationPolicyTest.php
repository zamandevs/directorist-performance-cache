<?php
/**
 * Multisite activation ownership policy tests.
 */

use Directorist\Performance_Cache\Activation_Policy;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Activation_Policy_Test extends TestCase {
    public function test_single_site_and_network_activation_are_allowed() {
        $this->assertTrue( Activation_Policy::allows( false, false ) );
        $this->assertTrue( Activation_Policy::allows( true, true ) );
    }

    public function test_per_site_multisite_activation_is_refused() {
        $this->assertFalse( Activation_Policy::allows( true, false ) );
    }
}

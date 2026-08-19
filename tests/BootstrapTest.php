<?php
/**
 * Test-toolchain smoke test.
 */

use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Bootstrap_Test extends TestCase {
    public function test_test_toolchain_uses_the_physical_repository() {
        $this->assertDirectoryExists( DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR );
        $this->assertFalse( is_link( DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR ) );
    }
}

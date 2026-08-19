<?php
/**
 * Generated early config validation tests.
 */

use Directorist\Performance_Cache\Early_Config;
use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Early_Config_Test extends TestCase {
    public function test_only_exact_schema_owner_and_bootstrap_shape_is_valid() {
        $valid = [
            '_marker'        => 'DIRECTORIST PAGE CACHE CONFIG',
            'owner_id'       => 'Owner-ID: directorist-performance-cache',
            'schema'         => 1,
            'owner'          => 'directorist-performance-cache',
            'bootstrap_file' => '/plugin/src/early-bootstrap.php',
        ];

        $this->assertTrue( Early_Config::is_valid( $valid ) );

        foreach (
            [
                null,
                [],
                array_merge( $valid, [ '_marker' => 'foreign' ] ),
                array_merge( $valid, [ 'owner_id' => 'foreign' ] ),
                array_merge( $valid, [ 'schema' => 2 ] ),
                array_merge( $valid, [ 'owner' => 'foreign' ] ),
                array_merge( $valid, [ 'bootstrap_file' => '' ] ),
                array_merge( $valid, [ 'bootstrap_file' => [ 'invalid' ] ] ),
            ] as $invalid
        ) {
            $this->assertFalse( Early_Config::is_valid( $invalid ) );
        }
    }

    public function test_rendered_configuration_is_bounded_non_executable_json() {
        $rendered = ( new Early_Config( '/content', '/plugin' ) )->render();
        $decoded  = json_decode( $rendered, true );

        $this->assertIsArray( $decoded );
        $this->assertTrue( Early_Config::is_valid( $decoded ) );
        $this->assertStringNotContainsString( '<?php', $rendered );
        $this->assertLessThan( 32768, strlen( $rendered ) );
    }

    public function test_unencodable_path_does_not_produce_a_configuration() {
        $this->assertSame( '', ( new Early_Config( '/content', "/plugin/\xB1" ) )->render() );
    }
}

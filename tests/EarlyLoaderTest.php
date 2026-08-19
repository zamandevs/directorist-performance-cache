<?php
/**
 * Early-loader fail-open process tests.
 */

use PHPUnit\Framework\TestCase;

final class Directorist_Performance_Cache_Early_Loader_Test extends TestCase {
    private $content_dir;

    protected function setUp(): void {
        $this->content_dir = sys_get_temp_dir() . '/directorist-pc05-loader-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->content_dir . '/cache/directorist-performance-cache', 0777, true );
    }

    protected function tearDown(): void {
        $this->remove_tree( $this->content_dir );
    }

    public function test_missing_and_malformed_config_fail_open_without_output() {
        $missing = $this->run_dropin();
        file_put_contents( $this->config_path(), "<?php return 'malformed';" );
        $malformed = $this->run_dropin();

        $this->assertSame( '|survived|0|config-executed|0', $missing );
        $this->assertSame( '|survived|0|config-executed|0', $malformed );
    }

    public function test_missing_bootstrap_fails_open_and_valid_bootstrap_loads_once() {
        $this->write_config( $this->content_dir . '/missing-bootstrap.php' );
        $missing = $this->run_dropin();

        $bootstrap = $this->content_dir . '/early-bootstrap.php';
        file_put_contents( $bootstrap, '<?php $GLOBALS["directorist_pc05_loaded"] = 1;' );
        $this->write_config( $bootstrap );
        $loaded = $this->run_dropin();

        $this->assertSame( '|survived|0|config-executed|0', $missing );
        $this->assertSame( '|survived|1|config-executed|0', $loaded );
    }

    public function test_replaced_configuration_is_never_executed() {
        file_put_contents(
            $this->config_path(),
            '<?php $GLOBALS["directorist_pc05_config_executed"] = 1; return array();'
        );

        $this->assertSame( '|survived|0|config-executed|0', $this->run_dropin() );
    }

    public function test_oversized_configuration_fails_open_without_parsing() {
        file_put_contents( $this->config_path(), str_repeat( '{', 32769 ) );

        $this->assertSame( '|survived|0|config-executed|0', $this->run_dropin() );
    }

    private function run_dropin() {
        $script = $this->content_dir . '/run.php';
        $dropin = DIRECTORIST_PERFORMANCE_CACHE_TEST_DIR . '/dropin/advanced-cache.php';
        $source = implode(
            '',
            [
                '<?php define("ABSPATH", ' . var_export( $this->content_dir . '/', true ) . ');',
                'define("WP_CONTENT_DIR", ' . var_export( $this->content_dir, true ) . ');',
                'require ' . var_export( $dropin, true ) . ';',
                'echo "|survived|" . (isset($GLOBALS["directorist_pc05_loaded"]) ? $GLOBALS["directorist_pc05_loaded"] : 0);',
                'echo "|config-executed|" . (isset($GLOBALS["directorist_pc05_config_executed"]) ? $GLOBALS["directorist_pc05_config_executed"] : 0);',
            ]
        );
        file_put_contents( $script, $source );

        $output = [];
        $status = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );

        $this->assertSame( 0, $status, implode( "\n", $output ) );

        return implode( "\n", $output );
    }

    private function write_config( $bootstrap ) {
        $config = [
            '_marker'        => 'DIRECTORIST PAGE CACHE CONFIG',
            'owner_id'       => 'Owner-ID: directorist-performance-cache',
            'schema'         => 1,
            'owner'          => 'directorist-performance-cache',
            'bootstrap_file' => $bootstrap,
        ];
        file_put_contents( $this->config_path(), json_encode( $config ) );
    }

    private function config_path() {
        return $this->content_dir . '/cache/directorist-performance-cache/config.json';
    }

    private function remove_tree( $path ) {
        if ( is_file( $path ) || is_link( $path ) ) {
            unlink( $path );

            return;
        }

        if ( ! is_dir( $path ) ) {
            return;
        }

        foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $item ) {
            $this->remove_tree( $path . '/' . $item );
        }

        rmdir( $path );
    }
}

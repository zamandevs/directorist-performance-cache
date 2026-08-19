<?php

namespace Directorist\Performance_Cache;

/**
 * Generated early-loader configuration.
 */
final class Early_Config {
    const SCHEMA = 1;

    /** @var string */
    private $content_dir;

    /** @var string */
    private $plugin_dir;

    /**
     * @param string $content_dir WordPress content directory.
     * @param string $plugin_dir Companion plugin directory.
     */
    public function __construct( $content_dir, $plugin_dir ) {
        $this->content_dir = rtrim( (string) $content_dir, '/\\' );
        $this->plugin_dir  = rtrim( (string) $plugin_dir, '/\\' );
    }

    /** @return array */
    public function data() {
        return [
            '_marker'        => Ownership::CONFIG_MARKER,
            'owner_id'       => Ownership::OWNER_LINE,
            'schema'         => self::SCHEMA,
            'owner'          => 'directorist-performance-cache',
            'plugin_dir'     => $this->plugin_dir,
            'bootstrap_file' => $this->plugin_dir . '/src/early-bootstrap.php',
            'engine_file'    => $this->plugin_dir . '/src/class-cache-engine.php',
            'cache_dir'      => $this->content_dir . '/cache/directorist-performance-cache',
            'ttl'            => 3600,
            'stale_ttl'      => 30,
            'debug'          => false,
        ];
    }

    /** @return string */
    public function render() {
        $encoded = json_encode( $this->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        return false === $encoded ? '' : $encoded . "\n";
    }

    /**
     * @param mixed $config Candidate config.
     * @return bool
     */
    public static function is_valid( $config ) {
        return is_array( $config )
            && isset( $config['_marker'], $config['owner_id'], $config['schema'], $config['owner'], $config['bootstrap_file'] )
            && Ownership::CONFIG_MARKER === $config['_marker']
            && Ownership::OWNER_LINE === $config['owner_id']
            && self::SCHEMA === $config['schema']
            && 'directorist-performance-cache' === $config['owner']
            && is_string( $config['bootstrap_file'] )
            && '' !== $config['bootstrap_file'];
    }
}

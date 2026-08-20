<?php

namespace Directorist\Performance_Cache;

/**
 * Read-only bounded cache-file health inventory for explicit diagnostics.
 */
final class Cache_Inventory {
    const MAX_FILES       = 500;
    const MAX_DIRECTORIES = 128;

    /** @var string */
    private $root;

    /**
     * @param string $root Owned cache root.
     */
    public function __construct( $root ) {
        $this->root = is_string( $root ) ? rtrim( $root, '/\\' ) : '';
    }

    /** @return array */
    public function status() {
        if ( '' === $this->root || ! file_exists( $this->root ) ) {
            return $this->result( false, 'cache_root_missing' );
        }

        if ( is_link( $this->root ) ) {
            return $this->result( false, 'cache_root_symlink' );
        }

        if ( ! is_dir( $this->root ) || ! is_readable( $this->root ) ) {
            return $this->result( false, 'cache_root_unreadable' );
        }

        $pages       = $this->page_counts();
        $generations = $pages['truncated']
            ? [ 'count' => 0, 'examined' => 0, 'truncated' => true ]
            : $this->generation_count( self::MAX_FILES - $pages['examined'] );
        $truncated   = $pages['truncated'] || $generations['truncated'];
        $code        = 0 < $pages['orphans'] ? 'cache_orphans' : ( $truncated ? 'inventory_truncated' : 'ready' );

        return [
            'success'     => true,
            'code'        => $code,
            'writable'    => is_writable( $this->root ),
            'entries'     => $pages['entries'],
            'orphans'     => $pages['orphans'],
            'generations' => $generations['count'],
            'examined'    => $pages['examined'] + $generations['examined'],
            'truncated'   => $truncated,
        ];
    }

    /** @return array */
    private function page_counts() {
        $pairs       = [];
        $examined    = 0;
        $directories = 0;
        $truncated   = false;

        foreach ( $this->hex_directories( $this->root . '/pages' ) as $first ) {
            foreach ( $this->hex_directories( $this->root . '/pages/' . $first ) as $second ) {
                ++$directories;
                $directory = $this->root . '/pages/' . $first . '/' . $second;

                foreach ( scandir( $directory ) as $name ) {
                    if ( ! preg_match( '/^([a-f0-9]{64})\.(json|body)$/', $name, $matches ) ) {
                        continue;
                    }

                    if ( self::MAX_FILES <= $examined ) {
                        $truncated = true;
                        break 3;
                    }

                    ++$examined;

                    $path = $directory . '/' . $name;

                    if ( is_link( $path ) || ! is_file( $path ) ) {
                        $pairs[ $matches[1] ]['unsafe'] = true;
                        continue;
                    }

                    $pairs[ $matches[1] ][ $matches[2] ] = true;
                }

                if ( self::MAX_DIRECTORIES <= $directories ) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        $entries = 0;
        $orphans = 0;

        foreach ( $pairs as $pair ) {
            if ( ! empty( $pair['json'] ) && ! empty( $pair['body'] ) && empty( $pair['unsafe'] ) ) {
                ++$entries;
            } else {
                ++$orphans;
            }
        }

        return compact( 'entries', 'orphans', 'examined', 'truncated' );
    }

    /**
     * @param int $limit Remaining file budget.
     * @return array
     */
    private function generation_count( $limit ) {
        $count     = 0;
        $examined  = 0;
        $truncated = false;
        $limit     = max( 0, (int) $limit );

        foreach ( $this->hex_directories( $this->root . '/generations' ) as $directory ) {
            $path = $this->root . '/generations/' . $directory;

            foreach ( scandir( $path ) as $name ) {
                if ( ! preg_match( '/^[a-f0-9]{64}\.gen$/', $name ) ) {
                    continue;
                }

                if ( $limit <= $examined ) {
                    $truncated = true;
                    break 2;
                }

                ++$examined;

                if ( ! is_link( $path . '/' . $name ) && is_file( $path . '/' . $name ) ) {
                    ++$count;
                }
            }
        }

        return compact( 'count', 'examined', 'truncated' );
    }

    /**
     * @param string $path Parent directory.
     * @return string[]
     */
    private function hex_directories( $path ) {
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            return [];
        }

        $directories = [];

        foreach ( scandir( $path ) as $name ) {
            $candidate = $path . '/' . $name;

            if ( preg_match( '/^[a-f0-9]{2}$/', $name ) && is_dir( $candidate ) && ! is_link( $candidate ) ) {
                $directories[] = $name;
            }
        }

        sort( $directories, SORT_STRING );

        return $directories;
    }

    /**
     * @param bool   $success Result state.
     * @param string $code Stable code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success'     => (bool) $success,
            'code'        => (string) $code,
            'writable'    => false,
            'entries'     => 0,
            'orphans'     => 0,
            'generations' => 0,
            'examined'    => 0,
            'truncated'   => false,
        ];
    }
}

<?php

namespace Directorist\Performance_Cache;

/**
 * Atomic bounded filesystem queue for same-origin cache warm requests.
 */
final class Warm_Queue {
    const SCHEMA               = 1;
    const MAX_ITEMS            = 500;
    const MAX_BATCH            = 10;
    const MAX_ATTEMPTS         = 3;
    const CLAIM_TTL            = 120;
    const CIRCUIT_FAILURES     = 4;
    const CIRCUIT_OPEN_SECONDS = 300;

    /** @var string */
    private $root;

    /** @var string */
    private $state_path;

    /** @var string */
    private $lock_path;

    /** @var string */
    private $origin;

    /** @var callable */
    private $clock;

    /** @var int */
    private $capacity;

    /** @var Request_Key */
    private $request_key;

    /** @var Atomic_File_Writer */
    private $writer;

    /**
     * @param string        $root Cache root.
     * @param string        $home_url Site home URL.
     * @param callable|null $clock Unix timestamp provider.
     * @param int           $capacity Queue capacity.
     */
    public function __construct( $root, $home_url, $clock = null, $capacity = self::MAX_ITEMS ) {
        $this->root        = is_string( $root ) ? rtrim( $root, '/\\' ) : '';
        $this->clock       = is_callable( $clock ) ? $clock : 'time';
        $this->capacity    = min( self::MAX_ITEMS, max( 1, (int) $capacity ) );
        $this->request_key = new Request_Key();
        $this->writer      = new Atomic_File_Writer();
        $home              = $this->request_key->from_url( $home_url );
        $this->origin      = ! empty( $home['success'] ) ? $this->origin( $home['canonical_url'] ) : '';
        $scope             = '' === $this->origin ? 'invalid' : substr( hash( 'sha256', $this->origin ), 0, 16 );
        $this->state_path  = $this->root . '/operations/sites/' . $scope . '/warm-queue.json';
        $this->lock_path   = $this->root . '/operations/sites/' . $scope . '/warm-queue.lock';
    }

    /**
     * @param string[] $urls Candidate URLs.
     * @return array
     */
    public function enqueue( array $urls ) {
        $lock = $this->acquire_lock();

        if ( false === $lock ) {
            return $this->result( false, 'queue_locked' );
        }

        $state = $this->read_state();

        if ( false === $state ) {
            $this->release_lock( $lock );

            return $this->result( false, 'queue_state_invalid' );
        }

        $queued     = 0;
        $duplicates = 0;
        $rejected   = 0;
        $full       = 0;

        foreach ( $urls as $url ) {
            $normalized = $this->normalize_url( $url );

            if ( false === $normalized ) {
                ++$rejected;
                continue;
            }

            if ( isset( $state['items'][ $normalized['hash'] ] ) ) {
                ++$duplicates;
                continue;
            }

            if ( $this->capacity <= count( $state['items'] ) ) {
                ++$full;
                continue;
            }

            $state['items'][ $normalized['hash'] ] = [
                'hash'         => $normalized['hash'],
                'url'          => $normalized['canonical_url'],
                'state'        => 'pending',
                'attempts'     => 0,
                'available_at' => $this->now(),
                'claim'        => '',
                'claimed_at'   => 0,
                'last_code'    => '',
            ];
            ++$queued;
        }

        $state['totals']['queued'] += $queued;
        $written = $this->write_state( $state );
        $this->release_lock( $lock );

        return array_merge(
            $this->result( $written, $written ? 'queued' : 'queue_write_failed' ),
            [
                'queued'     => $queued,
                'duplicates' => $duplicates,
                'rejected'   => $rejected,
                'full'       => $full,
                'total'      => count( $state['items'] ),
            ]
        );
    }

    /**
     * Claim one bounded batch without holding a lock during HTTP requests.
     *
     * @param int $limit Maximum batch size.
     * @return array
     */
    public function claim( $limit = 3 ) {
        $lock = $this->acquire_lock();

        if ( false === $lock ) {
            return array_merge( $this->result( false, 'queue_locked' ), [ 'claim' => '', 'items' => [] ] );
        }

        $state = $this->read_state();

        if ( false === $state ) {
            $this->release_lock( $lock );

            return array_merge( $this->result( false, 'queue_state_invalid' ), [ 'claim' => '', 'items' => [] ] );
        }

        $now       = $this->now();
        $recovered = $this->recover_claims( $state, $now );

        if ( $state['paused'] ) {
            $this->persist_recovery( $state, $recovered );
            $this->release_lock( $lock );

            return array_merge( $this->result( true, 'paused' ), [ 'claim' => '', 'items' => [] ] );
        }

        if ( $state['circuit_open_until'] > $now ) {
            $this->persist_recovery( $state, $recovered );
            $this->release_lock( $lock );

            return array_merge( $this->result( true, 'circuit_open' ), [ 'claim' => '', 'items' => [] ] );
        }

        if ( 0 < $state['circuit_open_until'] ) {
            $state['circuit_open_until'] = 0;
            $state['consecutive_failures'] = 0;
            $recovered = true;
        }

        $limit = min( self::MAX_BATCH, max( 1, (int) $limit ) );
        $claim = $this->token();
        $items = [];

        foreach ( $state['items'] as $hash => &$item ) {
            if ( $limit <= count( $items ) ) {
                break;
            }

            if ( 'pending' !== $item['state'] || $item['available_at'] > $now ) {
                continue;
            }

            $item['state']      = 'inflight';
            $item['claim']      = $claim;
            $item['claimed_at'] = $now;
            $items[]            = $item;
        }
        unset( $item );

        if ( empty( $items ) ) {
            $this->persist_recovery( $state, $recovered );
            $this->release_lock( $lock );
            $code = empty( $state['items'] ) ? 'empty' : 'backoff';

            return array_merge( $this->result( true, $code ), [ 'claim' => '', 'items' => [] ] );
        }

        $written = $this->write_state( $state );
        $this->release_lock( $lock );

        return array_merge(
            $this->result( $written, $written ? 'claimed' : 'queue_write_failed' ),
            [
                'claim' => $written ? $claim : '',
                'items' => $written ? $items : [],
            ]
        );
    }

    /**
     * @param string $claim Claim token.
     * @param array  $results Results keyed by URL hash.
     * @return array
     */
    public function complete( $claim, array $results ) {
        $lock = $this->acquire_lock();

        if ( false === $lock ) {
            return $this->result( false, 'queue_locked' );
        }

        $state = $this->read_state();

        if ( false === $state ) {
            $this->release_lock( $lock );

            return $this->result( false, 'queue_state_invalid' );
        }

        $now       = $this->now();
        $completed = 0;
        $retried   = 0;
        $failed    = 0;

        foreach ( array_keys( $state['items'] ) as $hash ) {
            $item = $state['items'][ $hash ];

            if ( 'inflight' !== $item['state'] || $claim !== $item['claim'] ) {
                continue;
            }

            $result  = isset( $results[ $hash ] ) && is_array( $results[ $hash ] ) ? $results[ $hash ] : [];
            $success = ! empty( $result['success'] );
            $code    = isset( $result['code'] ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $result['code'] ) ), 0, 64 ) : 'missing_result';

            if ( $success ) {
                unset( $state['items'][ $hash ] );
                ++$state['totals']['succeeded'];
                ++$completed;
                $state['consecutive_failures'] = 0;
                continue;
            }

            ++$item['attempts'];
            ++$state['totals']['failed_attempts'];
            ++$state['consecutive_failures'];
            $item['last_code'] = '' === $code ? 'request_failed' : $code;

            if ( self::MAX_ATTEMPTS <= $item['attempts'] ) {
                unset( $state['items'][ $hash ] );
                ++$state['totals']['failed'];
                ++$failed;
                continue;
            }

            $item['state']        = 'pending';
            $item['claim']        = '';
            $item['claimed_at']   = 0;
            $item['available_at'] = $now + $this->backoff( $item['attempts'] );
            $state['items'][ $hash ] = $item;
            ++$retried;
        }

        $code = 'completed';

        if ( self::CIRCUIT_FAILURES <= $state['consecutive_failures'] ) {
            $state['circuit_open_until'] = $now + self::CIRCUIT_OPEN_SECONDS;
            $code = 'circuit_open';
        }

        $written = $this->write_state( $state );
        $this->release_lock( $lock );

        return array_merge(
            $this->result( $written, $written ? $code : 'queue_write_failed' ),
            [
                'completed' => $completed,
                'retried'   => $retried,
                'failed'    => $failed,
            ]
        );
    }

    /** @return array */
    public function status() {
        $state = $this->read_state();

        if ( false === $state ) {
            return array_merge( $this->result( false, 'queue_state_invalid' ), $this->status_counts( $this->default_state() ) );
        }

        return array_merge( $this->result( true, 'ready' ), $this->status_counts( $state ) );
    }

    /** @return bool */
    public function has_runnable_items() {
        $status = $this->status();

        return ! empty( $status['success'] )
            && ! $status['paused']
            && $status['circuit_open_until'] <= $this->now()
            && 0 < $status['pending']
            && $status['next_available_at'] <= $this->now();
    }

    /**
     * @param string $reason Pause reason.
     * @return array
     */
    public function pause( $reason = 'manual' ) {
        return $this->set_pause( true, $reason );
    }

    /** @return array */
    public function resume() {
        return $this->set_pause( false, '' );
    }

    /** @return array */
    public function cancel() {
        $lock = $this->acquire_lock();

        if ( false === $lock ) {
            return $this->result( false, 'queue_locked' );
        }

        $state = $this->read_state();

        if ( false === $state ) {
            $this->release_lock( $lock );

            return $this->result( false, 'queue_state_invalid' );
        }

        $cancelled = count( $state['items'] );
        $state['items'] = [];
        $state['circuit_open_until'] = 0;
        $state['consecutive_failures'] = 0;
        $state['totals']['cancelled'] += $cancelled;
        $written = $this->write_state( $state );
        $this->release_lock( $lock );

        return array_merge( $this->result( $written, $written ? 'cancelled' : 'queue_write_failed' ), [ 'cancelled' => $cancelled ] );
    }

    /**
     * @param bool   $paused Paused state.
     * @param string $reason Pause reason.
     * @return array
     */
    private function set_pause( $paused, $reason ) {
        $lock = $this->acquire_lock();

        if ( false === $lock ) {
            return $this->result( false, 'queue_locked' );
        }

        $state = $this->read_state();

        if ( false === $state ) {
            $this->release_lock( $lock );

            return $this->result( false, 'queue_state_invalid' );
        }

        $state['paused']      = (bool) $paused;
        $state['pause_reason'] = $paused ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $reason ) ), 0, 64 ) : '';
        $written = $this->write_state( $state );
        $this->release_lock( $lock );

        return $this->result( $written, $written ? ( $paused ? 'paused' : 'resumed' ) : 'queue_write_failed' );
    }

    /**
     * @param array $state Queue state.
     * @param int   $now Current timestamp.
     * @return bool
     */
    private function recover_claims( array &$state, $now ) {
        $recovered = false;

        foreach ( $state['items'] as &$item ) {
            if ( 'inflight' !== $item['state'] || $item['claimed_at'] + self::CLAIM_TTL >= $now ) {
                continue;
            }

            $item['state']        = 'pending';
            $item['available_at'] = $now;
            $item['claim']        = '';
            $item['claimed_at']   = 0;
            $recovered            = true;
        }
        unset( $item );

        return $recovered;
    }

    /**
     * @param array $state Queue state.
     * @param bool  $changed Whether recovery changed state.
     * @return void
     */
    private function persist_recovery( array $state, $changed ) {
        if ( $changed ) {
            $this->write_state( $state );
        }
    }

    /**
     * @param mixed $url Candidate URL.
     * @return array|false
     */
    private function normalize_url( $url ) {
        $key = $this->request_key->from_url( $url );

        if ( empty( $key['success'] ) || '' === $this->origin || $this->origin( $key['canonical_url'] ) !== $this->origin ) {
            return false;
        }

        return $key;
    }

    /**
     * @param string $url Canonical URL.
     * @return string
     */
    private function origin( $url ) {
        $parts = parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
    }

    /** @return array|false */
    private function read_state() {
        if ( ! file_exists( $this->state_path ) ) {
            return $this->default_state();
        }

        if ( is_link( $this->state_path ) || ! is_file( $this->state_path ) || ! is_readable( $this->state_path ) || 5242880 < filesize( $this->state_path ) ) {
            return false;
        }

        $source = file_get_contents( $this->state_path );
        $state  = is_string( $source ) ? json_decode( $source, true ) : null;

        return $this->valid_state( $state ) ? $state : false;
    }

    /**
     * @param mixed $state Queue state.
     * @return bool
     */
    private function valid_state( $state ) {
        if ( ! is_array( $state ) || self::SCHEMA !== ( isset( $state['schema'] ) ? $state['schema'] : null ) || ! isset( $state['items'], $state['totals'] ) || ! is_array( $state['items'] ) || ! is_array( $state['totals'] ) || self::MAX_ITEMS < count( $state['items'] ) ) {
            return false;
        }

        foreach ( $state['items'] as $hash => $item ) {
            $normalized = is_array( $item ) && isset( $item['url'] ) ? $this->normalize_url( $item['url'] ) : false;

            if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! is_array( $item ) || ! isset( $item['hash'], $item['url'], $item['state'], $item['attempts'], $item['available_at'], $item['claim'], $item['claimed_at'], $item['last_code'] ) || $hash !== $item['hash'] || ! in_array( $item['state'], [ 'pending', 'inflight' ], true ) || false === $normalized || $hash !== $normalized['hash'] || $item['url'] !== $normalized['canonical_url'] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $state Queue state.
     * @return bool
     */
    private function write_state( array $state ) {
        $state['updated_at'] = $this->now();
        $encoded = json_encode( $state, JSON_UNESCAPED_SLASHES );

        return is_string( $encoded ) && $this->writer->write( $this->state_path, $encoded );
    }

    /** @return array */
    private function default_state() {
        return [
            'schema'               => self::SCHEMA,
            'paused'               => false,
            'pause_reason'          => '',
            'circuit_open_until'    => 0,
            'consecutive_failures'  => 0,
            'items'                 => [],
            'totals'                => [
                'queued'          => 0,
                'succeeded'       => 0,
                'failed'          => 0,
                'failed_attempts' => 0,
                'cancelled'       => 0,
            ],
            'updated_at'            => 0,
        ];
    }

    /**
     * @param array $state Queue state.
     * @return array
     */
    private function status_counts( array $state ) {
        $pending   = 0;
        $inflight  = 0;
        $next      = 0;

        foreach ( $state['items'] as $item ) {
            if ( 'pending' === $item['state'] ) {
                ++$pending;
                $next = 0 === $next ? $item['available_at'] : min( $next, $item['available_at'] );
            } else {
                ++$inflight;
            }
        }

        return [
            'total'                => count( $state['items'] ),
            'pending'              => $pending,
            'inflight'             => $inflight,
            'capacity'             => $this->capacity,
            'paused'               => (bool) $state['paused'],
            'pause_reason'          => (string) $state['pause_reason'],
            'circuit_open_until'    => (int) $state['circuit_open_until'],
            'consecutive_failures'  => (int) $state['consecutive_failures'],
            'next_available_at'     => $next,
            'totals'                => $state['totals'],
            'updated_at'            => (int) $state['updated_at'],
        ];
    }

    /** @return resource|false */
    private function acquire_lock() {
        if ( '' === $this->root || ! $this->writer->prepare_directory( dirname( $this->lock_path ) ) || is_link( $this->lock_path ) ) {
            return false;
        }

        $lock = @fopen( $this->lock_path, 'c+' );

        if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $lock ) ) {
                fclose( $lock );
            }

            return false;
        }

        return $lock;
    }

    /**
     * @param resource $lock Queue lock.
     * @return void
     */
    private function release_lock( $lock ) {
        flock( $lock, LOCK_UN );
        fclose( $lock );
    }

    /** @return int */
    private function now() {
        return (int) call_user_func( $this->clock );
    }

    /** @return string */
    private function token() {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $exception ) {
            unset( $exception );

            return hash( 'sha256', uniqid( '', true ) );
        }
    }

    /**
     * @param int $attempt Failed attempt number.
     * @return int
     */
    private function backoff( $attempt ) {
        $delays = [ 1 => 15, 2 => 60 ];

        return isset( $delays[ $attempt ] ) ? $delays[ $attempt ] : 300;
    }

    /**
     * @param bool   $success Operation state.
     * @param string $code Stable result code.
     * @return array
     */
    private function result( $success, $code ) {
        return [
            'success' => (bool) $success,
            'code'    => (string) $code,
        ];
    }
}

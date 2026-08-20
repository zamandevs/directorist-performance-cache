<?php

namespace Directorist\Performance_Cache;

/**
 * Executes exactly one warm queue batch and optionally dispatches a successor.
 */
final class Warm_Worker {
    /** @var Warm_Queue */
    private $queue;

    /** @var callable */
    private $requester;

    /** @var callable */
    private $successor;

    /**
     * @param Warm_Queue $queue Persistent queue.
     * @param callable   $requester URL requester.
     * @param callable   $successor Nonblocking successor dispatcher.
     */
    public function __construct( Warm_Queue $queue, $requester, $successor ) {
        $this->queue     = $queue;
        $this->requester = is_callable( $requester ) ? $requester : static function () {
            return [ 'success' => false, 'code' => 'requester_unavailable' ];
        };
        $this->successor = is_callable( $successor ) ? $successor : static function () {
            return false;
        };
    }

    /**
     * @param int $limit Maximum URLs in this request.
     * @return array
     */
    public function run( $limit = 3 ) {
        $claim = $this->queue->claim( $limit );

        if ( 'claimed' !== $claim['code'] ) {
            return array_merge( $claim, [ 'processed' => 0, 'successor' => false ] );
        }

        $results = [];

        foreach ( $claim['items'] as $item ) {
            try {
                $result = call_user_func( $this->requester, $item['url'], $item );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $result = [ 'success' => false, 'code' => 'request_exception' ];
            }

            $results[ $item['hash'] ] = is_array( $result )
                ? $result
                : [ 'success' => false, 'code' => 'invalid_request_result' ];
        }

        $completed = $this->queue->complete( $claim['claim'], $results );
        $successor = false;

        if ( ! empty( $completed['success'] ) && $this->queue->has_runnable_items() ) {
            try {
                $successor = (bool) call_user_func( $this->successor );
            } catch ( \Throwable $exception ) {
                unset( $exception );
                $successor = false;
            }
        }

        return array_merge(
            $completed,
            [
                'code'      => ! empty( $completed['success'] ) ? 'batch_complete' : $completed['code'],
                'processed' => count( $claim['items'] ),
                'successor' => $successor,
            ]
        );
    }
}

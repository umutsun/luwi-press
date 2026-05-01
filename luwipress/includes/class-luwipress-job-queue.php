<?php
/**
 * LuwiPress Generic Job Queue
 *
 * Background processing for any long-running batch work (translations, enrichment,
 * AEO generation, etc.). Each module registers a "type" with a worker callable; the
 * queue handles chunking, scheduling via wp_cron, atomic progress tracking via
 * transients, and loopback fallback when wp_cron is starved.
 *
 * Usage:
 *
 *   // 1. Register a job type once (in module __construct):
 *   LuwiPress_Job_Queue::register_type( 'taxonomy_translation', array( $this, 'process_chunk' ) );
 *
 *   // 2. Enqueue a job (returns job_id immediately, work runs in background):
 *   $job = LuwiPress_Job_Queue::enqueue( 'taxonomy_translation', array(
 *       'chunks'  => array( $chunk1, $chunk2, ... ),  // each chunk = 1 unit of work
 *       'meta'    => array( 'taxonomy' => 'product_tag', 'lang' => 'fr' ),
 *   ) );
 *   // $job = [ 'job_id' => 'job_abc...', 'total_units' => N ]
 *
 *   // 3. UI polls status:
 *   $status = LuwiPress_Job_Queue::status( $job_id );
 *   // [ done_units, total_units, sent, saved, errors[], status: queued|processing|done|cancelled ]
 *
 *   // 4. Worker callback signature (called per chunk):
 *   public function process_chunk( $chunk_payload, $meta, $job_id ) {
 *       // Do AI/DB work, return: [ 'sent' => N, 'saved' => N, 'errors' => [...] ]
 *       return array( 'sent' => 25, 'saved' => 25, 'errors' => array() );
 *   }
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LuwiPress_Job_Queue {

    const TRANSIENT_PREFIX = 'luwipress_job_';
    const TTL              = 21600; // 6 hours
    const STAGGER_SECONDS  = 5;     // Delay between scheduled chunks
    const CRON_HOOK        = 'luwipress_jq_chunk';

    /** @var array<string, callable> type => worker callable */
    private static $workers = array();

    /** Register the cron hook + AJAX endpoint exactly once. */
    public static function bootstrap() {
        static $booted = false;
        if ( $booted ) { return; }
        $booted = true;
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_dispatch' ), 10, 4 );
        add_action( 'wp_ajax_luwipress_job_status', array( __CLASS__, 'ajax_status' ) );
        add_action( 'wp_ajax_luwipress_job_cancel', array( __CLASS__, 'ajax_cancel' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
    }

    public static function register_rest() {
        register_rest_route( 'luwipress/v1', '/job/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_status' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args' => array( 'job_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
        ) );
        register_rest_route( 'luwipress/v1', '/job/cancel', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_cancel' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
            'args' => array( 'job_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
        ) );
    }

    /** Modules call this in __construct to register their per-chunk worker. */
    public static function register_type( $type, $worker ) {
        self::$workers[ $type ] = $worker;
    }

    /**
     * Enqueue a job. Returns job_id + total_units immediately; work runs in background.
     *
     * @param string $type    Registered type name.
     * @param array  $payload Required: 'chunks' => array of per-chunk payloads.
     *                        Optional: 'meta' => array shared by every chunk.
     * @return WP_Error|array
     */
    public static function enqueue( $type, $payload ) {
        if ( ! isset( self::$workers[ $type ] ) ) {
            return new WP_Error( 'unknown_job_type', 'Unknown job type: ' . $type );
        }
        $chunks = isset( $payload['chunks'] ) && is_array( $payload['chunks'] ) ? array_values( $payload['chunks'] ) : array();
        $meta   = isset( $payload['meta'] )   && is_array( $payload['meta'] )   ? $payload['meta']   : array();
        if ( empty( $chunks ) ) {
            return new WP_Error( 'no_chunks', 'enqueue() needs chunks[]' );
        }

        $job_id      = 'job_' . wp_generate_uuid4();
        $total_units = count( $chunks );

        $job = array(
            'job_id'      => $job_id,
            'type'        => $type,
            'meta'        => $meta,
            'total_units' => $total_units,
            'done_units'  => 0,
            'sent'        => 0,
            'saved'       => 0,
            'errors'      => array(),
            'status'      => 'queued',
            'started_at'  => time(),
            'finished_at' => null,
        );
        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TTL );

        // Stagger chunk events so spawn_cron has space to fire each one without
        // collapsing them into a single PHP process (which would re-create the
        // sync timeout we are trying to escape).
        $offset = 0;
        foreach ( $chunks as $chunk_index => $chunk_payload ) {
            wp_schedule_single_event(
                time() + $offset,
                self::CRON_HOOK,
                array( $job_id, $type, $chunk_index, $chunk_payload )
            );
            $offset += self::STAGGER_SECONDS;
        }

        self::nudge_cron();

        return array(
            'job_id'      => $job_id,
            'total_units' => $total_units,
            'status'      => 'queued',
        );
    }

    /**
     * Cron hook entry point. Fires once per chunk.
     *
     * @param string $job_id
     * @param string $type
     * @param int    $chunk_index
     * @param mixed  $chunk_payload
     */
    public static function cron_dispatch( $job_id, $type, $chunk_index, $chunk_payload ) {
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
        @ini_set( 'memory_limit', '512M' );

        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! is_array( $job ) ) { return; }
        if ( $job['status'] === 'cancelled' ) { return; }

        // Lazy worker resolution: workers are registered in module __construct, which
        // runs at plugins_loaded p10. Cron may fire later through rest/ajax/cli/wp-cron
        // entrypoints -- in all of them plugins_loaded has fired by the time the action
        // hook runs, so workers[] is populated. If it isn't, that means the module file
        // didn't load (maybe class autoload failed) and we should record an error.
        if ( ! isset( self::$workers[ $type ] ) ) {
            $error = sprintf( 'Worker for type %s not registered when chunk %d fired', $type, $chunk_index );
            self::merge_progress( $job_id, 0, 0, array( $error ), false );
            return;
        }

        if ( $job['status'] === 'queued' ) {
            $job['status'] = 'processing';
            set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TTL );
        }

        $worker = self::$workers[ $type ];
        $sent = 0; $saved = 0; $errors = array();
        try {
            $result = call_user_func( $worker, $chunk_payload, $job['meta'] ?? array(), $job_id );
            if ( is_array( $result ) ) {
                $sent   = absint( $result['sent']  ?? 0 );
                $saved  = absint( $result['saved'] ?? 0 );
                $errors = (array) ( $result['errors'] ?? array() );
            }
        } catch ( Throwable $e ) {
            $errors[] = sprintf( 'chunk %d threw: %s', $chunk_index, $e->getMessage() );
        }

        self::merge_progress( $job_id, $sent, $saved, $errors, true );

        // Each chunk completion also nudges cron -- helps when the operator is just
        // staring at a progress bar (no other admin nav happening). The wp_cron event
        // table still has work; this kicks the next one early instead of waiting for
        // the next polling tick.
        self::nudge_cron();
    }

    /**
     * Atomic-ish progress update. Re-reads transient before writing because a sibling
     * cron may have updated it concurrently (each chunk runs in its own PHP process).
     */
    private static function merge_progress( $job_id, $sent, $saved, $errors, $advance_done ) {
        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! is_array( $job ) ) { return; }
        if ( $advance_done ) {
            $job['done_units'] = ( $job['done_units'] ?? 0 ) + 1;
        }
        $job['sent']  = ( $job['sent']  ?? 0 ) + $sent;
        $job['saved'] = ( $job['saved'] ?? 0 ) + $saved;
        if ( ! empty( $errors ) ) {
            $existing = (array) ( $job['errors'] ?? array() );
            $job['errors'] = array_slice( array_merge( $existing, $errors ), 0, 20 );
        }
        if ( $job['done_units'] >= $job['total_units'] && $job['status'] !== 'done' ) {
            $job['status']      = 'done';
            $job['finished_at'] = time();
            $saved_count = (int) $job['saved'];
            LuwiPress_Logger::log(
                sprintf( 'Job %s (%s) complete: %d/%d sent, %d saved', $job_id, $job['type'], $job['sent'], $job['total_units'], $saved_count ),
                $saved_count > 0 ? 'info' : 'warning',
                array( 'job_id' => $job_id, 'meta' => $job['meta'] ?? array(), 'errors' => array_slice( (array) ( $job['errors'] ?? array() ), 0, 3 ) )
            );
        }
        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TTL );
    }

    public static function status( $job_id ) {
        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        return is_array( $job ) ? $job : null;
    }

    public static function cancel( $job_id ) {
        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! is_array( $job ) ) { return false; }
        $job['status']      = 'cancelled';
        $job['finished_at'] = time();
        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TTL );
        // Unschedule remaining chunks. Filter wp-cron events by hook+job_id.
        $crons = _get_cron_array();
        if ( is_array( $crons ) ) {
            foreach ( $crons as $ts => $hooks ) {
                if ( ! isset( $hooks[ self::CRON_HOOK ] ) ) { continue; }
                foreach ( $hooks[ self::CRON_HOOK ] as $key => $event ) {
                    $args = $event['args'] ?? array();
                    if ( ( $args[0] ?? '' ) === $job_id ) {
                        wp_unschedule_event( $ts, self::CRON_HOOK, $args );
                    }
                }
            }
        }
        return true;
    }

    /**
     * Trigger wp-cron processing now. Two layers:
     *  1) spawn_cron() -- standard WP non-blocking loopback to wp-cron.php
     *  2) Manual loopback request to wp-cron.php with doing_wp_cron token if spawn_cron
     *     gates us out (it has a 60s lock to prevent flooding).
     *
     * Loopback is best-effort; we don't wait for or check its response.
     */
    public static function nudge_cron() {
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) { return; }

        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }

        // Fallback loopback for hosts where spawn_cron() is throttled or DISABLE_WP_CRON
        // is set. Fire-and-forget; do not block the caller. Only fire occasionally to
        // avoid hammering wp-cron.php on every poll.
        $last = (int) get_transient( 'luwipress_jq_last_loopback' );
        if ( time() - $last < 30 ) { return; } // Throttle to once per 30s globally.
        set_transient( 'luwipress_jq_last_loopback', time(), 60 );

        $cron_url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );
        wp_remote_post( $cron_url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        ) );
    }

    // AJAX endpoints (admin-ajax.php) -------------------------------------------------

    public static function ajax_status() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        if ( ! $job_id ) {
            wp_send_json_error( 'Missing job_id' );
        }
        // Status poll doubles as cron heartbeat -- when the operator just sits on a
        // dashboard page (no other admin nav), wp-cron never fires and chunks stall.
        self::nudge_cron();

        $job = self::status( $job_id );
        if ( ! $job ) {
            wp_send_json_error( 'Job not found or expired' );
        }
        wp_send_json_success( $job );
    }

    public static function ajax_cancel() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        $ok = self::cancel( $job_id );
        if ( ! $ok ) {
            wp_send_json_error( 'Cancel failed' );
        }
        wp_send_json_success( array( 'cancelled' => true, 'job_id' => $job_id ) );
    }

    // REST endpoints -----------------------------------------------------------------

    public static function rest_status( $request ) {
        $job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
        self::nudge_cron();
        $job = self::status( $job_id );
        if ( ! $job ) {
            return new WP_Error( 'job_not_found', 'Job not found or expired', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $job );
    }

    public static function rest_cancel( $request ) {
        $job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
        $ok = self::cancel( $job_id );
        if ( ! $ok ) {
            return new WP_Error( 'cancel_failed', 'Job not found or already finished', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array( 'cancelled' => true, 'job_id' => $job_id ) );
    }
}

LuwiPress_Job_Queue::bootstrap();

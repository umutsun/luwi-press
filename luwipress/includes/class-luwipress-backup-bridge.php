<?php
/**
 * LuwiPress Backup Bridge — UpdraftPlus trigger/status/list/download over REST + WebMCP,
 * plus a cross-server pull/rescan/restore-assist flow for site migration.
 *
 * Wraps the UpdraftPlus public API ($updraftplus global + UpdraftPlus_Backup_History)
 * so a backup can be triggered, monitored, enumerated, and an archive streamed to a
 * trusted peer (for cross-server migration) entirely from the agent surface.
 *
 * Symmetric: the SAME class ships on source and target. Source uses diag/run/status/
 * list/download; the target (e.g. arsha-vps) uses pull/rescan/restore to ingest the
 * source's archives into its own updraft_dir and rescan them into a restorable set.
 *
 * Design constraints:
 *  - UpdraftPlus is a SOFT dependency. Every route degrades to 503 when it is absent
 *    or the expected class/method is missing (version drift) — we probe
 *    method_exists()/class_exists() before every call.
 *  - Triggering uses the OFFICIALLY-RECOMMENDED do_action('updraft_backupnow_*') hooks;
 *    reading uses UpdraftPlus_Backup_History::get_history(); status uses
 *    jobdata_getarray()/is_backup_running(). boot_backup() is an internal fallback only.
 *  - /backup/download is gated by OUR token (check_token_or_admin), streams from
 *    updraft_dir, with a strict archive-name allowlist + realpath traversal confinement.
 *    It never reuses UpdraftPlus' own _wpnonce download flow.
 *  - RESTORE has no supported programmatic entrypoint and can brick the target mid-
 *    request (it swaps the running process's own option/user rows). So /backup/restore
 *    defaults to mode=assist: it verifies the set is restorable and returns the operator
 *    the set to click Restore on + the cross-domain `wp search-replace` command.
 *
 * @package LuwiPress
 * @since   3.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Backup_Bridge {

	/** @var LuwiPress_Backup_Bridge|null */
	private static $instance = null;

	/** File entities we recognize (matches get_backupable_file_entities + db). */
	const ENTITIES = array( 'db', 'plugins', 'themes', 'uploads', 'mu-plugins', 'others' );

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	/* ---------------------------------------------------------------------
	 * UpdraftPlus availability + safe accessors
	 * ------------------------------------------------------------------- */

	private function up_available() {
		global $updraftplus;
		return is_a( $updraftplus, 'UpdraftPlus' );
	}

	private function not_available() {
		return new WP_Error(
			'updraftplus_inactive',
			__( 'UpdraftPlus is not active on this site.', 'luwipress' ),
			array( 'status' => 503 )
		);
	}

	/** Absolute, normalized backups dir (untrailingslashed). */
	private function updraft_dir() {
		global $updraftplus;
		if ( method_exists( $updraftplus, 'backups_dir_location' ) ) {
			return untrailingslashit( $updraftplus->backups_dir_location() );
		}
		$raw = get_option( 'updraft_dir' );
		if ( empty( $raw ) ) {
			return untrailingslashit( WP_CONTENT_DIR . '/updraft' );
		}
		if ( '/' !== substr( $raw, 0, 1 ) && '\\' !== substr( $raw, 0, 1 ) && ! preg_match( '#^[A-Za-z]:#', $raw ) ) {
			$raw = trailingslashit( WP_CONTENT_DIR ) . $raw;
		}
		return untrailingslashit( $raw );
	}

	/** Fresh-from-DB history (dodges stale autoloaded/object-cache value during long jobs). */
	private function get_history() {
		if ( class_exists( 'UpdraftPlus_Backup_History' ) ) {
			if ( method_exists( 'UpdraftPlus_Backup_History', 'always_get_from_db' ) ) {
				UpdraftPlus_Backup_History::always_get_from_db();
			}
			$h = UpdraftPlus_Backup_History::get_history();
		} else {
			$h = get_option( 'updraft_backup_history', array() );
			if ( ! is_array( $h ) ) {
				$h = array();
			}
			krsort( $h );
		}
		return is_array( $h ) ? $h : array();
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------- */

	public function register_endpoints() {
		$ns = 'luwipress/v1';
		$perm = array( $this, 'check_permission' );

		register_rest_route( $ns, '/backup/diag', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_diag' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $ns, '/backup/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_run' ),
			'permission_callback' => $perm,
			'args'                => array(
				'scope'    => array( 'type' => 'string',  'required' => false, 'default' => 'full', 'sanitize_callback' => 'sanitize_key' ),
				'nocloud'  => array( 'type' => 'boolean', 'required' => false, 'default' => true ),
				'label'    => array( 'type' => 'string',  'required' => false, 'default' => 'LuwiPress migration backup', 'sanitize_callback' => 'sanitize_text_field' ),
				'entities' => array( 'type' => 'array',   'required' => false ),
			),
		) );

		register_rest_route( $ns, '/backup/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_status' ),
			'permission_callback' => $perm,
			'args'                => array(
				'nonce' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $ns, '/backup/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_list' ),
			'permission_callback' => $perm,
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
			),
		) );

		register_rest_route( $ns, '/backup/download', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_download' ),
			'permission_callback' => $perm,
			'args'                => array(
				'file' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_file_name' ),
			),
		) );

		// ---- Target-side (migration ingest) ----
		register_rest_route( $ns, '/backup/pull', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_pull' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $ns, '/backup/rescan', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_rescan' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $ns, '/backup/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_restore' ),
			'permission_callback' => $perm,
		) );
	}

	/* ----------------------------- DIAG ------------------------------- */

	public function handle_diag( $request ) {
		global $updraftplus;
		$available = $this->up_available();

		$version = null;
		if ( defined( 'UPDRAFTPLUS_VERSION' ) ) {
			$version = UPDRAFTPLUS_VERSION;
		} elseif ( $available && isset( $updraftplus->version ) ) {
			$version = $updraftplus->version;
		}

		$cron_disabled = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );

		return rest_ensure_response( array(
			'updraftplus_active'   => $available,
			'version'              => $version,
			'updraft_dir'          => $available ? $this->updraft_dir() : null,
			'updraft_dir_writable' => $available ? is_writable( $this->updraft_dir() ) : false,
			'history_class'        => class_exists( 'UpdraftPlus_Backup_History' ),
			'can_trigger_hook'     => $available && (bool) has_action( 'updraft_backupnow_backup_all' ),
			'can_boot_backup'      => $available && method_exists( $updraftplus, 'boot_backup' ),
			'wp_cron_disabled'     => $cron_disabled,
			'cron_warning'         => $cron_disabled ? __( 'WP-Cron is disabled; a real backup may stall after the first chunk unless system cron processes updraft_backup_resume. Verify completion via /backup/status.', 'luwipress' ) : null,
			'next_scheduled_full'  => $available ? wp_next_scheduled( 'updraft_backup' ) : null,
		) );
	}

	/* ------------------------------ RUN ------------------------------- */

	public function handle_run( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}
		global $updraftplus;

		$scope    = $request->get_param( 'scope' );
		$nocloud  = $request->get_param( 'nocloud' ) ? 1 : 0;
		$label    = $request->get_param( 'label' );
		$entities = $request->get_param( 'entities' );

		$before = array();
		foreach ( $this->get_history() as $ts => $set ) {
			if ( ! empty( $set['nonce'] ) ) {
				$before[ $set['nonce'] ] = true;
			}
		}
		$pre_oneshot = get_site_option( 'updraft_oneshotnonce' );

		$options = array( 'nocloud' => $nocloud, 'label' => $label );
		if ( is_array( $entities ) && ! empty( $entities ) ) {
			$allowed = array_values( array_intersect( $entities, array( 'plugins', 'themes', 'uploads', 'others' ) ) );
			if ( $allowed ) {
				$options['restrict_files_to_override'] = $allowed;
			}
		}

		$used = 'hook';
		switch ( $scope ) {
			case 'db':
				if ( has_action( 'updraft_backupnow_backup_database' ) ) {
					do_action( 'updraft_backupnow_backup_database', $options );
				} elseif ( method_exists( $updraftplus, 'boot_backup' ) ) {
					$used = 'boot_backup';
					$updraftplus->boot_backup( 0, 1, false, false, $nocloud ? 'none' : false, $options );
				} else {
					return $this->not_available();
				}
				break;

			case 'files':
				if ( has_action( 'updraft_backupnow_backup' ) ) {
					do_action( 'updraft_backupnow_backup', $options );
				} elseif ( method_exists( $updraftplus, 'boot_backup' ) ) {
					$used     = 'boot_backup';
					$restrict = isset( $options['restrict_files_to_override'] ) ? $options['restrict_files_to_override'] : false;
					$updraftplus->boot_backup( 1, 0, $restrict, false, $nocloud ? 'none' : false, $options );
				} else {
					return $this->not_available();
				}
				break;

			case 'full':
			default:
				if ( has_action( 'updraft_backupnow_backup_all' ) ) {
					do_action( 'updraft_backupnow_backup_all', $options );
				} elseif ( method_exists( $updraftplus, 'boot_backup' ) ) {
					$used     = 'boot_backup';
					$restrict = isset( $options['restrict_files_to_override'] ) ? $options['restrict_files_to_override'] : false;
					$updraftplus->boot_backup( 1, 1, $restrict, false, $nocloud ? 'none' : false, $options );
				} else {
					return $this->not_available();
				}
				break;
		}

		$new_nonce    = null;
		$post_oneshot = get_site_option( 'updraft_oneshotnonce' );
		if ( $post_oneshot && $post_oneshot !== $pre_oneshot ) {
			$new_nonce = $post_oneshot;
		} else {
			foreach ( $this->get_history() as $ts => $set ) {
				if ( ! empty( $set['nonce'] ) && empty( $before[ $set['nonce'] ] ) ) {
					$new_nonce = $set['nonce'];
					break;
				}
			}
			if ( ! $new_nonce ) {
				$new_nonce = $this->scan_cron_for_running_nonce();
			}
		}

		LuwiPress_Logger::log(
			sprintf( 'Backup triggered (scope=%s, nocloud=%d) via %s; nonce=%s', $scope, $nocloud, $used, $new_nonce ? $new_nonce : 'unknown' ),
			'info',
			array( 'module' => 'backup-bridge' )
		);

		return rest_ensure_response( array(
			'triggered' => true,
			'scope'     => $scope,
			'nocloud'   => (bool) $nocloud,
			'entry'     => $used,
			'nonce'     => $new_nonce,
			'note'      => __( 'Backup started synchronously. Poll /backup/status. Completion of a non-trivial backup depends on WP-Cron processing updraft_backup_resume.', 'luwipress' ),
		) );
	}

	/** Scan cron for a live updraft_backup_resume event nonce. */
	private function scan_cron_for_running_nonce() {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : get_option( 'cron' );
		if ( ! is_array( $cron ) ) {
			return null;
		}
		foreach ( $cron as $ts => $hooks ) {
			if ( empty( $hooks['updraft_backup_resume'] ) ) {
				continue;
			}
			foreach ( $hooks['updraft_backup_resume'] as $event ) {
				if ( isset( $event['args'][1] ) ) {
					return $event['args'][1];
				}
			}
		}
		return null;
	}

	/* ----------------------------- STATUS ----------------------------- */

	public function handle_status( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}
		global $updraftplus;

		$want_nonce   = $request->get_param( 'nonce' );
		$history      = $this->get_history();
		$running_code = method_exists( $updraftplus, 'is_backup_running' ) ? $updraftplus->is_backup_running() : false;

		$job = null;
		foreach ( $history as $ts => $set ) {
			if ( empty( $set['nonce'] ) ) {
				continue;
			}
			if ( $want_nonce && $set['nonce'] !== $want_nonce ) {
				continue;
			}
			$jobdata = method_exists( $updraftplus, 'jobdata_getarray' )
				? $updraftplus->jobdata_getarray( $set['nonce'] )
				: get_site_option( 'updraft_jobdata_' . $set['nonce'], array() );

			if ( empty( $jobdata ) ) {
				continue;
			}
			$jobstatus = isset( $jobdata['jobstatus'] ) ? $jobdata['jobstatus'] : 'unknown';
			$finished  = ( 'finished' === $jobstatus );

			if ( ! $want_nonce && $finished ) {
				continue;
			}

			$job = array(
				'nonce'        => $set['nonce'],
				'jobstatus'    => $jobstatus,
				'finished'     => $finished,
				'reason_code'  => $running_code,
				'percent'      => $this->compute_percent( $jobdata ),
				'started'      => isset( $jobdata['backup_time'] ) ? (int) $jobdata['backup_time'] : (int) $ts,
				'job_type'     => isset( $jobdata['job_type'] ) ? $jobdata['job_type'] : null,
				'service'      => isset( $jobdata['service'] ) ? $jobdata['service'] : null,
				'resume_due'   => wp_next_scheduled( 'updraft_backup_resume' ),
			);
			break;
		}

		if ( null === $job ) {
			return rest_ensure_response( array(
				'is_running'  => false,
				'reason_code' => $running_code,
				'job'         => null,
			) );
		}

		return rest_ensure_response( array(
			'is_running'  => ! $job['finished'],
			'reason_code' => $running_code,
			'job'         => $job,
		) );
	}

	/** Coarse 0..100 from substatus arrays; null if not computable. */
	private function compute_percent( $jobdata ) {
		if ( ! empty( $jobdata['uploading_substatus']['t'] ) ) {
			$t = max( (int) $jobdata['uploading_substatus']['t'], 1 );
			$i = (int) $jobdata['uploading_substatus']['i'];
			$p = (float) ( isset( $jobdata['uploading_substatus']['p'] ) ? $jobdata['uploading_substatus']['p'] : 0 );
			return (int) floor( 100 * min( ( $i + $p ) / $t, 1 ) );
		}
		if ( ! empty( $jobdata['dbcreating_substatus']['a'] ) ) {
			$a = max( (int) $jobdata['dbcreating_substatus']['a'], 1 );
			$i = (int) $jobdata['dbcreating_substatus']['i'];
			return (int) floor( 100 * min( $i / $a, 1 ) );
		}
		$map = array(
			'begun' => 5, 'filescreating' => 30, 'filescreated' => 55,
			'clouduploading' => 70, 'partialclouduploading' => 70,
			'pruning' => 90, 'finished' => 100,
		);
		$st = isset( $jobdata['jobstatus'] ) ? $jobdata['jobstatus'] : '';
		foreach ( $map as $k => $v ) {
			if ( 0 === strpos( (string) $st, $k ) ) {
				return $v;
			}
		}
		return null;
	}

	/* ------------------------------ LIST ------------------------------ */

	public function handle_list( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}
		global $updraftplus;

		$limit       = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$updraft_dir = $this->updraft_dir();
		$history     = $this->get_history();

		$sets  = array();
		$count = 0;
		foreach ( $history as $ts => $set ) {
			if ( $count >= $limit ) {
				break;
			}
			$count++;

			$total = method_exists( $updraftplus, 'get_total_backup_size' ) ? $updraftplus->get_total_backup_size( $set ) : null;

			$entities = array();
			$complete = true;
			foreach ( self::ENTITIES as $ent ) {
				$keys = ( 'db' === $ent )
					? array_filter( array_keys( $set ), function ( $k ) { return preg_match( '/^db[0-9]*$/', $k ); } )
					: ( isset( $set[ $ent ] ) ? array( $ent ) : array() );

				foreach ( $keys as $k ) {
					$files = is_array( $set[ $k ] ) ? $set[ $k ] : array( $set[ $k ] );
					foreach ( $files as $idx => $fname ) {
						$path     = $updraft_dir . '/' . $fname;
						$on_disk  = is_file( $path );
						if ( ! $on_disk ) {
							$complete = false;
						}
						$size_key = ( 0 === $idx ) ? ( $ent . '-size' ) : ( $ent . ( $idx + 1 ) . '-size' );
						$entities[ $ent ][] = array(
							'file'         => $fname,
							'index'        => $idx,
							'on_disk'      => $on_disk,
							'size'         => $on_disk ? filesize( $path ) : ( isset( $set[ $size_key ] ) ? (int) $set[ $size_key ] : null ),
							'download_url' => rest_url( 'luwipress/v1/backup/download' ) . '?file=' . rawurlencode( $fname ),
						);
					}
				}
			}

			$sets[] = array(
				'timestamp'   => (int) $ts,
				'date'        => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $ts ) ),
				'nonce'       => isset( $set['nonce'] ) ? $set['nonce'] : '',
				'label'       => isset( $set['label'] ) ? $set['label'] : '',
				'service'     => isset( $set['service'] ) ? $set['service'] : 'none',
				'total_bytes' => ( false === $total || null === $total ) ? null : $total,
				'complete'    => $complete,
				'entities'    => $entities,
			);
		}

		return rest_ensure_response( array(
			'updraft_dir' => $updraft_dir,
			'count'       => count( $sets ),
			'sets'        => $sets,
		) );
	}

	/* ---------------------------- DOWNLOAD ---------------------------- */

	public function handle_download( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}

		$file = $request->get_param( 'file' );
		if ( ! preg_match( '/^(backup_[\-0-9]{15}_.*_[0-9a-f]{12}-[\-a-z]+[0-9]*\.(zip|gz|gz\.crypt)|log\.[0-9a-f]{12}\.txt)$/', $file ) ) {
			return new WP_Error( 'bad_file', __( 'File name does not match a backup archive pattern.', 'luwipress' ), array( 'status' => 400 ) );
		}

		$updraft_dir = $this->updraft_dir();
		$path        = $updraft_dir . '/' . $file;
		$real        = realpath( $path );
		$real_dir    = realpath( $updraft_dir );

		if ( ! $real || ! $real_dir || 0 !== strpos( $real, $real_dir ) || ! is_file( $real ) ) {
			return new WP_Error( 'not_found', __( 'Archive not found on disk (may live only in remote storage).', 'luwipress' ), array( 'status' => 404 ) );
		}

		LuwiPress_Logger::log( 'Backup archive downloaded: ' . $file, 'info', array( 'module' => 'backup-bridge' ) );

		$size = filesize( $real );

		// 64-bit guard: on a 32-bit PHP build filesize() wraps for >2GB archives, which
		// would emit a bogus/negative Content-Length and make the client truncate. Bail loud.
		if ( PHP_INT_SIZE < 8 && $size > 2147483647 ) {
			return new WP_Error( 'too_large_32bit', __( 'Archive exceeds 2GB on a 32-bit PHP build; cannot stream a correct Content-Length.', 'luwipress' ), array( 'status' => 500 ) );
		}

		// A multi-GB server-to-server pull must survive the FPM request_terminate_timeout
		// and a client reconnect (curl -C -); without this the stream is SIGTERM'd mid-file
		// and the peer keeps a truncated archive.
		@set_time_limit( 0 );
		ignore_user_abort( true );

		// Honour a single HTTP Range so an interrupted large pull can resume (curl -C -).
		$start     = 0;
		$end       = $size - 1;
		$is_range  = false;
		$range_hdr = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $range_hdr && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range_hdr ), $m ) ) {
			$r_start = ( '' === $m[1] ) ? null : (int) $m[1];
			$r_end   = ( '' === $m[2] ) ? null : (int) $m[2];
			if ( null === $r_start && null !== $r_end ) {
				$start = max( 0, $size - $r_end ); // suffix range: last N bytes
			} elseif ( null !== $r_start ) {
				$start = $r_start;
				$end   = ( null !== $r_end ) ? min( $r_end, $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				if ( ob_get_level() ) {
					@ob_end_clean();
				}
				http_response_code( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$is_range = true;
		}

		if ( ob_get_level() ) {
			@ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Accel-Buffering: no' );

		if ( $is_range ) {
			http_response_code( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
			header( 'Content-Length: ' . ( $end - $start + 1 ) );
		} else {
			header( 'Content-Length: ' . $size );
		}

		$fh = fopen( $real, 'rb' );
		if ( $fh ) {
			if ( $start > 0 ) {
				fseek( $fh, $start );
			}
			$remaining = $end - $start + 1;
			$chunk     = 1024 * 256; // 256KB
			while ( $remaining > 0 && ! feof( $fh ) ) {
				$buf = fread( $fh, (int) min( $chunk, $remaining ) );
				if ( false === $buf ) {
					break;
				}
				echo $buf; // phpcs:ignore
				$remaining -= strlen( $buf );
				flush();
				if ( connection_aborted() ) {
					break;
				}
			}
			fclose( $fh );
		}
		exit;
	}

	/* ============================ TARGET SIDE ============================ */

	/** Pull a backup set's archives from a trusted source LuwiPress site into updraft_dir. */
	public function handle_pull( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}
		$source = untrailingslashit( esc_url_raw( (string) $request->get_param( 'source_url' ) ) );
		$token  = sanitize_text_field( (string) $request->get_param( 'source_token' ) );
		$files  = (array) $request->get_param( 'files' );

		if ( ! $source || ! $token || empty( $files ) ) {
			return new WP_Error( 'bad_request', 'source_url, source_token and files[] are required.', array( 'status' => 400 ) );
		}

		$updraft_dir = $this->updraft_dir();
		if ( ! is_writable( $updraft_dir ) ) {
			return new WP_Error( 'dir_unwritable', 'updraft_dir is not writable: ' . $updraft_dir, array( 'status' => 500 ) );
		}

		$results = array();
		foreach ( $files as $fname ) {
			$fname = sanitize_file_name( $fname );
			if ( ! preg_match( '/^backup_[\-0-9]{15}_.*_[0-9a-f]{12}-[\-a-z]+[0-9]*\.(zip|gz|gz\.crypt)$/', $fname ) ) {
				$results[] = array( 'file' => $fname, 'ok' => false, 'error' => 'bad_name' );
				continue;
			}

			$url  = $source . '/wp-json/luwipress/v1/backup/download?file=' . rawurlencode( $fname );
			$dest = $updraft_dir . '/' . $fname;

			$resp = wp_remote_get( $url, array(
				'timeout'  => 600,
				'stream'   => true,
				'filename' => $dest,
				'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
			) );

			if ( is_wp_error( $resp ) ) {
				$results[] = array( 'file' => $fname, 'ok' => false, 'error' => $resp->get_error_message() );
				continue;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			if ( 200 !== (int) $code || ! is_file( $dest ) || filesize( $dest ) === 0 ) {
				@unlink( $dest );
				$results[] = array( 'file' => $fname, 'ok' => false, 'error' => 'http_' . $code );
				continue;
			}
			$results[] = array( 'file' => $fname, 'ok' => true, 'size' => filesize( $dest ) );
		}

		$ok = count( array_filter( $results, function ( $r ) { return ! empty( $r['ok'] ); } ) );
		LuwiPress_Logger::log( sprintf( 'Pulled %d/%d archives from %s', $ok, count( $files ), $source ), 'info', array( 'module' => 'backup-bridge' ) );

		return rest_ensure_response( array(
			'pulled'      => $ok,
			'total'       => count( $files ),
			'results'     => $results,
			'updraft_dir' => $updraft_dir,
			'next'        => 'POST /backup/rescan, then POST /backup/restore (mode=assist).',
		) );
	}

	/** Make UpdraftPlus re-enumerate updraft_dir so the pulled set becomes restorable. */
	public function handle_rescan( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}
		$rebuilt = false;
		if ( class_exists( 'UpdraftPlus_Backup_History' ) && method_exists( 'UpdraftPlus_Backup_History', 'rebuild_backup_history' ) ) {
			UpdraftPlus_Backup_History::rebuild_backup_history();
			$rebuilt = true;
		}
		$list = $this->handle_list( $request );
		$sets = ( $list instanceof WP_REST_Response ) ? ( $list->get_data()['sets'] ?? array() ) : array();

		return rest_ensure_response( array(
			'rescanned' => $rebuilt,
			'note'      => $rebuilt ? null : 'rebuild_backup_history() unavailable in this UpdraftPlus build; rescan manually (Existing Backups -> Rescan local folder).',
			'sets'      => $sets,
		) );
	}

	/**
	 * mode=assist (DEFAULT, SAFE): verify the set is restorable, return the set to
	 *   click Restore on + the post-restore search-replace command for the domain change.
	 * mode=execute (OPT-IN, FRAGILE): intentionally not auto-run against production.
	 */
	public function handle_restore( $request ) {
		if ( ! $this->up_available() ) {
			return $this->not_available();
		}
		$mode      = sanitize_key( (string) ( $request->get_param( 'mode' ) ?: 'assist' ) );
		$timestamp = (int) $request->get_param( 'timestamp' );
		$old_url   = esc_url_raw( (string) $request->get_param( 'old_url' ) );
		$new_url   = esc_url_raw( (string) ( $request->get_param( 'new_url' ) ?: home_url() ) );

		$set = class_exists( 'UpdraftPlus_Backup_History' )
			? UpdraftPlus_Backup_History::get_history( $timestamp )
			: ( $this->get_history()[ $timestamp ] ?? array() );

		if ( empty( $set ) ) {
			return new WP_Error( 'set_not_found', 'No backup set at that timestamp; run /backup/rescan first.', array( 'status' => 404 ) );
		}

		$encrypted = false;
		foreach ( (array) ( $set['db'] ?? array() ) as $f ) {
			if ( false !== strpos( (string) $f, '.gz.crypt' ) ) {
				$encrypted = true;
			}
		}

		$search_replace = sprintf(
			'wp search-replace %s %s --all-tables --report-changes-only',
			escapeshellarg( $old_url ),
			escapeshellarg( $new_url )
		);

		if ( 'assist' === $mode ) {
			return rest_ensure_response( array(
				'mode'           => 'assist',
				'restorable'     => ! $encrypted,
				'encrypted_db'   => $encrypted,
				'set_timestamp'  => $timestamp,
				'set_nonce'      => $set['nonce'] ?? '',
				'instructions'   => $encrypted
					? 'DB archive is encrypted (.gz.crypt) — UpdraftPlus Premium is required to restore it. The free path cannot proceed.'
					: 'Open UpdraftPlus -> Existing Backups, find this set, click Restore. AFTER restore, run the search_replace command to fix the domain (free UpdraftPlus does NOT rewrite URLs automatically).',
				'search_replace' => $search_replace,
				'domain_change'  => array( 'from' => $old_url, 'to' => $new_url ),
			) );
		}

		// mode=execute — explicit opt-in, fragile, unsupported. Intentionally not auto-run.
		if ( ! $request->get_param( 'confirm_unsupported' ) ) {
			return new WP_Error( 'confirm_required', 'mode=execute drives an unsupported programmatic restore that can brick this site. Re-send with confirm_unsupported=true after a target snapshot — and prefer mode=assist.', array( 'status' => 412 ) );
		}
		if ( $encrypted ) {
			return new WP_Error( 'encrypted', 'Encrypted DB requires UpdraftPlus Premium; cannot restore on free.', array( 'status' => 501 ) );
		}
		return new WP_Error( 'not_implemented_safely', 'Programmatic execute mode is intentionally not auto-run against production; use mode=assist (click Restore in the UpdraftPlus UI, then run the returned search-replace).', array( 'status' => 501 ) );
	}
}

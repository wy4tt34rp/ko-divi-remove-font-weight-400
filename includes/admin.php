<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KO_Font_Weight_400_Admin {
	const PREFIX = 'ko_fw400_backup_';
	const LATEST = 'ko_fw400_latest_run';
	const RUN = 'ko_fw400_run_';
	const SETTINGS = 'ko_fw400_schedule';
	const STATE = 'ko_fw400_cron_state';
	const DAILY = 'ko_fw400_midnight';
	const STEP = 'ko_fw400_cron_step';

	public static function menu() {
		add_management_page( 'KO: Divi font-weight=400px Fix', 'KO: Font Weight Fix', 'manage_options', 'ko-font-weight-fix', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$runs = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100", $wpdb->esc_like( self::RUN ) . '%' ) );
		$settings = get_option( self::SETTINGS, array() );
		$next = wp_next_scheduled( self::DAILY );
		wp_enqueue_script( 'ko-fw400-admin', plugins_url( '../assets/admin.js', __FILE__ ), array(), '2.0.0', true );
		wp_localize_script( 'ko-fw400-admin', 'koFw400', array(
			'url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ko_fw400' ),
		) );
		?>
		<div class="wrap">
			<h1>KO: Divi font-weight=400px Fix</h1>
			<p>Permanently remove inline <code>font-weight: 400</code> from saved content and excerpts in posts, pages, custom post types, and Divi Library / Theme Builder records.</p>
			<p>Other inline styles and font weights stay intact. Preview first, then run cleanup. Original affected content is backed up privately in this site's database before each change.</p>
			<p>Revisions, attachments, trash, auto-drafts, widget settings, custom fields, and theme files are excluded. This tool does not prevent future pasted styles.</p>
			<p>
				<button class="button" id="ko-preview">1. Preview cleanup</button>
				<button class="button button-primary" id="ko-clean" disabled>2. Permanently clean saved content</button>
			</p>
			<p><label for="ko-run">Backup to restore:</label>
				<select id="ko-run">
					<option value="">Choose a cleanup</option>
					<?php foreach ( $runs as $saved ) :
						$info = maybe_unserialize( $saved->option_value );
						$id = substr( $saved->option_name, strlen( self::RUN ) ); ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $info['date'] . ' / ' . $info['source'] . ' / ' . $info['status'] . ' / ' . $info['changed'] . ' cleaned' ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button" id="ko-restore" disabled>Restore selected cleanup</button>
			</p>
			<p>Restore skips records edited after cleanup. Disable the schedule before restoring if you want the original styles to remain.</p>
			<hr>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ko_fw400_schedule">
				<?php wp_nonce_field( 'ko_fw400_schedule' ); ?>
				<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Automatically clean saved content daily at midnight</label></p>
				<p>Site timezone: <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>.
				Next start: <?php echo esc_html( $next ? wp_date( 'Y-m-d H:i T', $next ) : 'Not scheduled' ); ?>.</p>
				<p>WP-Cron starts on the next request after midnight. Exact timing requires a server cron. Each run backs up affected records. Keep this plugin active for scheduled cleanup.</p>
				<?php submit_button( 'Save schedule', 'secondary', 'submit', false ); ?>
			</form>
			<p>Last scheduled result: <?php echo esc_html( get_option( 'ko_fw400_cron_result', 'Not run yet.' ) ); ?></p>
			<hr>
			<p id="ko-status" role="status" aria-live="polite">Ready. Nothing runs on public page views.</p>
			<ul id="ko-results"></ul>
			<p>After cleanup or restore, clear Divi's generated CSS and all page/CDN caches, then check the affected pages. You can deactivate and delete this plugin after cleanup; the changes remain. Backups are retained in the database.</p>
		</div>
		<?php
	}

	/** Atomic compare-and-swap prevents overwriting content edited since it was read. */
	public static function replace( $id, $before, $after ) {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_content = %s, post_excerpt = %s WHERE ID = %d AND BINARY post_content = BINARY %s AND BINARY post_excerpt = BINARY %s",
			$after['post_content'], $after['post_excerpt'], $id, $before['post_content'], $before['post_excerpt']
		) );
		if ( false === $result ) {
			throw new RuntimeException( 'Database update failed. The original content backup was retained.' );
		}
		if ( 1 === $result ) {
			clean_post_cache( $id );
		}
		return 1 === $result;
	}

	public static function batch( $mode, $cursor, $run ) {
		global $wpdb;
		$out = array( 'cursor' => $cursor, 'changed' => 0, 'skipped' => 0, 'scanned' => 0, 'items' => array(), 'run' => $run );
		if ( 'restore' === $mode ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_id, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_name LIKE %s ORDER BY option_id ASC LIMIT 25",
				$cursor, $wpdb->esc_like( self::PREFIX . $run . '_' ) . '%'
			) );
		} else {
			$like = '%' . $wpdb->esc_like( 'font-weight' ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts}
				 WHERE ID > %d AND post_status NOT IN ('trash','auto-draft')
				 AND post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','customize_changeset','user_request','oembed_cache')
				 AND (post_content LIKE %s OR post_excerpt LIKE %s) ORDER BY ID ASC LIMIT 25",
				$cursor, $like, $like
			) );
		}
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Database read failed. Stop and check the database connection.' );
		}
		foreach ( $rows as $row ) {
			++$out['scanned'];
			if ( 'restore' === $mode ) {
				$out['cursor'] = (int) $row->option_id;
				$backup = maybe_unserialize( $row->option_value );
				if ( ! is_array( $backup ) || ! isset( $backup['id'], $backup['before'], $backup['after'] ) ) {
					throw new RuntimeException( 'A backup could not be read. No further records were restored.' );
				}
				if ( ! current_user_can( 'edit_post', $backup['id'] ) ) {
					++$out['skipped'];
					continue;
				}
				$restored = self::replace( $backup['id'], $backup['after'], $backup['before'] );
				++$out[ $restored ? 'changed' : 'skipped' ];
				continue;
			}
			$out['cursor'] = (int) $row->ID;
			if ( ! current_user_can( 'edit_post', $row->ID ) ) {
				++$out['skipped'];
				continue;
			}
			$before = array( 'post_content' => $row->post_content, 'post_excerpt' => $row->post_excerpt );
			$after = array_map( array( 'KO_Divi_Remove_Font_Weight_400', 'clean_html' ), $before );
			if ( $before === $after ) {
				continue;
			}
			if ( 'clean' === $mode ) {
				// A unique, non-autoloaded option is durable before the post is touched.
				$key = self::PREFIX . $run . '_' . $row->ID;
				$backup = array( 'id' => (int) $row->ID, 'before' => $before, 'after' => $after );
				if ( ! add_option( $key, $backup, '', false ) && get_option( $key ) !== $backup ) {
					throw new RuntimeException( 'Backup could not be saved or differs from this content. No change was made to this record.' );
				}
				if ( ! self::replace( $row->ID, $before, $after ) ) {
					++$out['skipped'];
					continue;
				}
			}
			++$out['changed'];
			$out['items'][] = '#' . $row->ID . ' ' . $row->post_title;
		}
		$out['done'] = count( $rows ) < 25;
		return $out;
	}

	public static function acquire_lock() {
		global $wpdb;
		$key = 'ko_fw400_lock';
		$old = get_option( $key );
		if ( is_string( $old ) && (int) $old < time() - 900 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $old ) );
			wp_cache_delete( $key, 'options' );
		}
		$token = time() . ':' . wp_generate_uuid4();
		return add_option( $key, $token, '', false ) ? $token : false;
	}

	public static function release_lock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = 'ko_fw400_lock' AND option_value = %s", $token ) );
		wp_cache_delete( 'ko_fw400_lock', 'options' );
	}

	public static function new_run( $source ) {
		$run = wp_generate_uuid4();
		if ( ! add_option( self::RUN . $run, array( 'date' => wp_date( 'Y-m-d H:i:s T' ), 'source' => $source, 'status' => 'Started', 'changed' => 0, 'skipped' => 0 ), '', false ) ) {
			throw new RuntimeException( 'Could not save the cleanup identifier.' );
		}
		update_option( self::LATEST, $run, false );
		return $run;
	}

	public static function record_result( $run, $result ) {
		$info = get_option( self::RUN . $run );
		$info['changed'] += $result['changed'];
		$info['skipped'] += $result['skipped'];
		$info['status'] = $result['done'] ? 'Complete' : 'In progress';
		update_option( self::RUN . $run, $info, false );
		return $info;
	}

	/** Recalculate local midnight each day so DST does not move the start time. */
	public static function next_midnight( $now = null, $timezone = null ) {
		$timezone = $timezone ?: wp_timezone();
		$now = null === $now ? time() : $now;
		return ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone )->modify( 'tomorrow' )->setTime( 0, 0 )->getTimestamp();
	}

	public static function schedule_next() {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			$result = wp_schedule_single_event( self::next_midnight(), self::DAILY, array(), true );
			if ( is_wp_error( $result ) || ! $result ) {
				throw new RuntimeException( 'Could not schedule the next midnight cleanup.' );
			}
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::STEP );
		delete_option( self::STATE );
		delete_option( self::SETTINGS );
	}

	public static function save_schedule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Administrator access is required.' );
		}
		check_admin_referer( 'ko_fw400_schedule' );
		$lock = self::acquire_lock();
		if ( ! $lock ) {
			wp_die( 'A cleanup batch is running. Please try saving the schedule again shortly.' );
		}
		try {
			self::deactivate();
			if ( ! empty( $_POST['enabled'] ) ) {
				update_option( self::SETTINGS, array( 'enabled' => true, 'user' => get_current_user_id() ), false );
				self::schedule_next();
			}
		} catch ( Throwable $error ) {
			self::release_lock( $lock );
			wp_die( esc_html( $error->getMessage() ) );
		}
		self::release_lock( $lock );
		wp_safe_redirect( admin_url( 'tools.php?page=ko-font-weight-fix' ) );
		exit;
	}

	public static function cron() {
		$settings = get_option( self::SETTINGS, array() );
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		try {
			self::schedule_next();
		} catch ( Throwable $error ) {
			update_option( 'ko_fw400_cron_result', $error->getMessage(), false );
			return;
		}
		$lock = self::acquire_lock();
		if ( ! $lock ) {
			try {
				self::queue_step();
			} catch ( Throwable $error ) {
				update_option( 'ko_fw400_cron_result', $error->getMessage(), false );
			}
			return;
		}
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( (int) $settings['user'] );
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new RuntimeException( 'Scheduled cleanup stopped: the administrator who enabled it no longer has permission. Save the schedule again as an administrator.' );
			}
			$state = get_option( self::STATE );
			if ( ! $state ) {
				$state = array( 'run' => self::new_run( 'Midnight schedule' ), 'cursor' => 0 );
				update_option( self::STATE, $state, false );
			}
			$result = self::batch( 'clean', $state['cursor'], $state['run'] );
			$info = self::record_result( $state['run'], $result );
			update_option( 'ko_fw400_cron_result', wp_date( 'Y-m-d H:i:s T' ) . ': ' . $info['status'] . ', ' . $info['changed'] . ' cleaned, ' . $info['skipped'] . ' skipped.', false );
			if ( $result['done'] ) {
				delete_option( self::STATE );
				wp_clear_scheduled_hook( self::STEP );
			} else {
				$state['cursor'] = $result['cursor'];
				update_option( self::STATE, $state, false );
				self::queue_step();
			}
		} catch ( Throwable $error ) {
			update_option( 'ko_fw400_cron_result', wp_date( 'Y-m-d H:i:s T' ) . ': Stopped. ' . $error->getMessage(), false );
			delete_option( self::STATE );
		} finally {
			wp_set_current_user( $previous_user );
			self::release_lock( $lock );
		}
	}

	public static function queue_step() {
		if ( ! wp_next_scheduled( self::STEP ) ) {
			$result = wp_schedule_single_event( time() + 30, self::STEP, array(), true );
			if ( is_wp_error( $result ) || ! $result ) {
				throw new RuntimeException( 'Could not queue the next cleanup batch. Backups were retained.' );
			}
		}
	}

	public static function timezone_changed() {
		$settings = get_option( self::SETTINGS, array() );
		if ( ! empty( $settings['enabled'] ) ) {
			wp_clear_scheduled_hook( self::DAILY );
			try {
				self::schedule_next();
			} catch ( Throwable $error ) {
				update_option( 'ko_fw400_cron_result', $error->getMessage(), false );
			}
		}
	}

	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Administrator access is required.', 403 );
		}
		check_ajax_referer( 'ko_fw400', 'nonce' );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$cursor = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$run = isset( $_POST['run'] ) ? sanitize_key( $_POST['run'] ) : '';
		if ( ! in_array( $mode, array( 'preview', 'clean', 'restore' ), true ) ) {
			wp_send_json_error( 'Unknown operation.', 400 );
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			wp_send_json_error( 'WordPress 6.2 or newer is required.', 400 );
		}
		$lock = self::acquire_lock();
		if ( ! $lock ) {
			wp_send_json_error( 'Another batch is running. Retry shortly. Interrupted locks expire after 15 minutes.', 409 );
		}
		try {
			if ( get_option( self::STATE ) ) {
				throw new RuntimeException( 'Scheduled cleanup is running. Wait for it to finish, or disable the schedule to stop it between batches.' );
			}
			if ( 'clean' === $mode && '' === $run && 0 === $cursor ) {
				$run = self::new_run( 'Manual' );
			}
			if ( 'preview' !== $mode && ( '' === $run || ! get_option( self::RUN . $run ) ) ) {
				throw new RuntimeException( 'Cleanup backup not found. Reload this screen.' );
			}
			$result = self::batch( $mode, $cursor, $run );
			if ( 'clean' === $mode ) {
				self::record_result( $run, $result );
			}
		} catch ( Throwable $error ) {
			self::release_lock( $lock );
			wp_send_json_error( $error->getMessage(), 500 );
		}
		self::release_lock( $lock );
		wp_send_json_success( $result );
	}
}

add_action( 'admin_menu', array( 'KO_Font_Weight_400_Admin', 'menu' ) );
add_action( 'wp_ajax_ko_fw400', array( 'KO_Font_Weight_400_Admin', 'ajax' ) );
add_action( 'admin_post_ko_fw400_schedule', array( 'KO_Font_Weight_400_Admin', 'save_schedule' ) );
add_action( KO_Font_Weight_400_Admin::DAILY, array( 'KO_Font_Weight_400_Admin', 'cron' ) );
add_action( KO_Font_Weight_400_Admin::STEP, array( 'KO_Font_Weight_400_Admin', 'cron' ) );
add_action( 'update_option_timezone_string', array( 'KO_Font_Weight_400_Admin', 'timezone_changed' ) );
add_action( 'update_option_gmt_offset', array( 'KO_Font_Weight_400_Admin', 'timezone_changed' ) );

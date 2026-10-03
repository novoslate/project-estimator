<?php
/**
 * Reliable CRM webhook delivery: sent after the response, retried with backoff,
 * logged on each lead, with failure alerts, manual resend, and a test button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Webhook {

	const META        = '_pe_webhook';
	const META_STATUS = '_pe_webhook_status';
	const CRON        = 'pe_webhook_retry';

	/** Seconds to wait before each retry. 6 attempts in total over about 9 hours. */
	const BACKOFF = array( 60, 300, 1800, 7200, 21600 );

	private static $after_response = array();

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'deliver' ) );
		add_action( 'shutdown', array( __CLASS__, 'run_after_response' ), 0 );
		add_action( 'admin_post_pe_webhook_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'wp_ajax_pe_webhook_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'add_meta_boxes_nse_lead', array( __CLASS__, 'meta_box' ) );
		add_filter( 'manage_nse_lead_posts_columns', array( __CLASS__, 'columns' ), 30 );
		add_action( 'manage_nse_lead_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-nse_lead', array( __CLASS__, 'bulk_actions' ), 20 );
		add_filter( 'handle_bulk_actions-edit-nse_lead', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ---------- Queue and deliver ---------- */

	public static function url_for( $lead_id ) {
		$est = (int) get_post_meta( $lead_id, '_nse_estimator_id', true );
		if ( $est && 'nse_estimator' === get_post_type( $est ) ) {
			$c = NSE_Config::get( $est );
			return $c['business']['webhook_url'];
		}
		return '';
	}

	public static function state( $lead_id ) {
		$s = get_post_meta( $lead_id, self::META, true );
		return is_array( $s ) ? wp_parse_args( $s, array( 'status' => 'pending', 'tries' => 0, 'attempts' => array(), 'next' => 0 ) ) : null;
	}

	private static function save_state( $lead_id, array $s ) {
		$s['attempts'] = array_slice( $s['attempts'], -12 );
		update_post_meta( $lead_id, self::META, $s );
		update_post_meta( $lead_id, self::META_STATUS, $s['status'] );
	}

	/**
	 * Called for a new lead. Sends after the visitor's response has been returned.
	 */
	public static function queue( $lead_id ) {
		if ( ! self::url_for( $lead_id ) ) {
			return;
		}
		self::save_state( $lead_id, array( 'status' => 'pending', 'tries' => 0, 'attempts' => array(), 'next' => 0 ) );
		self::$after_response[] = (int) $lead_id;
		/* Safety net: if this request dies before shutdown, cron still sends it. */
		wp_schedule_single_event( time() + 120, self::CRON, array( (int) $lead_id ) );
	}

	/**
	 * Flush the visitor's response first, then deliver.
	 */
	public static function run_after_response() {
		if ( ! self::$after_response ) {
			return;
		}
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		foreach ( self::$after_response as $id ) {
			self::deliver( $id );
		}
		self::$after_response = array();
	}

	/**
	 * The JSON body sent to the webhook. Same fields as before 1.9.0, plus event details.
	 */
	public static function payload( $lead_id, $attempt, $test = false ) {
		$lead = get_post_meta( $lead_id, '_nse_lead', true );
		$lead = is_array( $lead ) ? $lead : array();
		return array_merge(
			$lead,
			array(
				'event'            => 'lead.created',
				'test'             => (bool) $test,
				'lead_id'          => (int) $lead_id,
				'status'           => NSE_Status::get( $lead_id ),
				'delivery_attempt' => (int) $attempt,
				'site'             => home_url(),
			)
		);
	}

	/**
	 * POST JSON to a URL. Returns [ok, code, message, ms].
	 */
	public static function post( $url, array $body, $lead_id ) {
		$start = microtime( true );
		$res   = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array(
					'Content-Type'    => 'application/json',
					'User-Agent'      => 'ProjectEstimator/' . NSE_VERSION . '; ' . home_url(),
					'X-PE-Event'      => $body['event'],
					'X-PE-Lead-ID'    => (string) $lead_id,
					'X-PE-Attempt'    => (string) $body['delivery_attempt'],
				),
				'body'        => wp_json_encode( $body ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( 'http_request_failed' === $res->get_error_code() && false !== stripos( $msg, 'valid URL' ) ) {
				$msg = 'This address is not allowed. Webhook URLs must be public, for example https://hooks.zapier.com/...';
			}
			return array( false, 0, $msg, $ms );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$text = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) ) );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 160 ) : substr( $text, 0, 160 );
		return array( $code >= 200 && $code < 300, $code, $text ? $text : wp_remote_retrieve_response_message( $res ), $ms );
	}

	/**
	 * Try one delivery. Schedules a retry or sends an alert on failure.
	 */
	public static function deliver( $lead_id, $manual = false ) {
		$lead_id = (int) $lead_id;
		$s       = self::state( $lead_id );
		if ( ! $s || ( 'delivered' === $s['status'] && ! $manual ) ) {
			return $s;
		}
		$lock = 'pe_wh_lock_' . $lead_id;
		if ( get_transient( $lock ) ) {
			return $s;
		}
		set_transient( $lock, 1, 60 );

		$url = self::url_for( $lead_id );
		$s['tries']++;
		$attempt = count( $s['attempts'] ) + 1;
		if ( ! $url ) {
			$result = array( false, 0, 'No webhook URL is set on this estimator.', 0 );
		} else {
			$result = self::post( $url, self::payload( $lead_id, $attempt ), $lead_id );
		}
		$s['attempts'][] = array(
			'time'    => time(),
			'ok'      => $result[0],
			'code'    => $result[1],
			'message' => $result[2],
			'ms'      => $result[3],
			'manual'  => (bool) $manual,
		);
		wp_clear_scheduled_hook( self::CRON, array( $lead_id ) );

		if ( $result[0] ) {
			$s['status'] = 'delivered';
			$s['next']   = 0;
		} elseif ( $url && $s['tries'] <= count( self::BACKOFF ) ) {
			$s['status'] = 'retrying';
			$s['next']   = time() + self::BACKOFF[ $s['tries'] - 1 ];
			wp_schedule_single_event( $s['next'], self::CRON, array( $lead_id ) );
		} else {
			$s['status'] = 'failed';
			$s['next']   = 0;
			if ( ! $manual ) {
				self::alert( $lead_id, $s );
			}
		}
		self::save_state( $lead_id, $s );
		delete_transient( $lock );
		return $s;
	}

	/**
	 * Start a fresh round of attempts (after fixing a Zap, for example).
	 */
	public static function restart( $lead_id, $now = true ) {
		$s = self::state( $lead_id );
		$s = $s ? $s : array( 'status' => 'pending', 'tries' => 0, 'attempts' => array(), 'next' => 0 );
		$s['status'] = 'pending';
		$s['tries']  = 0;
		self::save_state( $lead_id, $s );
		if ( $now ) {
			return self::deliver( $lead_id, true );
		}
		wp_clear_scheduled_hook( self::CRON, array( (int) $lead_id ) );
		wp_schedule_single_event( time(), self::CRON, array( (int) $lead_id ) );
		return $s;
	}

	private static function alert( $lead_id, array $s ) {
		$settings = NSE_Settings::get();
		$to       = $settings['webhook_alert'] ? $settings['webhook_alert'] : get_option( 'admin_email' );
		$lead     = get_post_meta( $lead_id, '_nse_lead', true );
		$last     = end( $s['attempts'] );
		$lines    = array(
			'A quote request could not be sent to the CRM webhook after ' . count( $s['attempts'] ) . ' attempts over about 9 hours.',
			'',
			'Lead: ' . ( isset( $lead['name'] ) ? $lead['name'] : '#' . $lead_id ) . ( isset( $lead['phone'] ) ? ', ' . $lead['phone'] : '' ),
			'Last error: ' . ( $last['code'] ? 'HTTP ' . $last['code'] . ' ' : '' ) . $last['message'],
			'',
			'The lead is saved in WordPress. After fixing the webhook (for example, turning the Zap back on), open the lead and click "Resend now":',
			admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' ),
		);
		wp_mail( $to, 'CRM webhook failed: ' . get_bloginfo( 'name' ), implode( "\n", $lines ) );
	}

	/* ---------- Admin: lead screen ---------- */

	public static function labels() {
		return array(
			'delivered' => 'Delivered',
			'retrying'  => 'Retrying',
			'pending'   => 'Sending',
			'failed'    => 'Failed',
		);
	}

	public static function meta_box() {
		add_meta_box( 'pe_webhook', 'CRM webhook', array( __CLASS__, 'render_box' ), 'nse_lead', 'side', 'default' );
	}

	public static function render_box( $post ) {
		$s   = self::state( $post->ID );
		$url = self::url_for( $post->ID );
		if ( ! $s && ! $url ) {
			echo '<p class="description">No webhook URL is set on this estimator.</p>';
			return;
		}
		if ( $s ) {
			printf( '<p><span class="pe-wh pe-wh--%1$s">%2$s</span></p>', esc_attr( $s['status'] ), esc_html( self::labels()[ $s['status'] ] ) );
			if ( 'retrying' === $s['status'] && $s['next'] ) {
				echo '<p class="description">Next try: ' . esc_html( wp_date( 'M j, g:i a', $s['next'] ) ) . '</p>';
			}
			if ( $s['attempts'] ) {
				echo '<ul class="pe-wh-log">';
				foreach ( array_reverse( $s['attempts'] ) as $a ) {
					printf(
						'<li><strong>%s</strong> %s<br><span class="description">%s%s</span></li>',
						esc_html( $a['ok'] ? 'OK' : 'Failed' ),
						esc_html( wp_date( 'M j, g:i:s a', $a['time'] ) . ( ! empty( $a['manual'] ) ? ' (manual)' : '' ) ),
						esc_html( $a['code'] ? 'HTTP ' . $a['code'] . ': ' : '' ),
						esc_html( $a['message'] )
					);
				}
				echo '</ul>';
			}
		} else {
			echo '<p class="description">This lead was saved before delivery tracking, or the webhook was added later.</p>';
		}
		if ( $url ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pe_webhook_resend&lead=' . (int) $post->ID ), 'pe_webhook_resend_' . (int) $post->ID ) ),
				esc_html( $s && 'delivered' === $s['status'] ? 'Send again' : 'Resend now' )
			);
		}
		echo '<style>.pe-wh{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600}.pe-wh--delivered{background:#DFF3E4;color:#1F6B35}.pe-wh--retrying,.pe-wh--pending{background:#FFF4D6;color:#7A5A00}.pe-wh--failed{background:#FBE3E1;color:#9B2C1F}.pe-wh-log{margin:8px 0;max-height:220px;overflow:auto}.pe-wh-log li{margin-bottom:8px}</style>';
	}

	public static function handle_resend() {
		$id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		check_admin_referer( 'pe_webhook_resend_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'You do not have permission to resend this lead.' );
		}
		$s = self::restart( $id, true );
		wp_safe_redirect( add_query_arg( array( 'post' => $id, 'action' => 'edit', 'pe_resent' => $s['status'] ), admin_url( 'post.php' ) ) );
		exit;
	}

	/* ---------- Admin: leads list ---------- */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'pe_status' === $k ) {
				$out['pe_webhook'] = 'CRM';
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'pe_webhook' !== $col ) {
			return;
		}
		$s = self::state( $post_id );
		if ( ! $s ) {
			echo '<span style="color:#8c8f94">Not sent</span>';
			return;
		}
		$icons = array( 'delivered' => '&#10003;', 'retrying' => '&#8635;', 'pending' => '&#8635;', 'failed' => '&#10005;' );
		$color = array( 'delivered' => '#1F6B35', 'retrying' => '#7A5A00', 'pending' => '#7A5A00', 'failed' => '#9B2C1F' );
		printf(
			'<span style="color:%s;font-weight:600" title="%s">%s %s</span>',
			esc_attr( $color[ $s['status'] ] ),
			esc_attr( count( $s['attempts'] ) . ' attempt(s)' ),
			$icons[ $s['status'] ], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed HTML entities.
			esc_html( self::labels()[ $s['status'] ] )
		);
	}

	public static function bulk_actions( $actions ) {
		$actions['pe_webhook_resend'] = 'Resend to CRM webhook';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'pe_webhook_resend' !== $action ) {
			return $redirect;
		}
		$n = 0;
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) && self::url_for( $id ) ) {
				self::restart( (int) $id, false );
				$n++;
			}
		}
		if ( $n ) {
			spawn_cron();
		}
		return add_query_arg( 'pe_requeued', $n, $redirect );
	}

	public static function apply_filter( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || 'nse_lead' !== $q->get( 'post_type' ) || empty( $_GET['pe_webhook'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['pe_webhook'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::labels()[ $status ] ) ) {
			$mq   = (array) $q->get( 'meta_query' );
			$mq[] = array( 'key' => self::META_STATUS, 'value' => $status );
			$q->set( 'meta_query', $mq );
		}
	}

	public static function failed_count() {
		$q = new WP_Query(
			array(
				'post_type'      => 'nse_lead',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'failed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return (int) $q->found_posts;
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'nse_lead', 'nse_estimator' ), true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['pe_resent'] ) ) {
			$st = sanitize_key( wp_unslash( $_GET['pe_resent'] ) );
			$ok = 'delivered' === $st;
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( $ok ? 'Lead sent to the CRM webhook.' : 'The webhook did not accept the lead. See the CRM webhook box for the error. Automatic retries are scheduled.' ) );
		}
		if ( isset( $_GET['pe_requeued'] ) ) {
			$n = absint( $_GET['pe_requeued'] );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( '%d %s queued to resend to the CRM webhook.', $n, 1 === $n ? 'lead' : 'leads' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$failed = self::failed_count();
		if ( $failed ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> <a href="%s">View failed leads</a>, then use "Resend to CRM webhook" after fixing the webhook.</p></div>',
				esc_html( sprintf( '%d %s could not be sent to the CRM webhook.', $failed, 1 === $failed ? 'lead' : 'leads' ) ),
				esc_url( admin_url( 'edit.php?post_type=nse_lead&pe_webhook=failed' ) )
			);
		}
	}

	/* ---------- Admin: test button ---------- */

	/**
	 * Sample lead for "Send test lead". Uses the estimator's first project so the fields look real.
	 */
	public static function sample( $estimator_id ) {
		$c    = $estimator_id ? NSE_Config::get( $estimator_id ) : NSE_Config::defaults();
		$p    = $c['projects'][0];
		$opt  = isset( $p['options'][0] ) ? $p['options'][0]['name'] : '';
		$col  = isset( $p['colors'][0] ) ? $p['colors'][0]['name'] : '';
		$dims = array_map( function ( $d ) { return $d['default']; }, $p['dims'] );
		$est  = NSE_Config::estimate( $c, 0, 0, $dims, array(), 0 );
		return array(
			'name'          => 'Test Lead',
			'phone'         => '(602) 555-0100',
			'email'         => 'test@example.com',
			'zip'           => '85001',
			'address'       => '',
			'timeline'      => 'As soon as possible',
			'notes'         => 'This is a test lead from Project Estimator. You can delete it.',
			'estimator'     => $estimator_id ? get_the_title( $estimator_id ) : 'Test estimator',
			'estimator_id'  => (int) $estimator_id,
			'project'       => $p['name'],
			'option'        => $opt,
			'color'         => $col,
			'measurements'  => implode( ', ', array_map( function ( $d ) { return $d['label'] . ': ' . $d['default']; }, $p['dims'] ) ),
			'quantity'      => $est['qty'] . ' ' . $p['unit_label'],
			'size'          => implode( ' x ', array_map( function ( $v ) { return $v . ' ft'; }, $dims ) ),
			'extras'        => array(),
			'estimate_low'  => $est['low'],
			'estimate_high' => $est['high'],
			'source'        => 'Google Ads: Test Campaign',
			'attribution'   => array( 'gclid' => 'TEST_GCLID', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'Test Campaign' ),
			'pdf_url'       => '',
			'page'          => home_url( '/' ),
			'submitted'     => current_time( 'mysql' ),
		);
	}

	public static function ajax_test() {
		check_ajax_referer( 'pe_webhook_test', 'nonce' );
		$est = isset( $_POST['estimator'] ) ? absint( $_POST['estimator'] ) : 0;
		if ( ! current_user_can( 'edit_posts' ) || ( $est && ! current_user_can( 'edit_post', $est ) ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to send a test.' ) );
		}
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'https', 'http' ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'Enter a webhook URL first.' ) );
		}
		$body = array_merge(
			self::sample( $est ),
			array( 'event' => 'lead.created', 'test' => true, 'lead_id' => 0, 'status' => 'new', 'delivery_attempt' => 1, 'site' => home_url() )
		);
		$r = self::post( $url, $body, 0 );
		$msg = $r[0]
			? sprintf( 'Test lead sent (HTTP %d, %d ms). Check your Zap or CRM for "Test Lead".', $r[1], $r[3] )
			: sprintf( 'Test failed: %s%s', $r[1] ? 'HTTP ' . $r[1] . ', ' : '', $r[2] );
		$r[0] ? wp_send_json_success( array( 'message' => $msg ) ) : wp_send_json_error( array( 'message' => $msg ) );
	}
}

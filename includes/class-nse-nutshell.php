<?php
/**
 * Nutshell CRM: sends each quote request to Nutshell's JSON-RPC API as a contact and a lead.
 *
 * Connection: endpoint discovery with getApiForUsername, then HTTP Basic auth
 * (Nutshell user email + API key), as in Nutshell's official PHP client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Nutshell {

	const META        = '_pe_nutshell';
	const META_STATUS = '_pe_nutshell_status';
	const CRON        = 'pe_nutshell_retry';
	const DISCOVERY   = 'https://api.nutshell.com/v1/json';
	const BACKOFF     = array( 60, 300, 1800, 7200, 21600 );

	private static $after_response = array();

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'deliver' ) );
		add_action( 'shutdown', array( __CLASS__, 'run_after_response' ), 1 );
		add_action( 'wp_ajax_pe_nutshell_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'admin_post_pe_nutshell_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'add_meta_boxes_nse_lead', array( __CLASS__, 'meta_box' ) );
		add_filter( 'bulk_actions-edit-nse_lead', array( __CLASS__, 'bulk_actions' ), 25 );
		add_filter( 'handle_bulk_actions-edit-nse_lead', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'manage_nse_lead_posts_columns', array( __CLASS__, 'columns' ), 35 );
		add_action( 'manage_nse_lead_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function columns( $cols ) {
		if ( ! self::active() ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'pe_webhook' === $k || ( 'pe_status' === $k && ! isset( $cols['pe_webhook'] ) ) ) {
				$out['pe_nutshell'] = 'Nutshell';
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'pe_nutshell' !== $col ) {
			return;
		}
		$s = self::state( $post_id );
		if ( ! $s ) {
			echo '<span style="color:#8c8f94">Not sent</span>';
			return;
		}
		$map = array( 'delivered' => array( '#1F6B35', '&#10003; Sent' ), 'retrying' => array( '#7A5A00', '&#8635; Retrying' ), 'pending' => array( '#7A5A00', '&#8635; Sending' ), 'failed' => array( '#9B2C1F', '&#10005; Failed' ) );
		$m   = $map[ $s['status'] ];
		if ( 'delivered' === $s['status'] && $s['lead_id'] ) {
			printf( '<a href="%s" target="_blank" rel="noopener" style="color:%s;font-weight:600">%s</a>', esc_url( $s['url'] ? $s['url'] : 'https://app.nutshell.com/lead/' . (int) $s['lead_id'] ), esc_attr( $m[0] ), $m[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entities.
			return;
		}
		printf( '<span style="color:%s;font-weight:600">%s</span>', esc_attr( $m[0] ), $m[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entities.
	}

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'enabled'     => false,
			'username'    => '',
			'api_key'     => '',
			'source_mode' => 'attribution',
			'source_name' => 'Website estimator',
			'tags'        => 'Project Estimator',
			'assignee'    => '',
			'product_id'  => 0,
			'labels'      => array(),
		);
	}

	public static function sanitize( $in, $current = null ) {
		$in  = is_array( $in ) ? $in : array();
		$cur = is_array( $current ) ? $current : self::defaults();
		$key = isset( $in['api_key'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['api_key'] ) : '';
		$asg = isset( $in['assignee'] ) ? (string) $in['assignee'] : '';
		$lab = array();
		foreach ( (array) ( isset( $in['labels'] ) ? $in['labels'] : array() ) as $k => $v ) {
			$lab[ sanitize_text_field( (string) $k ) ] = sanitize_text_field( (string) $v );
		}
		return array(
			'enabled'     => ! empty( $in['enabled'] ),
			'username'    => isset( $in['username'] ) ? sanitize_text_field( trim( (string) $in['username'] ) ) : '',
			/* Leaving the key field blank keeps the saved key. */
			'api_key'     => '' !== $key ? substr( $key, 0, 120 ) : ( isset( $cur['api_key'] ) ? $cur['api_key'] : '' ),
			'source_mode' => isset( $in['source_mode'] ) && 'fixed' === $in['source_mode'] ? 'fixed' : 'attribution',
			'source_name' => isset( $in['source_name'] ) && '' !== trim( $in['source_name'] ) ? sanitize_text_field( $in['source_name'] ) : 'Website estimator',
			'tags'        => isset( $in['tags'] ) ? sanitize_text_field( $in['tags'] ) : '',
			'assignee'    => preg_match( '/^(Users|Teams):\d+$/', $asg ) ? $asg : '',
			'product_id'  => isset( $in['product_id'] ) ? absint( $in['product_id'] ) : 0,
			'labels'      => array_slice( $lab, 0, 300, true ),
		);
	}

	public static function settings() {
		$s = NSE_Settings::get();
		return $s['nutshell'];
	}

	public static function active() {
		$n = self::settings();
		return $n['enabled'] && $n['username'] && $n['api_key'];
	}

	/* ---------- JSON-RPC client ---------- */

	/**
	 * The account's API endpoint, discovered once and cached for a week.
	 */
	public static function endpoint( $username, $fresh = false ) {
		$cache = 'pe_nutshell_ep_' . md5( strtolower( $username ) );
		if ( ! $fresh ) {
			$hit = get_transient( $cache );
			if ( $hit ) {
				return $hit;
			}
		}
		$res = wp_safe_remote_post(
			self::DISCOVERY,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => 'getApiForUsername', 'params' => array( 'username' => $username ), 'id' => 'pe1' ) ),
			)
		);
		$api = '';
		if ( ! is_wp_error( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! empty( $body['result']['api'] ) && preg_match( '/^[a-z0-9.-]+\.nutshell\.com$/i', $body['result']['api'] ) ) {
				$api = $body['result']['api'];
			}
		}
		$url = 'https://' . ( $api ? $api : 'app.nutshell.com' ) . '/api/v1/json';
		if ( $api ) {
			set_transient( $cache, $url, WEEK_IN_SECONDS );
		}
		return $url;
	}

	/**
	 * Call a Nutshell API method. Returns the result, or a WP_Error.
	 */
	public static function call( $method, array $params = array(), $creds = null ) {
		$n = $creds ? $creds : self::settings();
		if ( ! $n['username'] || ! $n['api_key'] ) {
			return new WP_Error( 'pe_nutshell_creds', 'Add the Nutshell user email and API key under Estimators > Settings.' );
		}
		$res = wp_safe_remote_post(
			self::endpoint( $n['username'] ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $n['username'] . ':' . $n['api_key'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'User-Agent'    => 'ProjectEstimator/' . NSE_VERSION . '; ' . home_url(),
				),
				'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params, 'id' => substr( md5( wp_rand() ), 0, 8 ) ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pe_nutshell_http', 'Could not reach Nutshell: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'pe_nutshell_auth', 'Nutshell rejected the user email or API key (HTTP ' . $code . ').' );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'pe_nutshell_http', 'Unexpected response from Nutshell (HTTP ' . $code . ').' );
		}
		if ( ! empty( $body['error'] ) ) {
			$msg = is_array( $body['error'] ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			return new WP_Error( 'pe_nutshell_api', 'Nutshell: ' . $msg );
		}
		return array_key_exists( 'result', $body ) ? $body['result'] : null;
	}

	/* ---------- Building the records ---------- */

	private static function first_contact_id( $search ) {
		if ( ! is_array( $search ) ) {
			return 0;
		}
		if ( isset( $search['contacts'] ) && is_array( $search['contacts'] ) && $search['contacts'] ) {
			return (int) $search['contacts'][0]['id'];
		}
		foreach ( $search as $row ) {
			if ( is_array( $row ) && isset( $row['entityType'], $row['id'] ) && 'Contacts' === $row['entityType'] ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	public static function contact_payload( array $lead ) {
		$c = array( 'name' => $lead['name'] );
		if ( ! empty( $lead['phone'] ) ) {
			$c['phone'] = array( $lead['phone'] );
		}
		if ( ! empty( $lead['email'] ) ) {
			$c['email'] = array( $lead['email'] );
		}
		$addr = array_filter(
			array(
				'address_1'  => isset( $lead['address'] ) ? $lead['address'] : '',
				'postalCode' => isset( $lead['zip'] ) ? $lead['zip'] : '',
			)
		);
		if ( $addr ) {
			$addr['country'] = 'US';
			$c['address']    = array( $addr );
		}
		return $c;
	}

	private static function money( $n ) {
		return '$' . number_format( (float) $n );
	}

	/**
	 * Plain-text note with every detail of the request.
	 */
	public static function note( $lead_id, array $lead ) {
		$a     = isset( $lead['attribution'] ) && is_array( $lead['attribution'] ) ? $lead['attribution'] : array();
		$lines = array(
			'Quote request from the website estimator (' . ( isset( $lead['estimator'] ) ? $lead['estimator'] : '' ) . ')',
			'',
			'Estimate: ' . self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] ),
			'Project: ' . $lead['project'],
			'Style: ' . $lead['option'],
		);
		if ( ! empty( $lead['color'] ) ) {
			$lines[] = 'Color: ' . $lead['color'];
		}
		$lines[] = 'Size: ' . ( ! empty( $lead['size'] ) ? $lead['size'] : $lead['measurements'] );
		$lines[] = 'Extras: ' . ( ! empty( $lead['extras'] ) ? implode( ', ', $lead['extras'] ) : 'None' );
		foreach ( array( 'timeline' => 'Timeline', 'notes' => 'Customer notes', 'zip' => 'ZIP', 'address' => 'Address' ) as $k => $label ) {
			if ( ! empty( $lead[ $k ] ) ) {
				$lines[] = $label . ': ' . $lead[ $k ];
			}
		}
		$lines[] = '';
		$lines[] = 'Source: ' . ( isset( $lead['source'] ) ? $lead['source'] : 'Unknown' );
		foreach ( array( 'utm_campaign' => 'Campaign', 'utm_term' => 'Keyword', 'gclid' => 'GCLID' ) as $k => $label ) {
			if ( ! empty( $a[ $k ] ) ) {
				$lines[] = $label . ': ' . $a[ $k ];
			}
		}
		if ( ! empty( $lead['pdf_url'] ) ) {
			$lines[] = '';
			$lines[] = 'PDF estimate: ' . $lead['pdf_url'];
		}
		if ( ! empty( $lead['photos'] ) ) {
			$lines[] = 'Customer photos:';
			foreach ( $lead['photos'] as $u ) {
				$lines[] = $u;
			}
		}
		$lines[] = '';
		$lines[] = 'In WordPress: ' . admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' );
		return implode( "\n", $lines );
	}

	private static function source_name( array $lead, array $n ) {
		if ( 'fixed' === $n['source_mode'] ) {
			return $n['source_name'];
		}
		$src = isset( $lead['source'] ) ? (string) $lead['source'] : '';
		if ( '' === $src || 'Direct' === $src ) {
			return $n['source_name'];
		}
		if ( 0 === strpos( $src, 'Organic search' ) ) {
			return 'Organic search';
		}
		$cut = strpos( $src, ':' );
		return false === $cut ? $src : trim( substr( $src, 0, $cut ) );
	}

	private static function tag_list( $raw ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) ) );
	}

	/* ---------- Delivery (after response, retries, log) ---------- */

	public static function state( $lead_id ) {
		$s = get_post_meta( $lead_id, self::META, true );
		return is_array( $s ) ? wp_parse_args( $s, array( 'status' => 'pending', 'tries' => 0, 'attempts' => array(), 'next' => 0, 'contact_id' => 0, 'lead_id' => 0, 'url' => '' ) ) : null;
	}

	private static function save_state( $lead_id, array $s ) {
		$s['attempts'] = array_slice( $s['attempts'], -12 );
		update_post_meta( $lead_id, self::META, $s );
		update_post_meta( $lead_id, self::META_STATUS, $s['status'] );
	}

	public static function queue( $lead_id ) {
		if ( ! self::active() ) {
			return;
		}
		self::save_state( $lead_id, array( 'status' => 'pending', 'tries' => 0, 'attempts' => array(), 'next' => 0, 'contact_id' => 0, 'lead_id' => 0, 'url' => '' ) );
		self::$after_response[] = (int) $lead_id;
		wp_schedule_single_event( time() + 120, self::CRON, array( (int) $lead_id ) );
	}

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
	 * Create the contact (once) and the lead (once). Safe to retry.
	 *
	 * @return true|WP_Error
	 */
	public static function sync( $lead_id, array &$s ) {
		$lead = get_post_meta( $lead_id, '_nse_lead', true );
		if ( ! is_array( $lead ) ) {
			return new WP_Error( 'pe_nutshell_lead', 'Lead data is missing.' );
		}
		$n = self::settings();

		/* 1. Contact: reuse one with the same email, otherwise create it. Saved so retries never duplicate. */
		if ( ! $s['contact_id'] ) {
			if ( ! empty( $lead['email'] ) ) {
				$found = self::call( 'searchByEmail', array( 'emailAddressString' => $lead['email'] ) );
				if ( ! is_wp_error( $found ) ) {
					$s['contact_id'] = self::first_contact_id( $found );
				}
			}
			if ( ! $s['contact_id'] ) {
				$contact = self::call( 'newContact', array( 'contact' => self::contact_payload( $lead ) ) );
				if ( is_wp_error( $contact ) ) {
					return $contact;
				}
				$s['contact_id'] = isset( $contact['id'] ) ? (int) $contact['id'] : 0;
				if ( ! $s['contact_id'] ) {
					return new WP_Error( 'pe_nutshell_api', 'Nutshell did not return a contact ID.' );
				}
			}
			self::save_state( $lead_id, $s );
		}

		if ( $s['lead_id'] ) {
			return true;
		}

		/* 2. Lead with source, tags, assignee, optional product value, and the full note. */
		$payload = array(
			'contacts'    => array( array( 'id' => $s['contact_id'] ) ),
			'description' => sprintf( '%s estimate: %s, %s', $lead['project'], $lead['option'], ! empty( $lead['size'] ) ? $lead['size'] : $lead['quantity'] ),
			'note'        => self::note( $lead_id, $lead ),
		);
		/* Source and tag lookups are remembered for a day to keep API calls down. */
		$sname = self::source_name( $lead, $n );
		$skey  = 'pe_ns_src_' . md5( strtolower( $n['username'] . '|' . $sname ) );
		$sid   = (int) get_transient( $skey );
		if ( ! $sid ) {
			$source = self::call( 'newSource', array( 'name' => $sname ) );
			if ( ! is_wp_error( $source ) && ! empty( $source['id'] ) ) {
				$sid = (int) $source['id'];
				set_transient( $skey, $sid, DAY_IN_SECONDS );
			}
		}
		if ( $sid ) {
			$payload['sources'] = array( array( 'id' => $sid ) );
		}
		$tags = self::tag_list( $n['tags'] );
		if ( $tags ) {
			$tkey = 'pe_ns_tags_' . md5( strtolower( $n['username'] . '|' . implode( ',', $tags ) ) );
			if ( ! get_transient( $tkey ) ) {
				foreach ( $tags as $t ) {
					/* Tags must exist before use; "already exists" errors are fine. */
					self::call( 'newTag', array( 'tag' => array( 'name' => $t, 'entityType' => 'Leads' ) ) );
				}
				set_transient( $tkey, 1, DAY_IN_SECONDS );
			}
			$payload['tags'] = $tags;
		}
		if ( $n['assignee'] ) {
			list( $type, $aid ) = explode( ':', $n['assignee'] );
			$payload['assignee']  = array( 'entityType' => $type, 'id' => (int) $aid );
		}
		if ( $n['product_id'] ) {
			$payload['products'] = array(
				array(
					'id'       => (int) $n['product_id'],
					'quantity' => 1,
					'price'    => array(
						'amount'   => (string) round( ( (float) $lead['estimate_low'] + (float) $lead['estimate_high'] ) / 2 ),
						'currency' => 'USD',
					),
				),
			);
		}
		$created = self::call( 'newLead', array( 'lead' => $payload ) );
		if ( is_wp_error( $created ) && ( isset( $payload['tags'] ) || isset( $payload['products'] ) ) && false !== stripos( $created->get_error_message(), 'tag' ) ) {
			/* A tag problem should not block the lead itself. */
			unset( $payload['tags'] );
			$created = self::call( 'newLead', array( 'lead' => $payload ) );
		}
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$s['lead_id'] = isset( $created['id'] ) ? (int) $created['id'] : 0;
		$s['url']     = isset( $created['htmlUrl'] ) ? esc_url_raw( $created['htmlUrl'] ) : '';
		return $s['lead_id'] ? true : new WP_Error( 'pe_nutshell_api', 'Nutshell did not return a lead ID.' );
	}

	public static function deliver( $lead_id, $manual = false ) {
		$lead_id = (int) $lead_id;
		$s       = self::state( $lead_id );
		if ( ! $s || ( 'delivered' === $s['status'] && ! $manual ) ) {
			return $s;
		}
		$lock = 'pe_ns_lock_' . $lead_id;
		if ( get_transient( $lock ) ) {
			return $s;
		}
		set_transient( $lock, 1, 90 );
		$s['tries']++;
		$start  = microtime( true );
		$result = self::active() ? self::sync( $lead_id, $s ) : new WP_Error( 'pe_nutshell_off', 'Nutshell is turned off or missing credentials.' );
		$ok     = true === $result;
		$s['attempts'][] = array(
			'time'    => time(),
			'ok'      => $ok,
			'message' => $ok ? 'Created Nutshell lead #' . $s['lead_id'] . ( $s['contact_id'] ? ' for contact #' . $s['contact_id'] : '' ) : $result->get_error_message(),
			'ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'manual'  => (bool) $manual,
		);
		wp_clear_scheduled_hook( self::CRON, array( $lead_id ) );
		$permanent = ! $ok && in_array( $result->get_error_code(), array( 'pe_nutshell_auth', 'pe_nutshell_creds', 'pe_nutshell_off' ), true );
		if ( $ok ) {
			$s['status'] = 'delivered';
			$s['next']   = 0;
		} elseif ( ! $permanent && $s['tries'] <= count( self::BACKOFF ) ) {
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

	public static function restart( $lead_id, $now = true ) {
		$s = self::state( $lead_id );
		$s = $s ? $s : array( 'status' => 'pending', 'tries' => 0, 'attempts' => array(), 'next' => 0, 'contact_id' => 0, 'lead_id' => 0, 'url' => '' );
		$s['tries'] = 0;
		if ( 'delivered' !== $s['status'] ) {
			$s['status'] = 'pending';
		}
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
		wp_mail(
			$to,
			'Nutshell sync failed: ' . get_bloginfo( 'name' ),
			implode(
				"\n",
				array(
					'A quote request could not be sent to Nutshell.',
					'',
					'Lead: ' . ( isset( $lead['name'] ) ? $lead['name'] : '#' . $lead_id ) . ( isset( $lead['phone'] ) ? ', ' . $lead['phone'] : '' ),
					'Last error: ' . $last['message'],
					'',
					'The lead is saved in WordPress. After fixing the problem (for example, a new API key under Estimators > Settings), open the lead and click "Send to Nutshell":',
					admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' ),
				)
			)
		);
	}

	/* ---------- Admin: test connection ---------- */

	public static function ajax_test() {
		check_ajax_referer( 'pe_nutshell_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ) );
		}
		$saved = self::settings();
		$key   = isset( $_POST['api_key'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$creds = array(
			'username' => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
			'api_key'  => '' !== $key ? $key : $saved['api_key'],
		);
		if ( ! $creds['username'] || ! $creds['api_key'] ) {
			wp_send_json_error( array( 'message' => 'Enter the Nutshell user email and API key first.' ) );
		}
		self::endpoint( $creds['username'], true );
		$users = self::call( 'findUsers', array( 'limit' => 100 ), $creds );
		if ( is_wp_error( $users ) ) {
			wp_send_json_error( array( 'message' => $users->get_error_message() ) );
		}
		$teams    = self::call( 'findTeams', array( 'limit' => 50 ), $creds );
		$products = self::call( 'findProducts', array( 'limit' => 100 ), $creds );
		$opt      = function ( $rows, $type ) {
			$out = array();
			foreach ( (array) $rows as $r ) {
				if ( ! is_array( $r ) || empty( $r['id'] ) ) {
					continue;
				}
				$name  = isset( $r['name'] ) ? $r['name'] : '';
				$name  = is_array( $name ) ? trim( implode( ' ', array_filter( array( isset( $name['givenName'] ) ? $name['givenName'] : '', isset( $name['familyName'] ) ? $name['familyName'] : '' ) ) ) ) : $name;
				$out[] = array( 'value' => ( $type ? $type . ':' : '' ) . (int) $r['id'], 'label' => $name ? $name : '#' . (int) $r['id'] );
			}
			return $out;
		};
		wp_send_json_success(
			array(
				'message'  => sprintf( 'Connected. Found %d users.', count( (array) $users ) ),
				'users'    => $opt( $users, 'Users' ),
				'teams'    => is_wp_error( $teams ) ? array() : $opt( $teams, 'Teams' ),
				'products' => is_wp_error( $products ) ? array() : $opt( $products, '' ),
			)
		);
	}

	/* ---------- Admin: lead screen, bulk, notices ---------- */

	public static function meta_box() {
		add_meta_box( 'pe_nutshell', 'Nutshell CRM', array( __CLASS__, 'render_box' ), 'nse_lead', 'side', 'default' );
	}

	public static function render_box( $post ) {
		$s = self::state( $post->ID );
		if ( ! $s && ! self::active() ) {
			echo '<p class="description">Nutshell is not connected. Set it up under Estimators &gt; Settings.</p>';
			return;
		}
		$labels = array( 'delivered' => 'Sent to Nutshell', 'retrying' => 'Retrying', 'pending' => 'Sending', 'failed' => 'Failed' );
		if ( $s ) {
			printf( '<p><span class="pe-wh pe-wh--%s">%s</span></p>', esc_attr( $s['status'] ), esc_html( $labels[ $s['status'] ] ) );
			if ( $s['lead_id'] ) {
				$url = $s['url'] ? $s['url'] : 'https://app.nutshell.com/lead/' . (int) $s['lead_id'];
				printf( '<p><a href="%s" target="_blank" rel="noopener">Open lead #%d in Nutshell</a></p>', esc_url( $url ), (int) $s['lead_id'] );
			}
			if ( 'retrying' === $s['status'] && $s['next'] ) {
				echo '<p class="description">Next try: ' . esc_html( wp_date( 'M j, g:i a', $s['next'] ) ) . '</p>';
			}
			if ( $s['attempts'] ) {
				echo '<ul class="pe-wh-log">';
				foreach ( array_reverse( $s['attempts'] ) as $a ) {
					printf( '<li><strong>%s</strong> %s<br><span class="description">%s</span></li>', esc_html( $a['ok'] ? 'OK' : 'Failed' ), esc_html( wp_date( 'M j, g:i:s a', $a['time'] ) . ( ! empty( $a['manual'] ) ? ' (manual)' : '' ) ), esc_html( $a['message'] ) );
				}
				echo '</ul>';
			}
		}
		if ( ! $s || 'delivered' !== $s['status'] ) {
			printf(
				'<p><a class="button" href="%s">Send to Nutshell</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pe_nutshell_resend&lead=' . (int) $post->ID ), 'pe_nutshell_resend_' . (int) $post->ID ) )
			);
		}
		echo '<style>.pe-wh{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600}.pe-wh--delivered{background:#DFF3E4;color:#1F6B35}.pe-wh--retrying,.pe-wh--pending{background:#FFF4D6;color:#7A5A00}.pe-wh--failed{background:#FBE3E1;color:#9B2C1F}.pe-wh-log{margin:8px 0;max-height:220px;overflow:auto}.pe-wh-log li{margin-bottom:8px}</style>';
	}

	public static function handle_resend() {
		$id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		check_admin_referer( 'pe_nutshell_resend_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'You do not have permission to send this lead.' );
		}
		$s = self::restart( $id, true );
		wp_safe_redirect( add_query_arg( array( 'post' => $id, 'action' => 'edit', 'pe_ns' => $s['status'] ), admin_url( 'post.php' ) ) );
		exit;
	}

	public static function bulk_actions( $actions ) {
		if ( self::active() ) {
			$actions['pe_nutshell_send'] = 'Send to Nutshell';
		}
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'pe_nutshell_send' !== $action ) {
			return $redirect;
		}
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$s = self::state( $id );
			if ( current_user_can( 'edit_post', $id ) && ( ! $s || 'delivered' !== $s['status'] ) ) {
				self::restart( (int) $id, false );
				$n++;
			}
		}
		if ( $n ) {
			spawn_cron();
		}
		return add_query_arg( 'pe_ns_queued', $n, $redirect );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'nse_lead', 'nse_estimator' ), true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['pe_ns'] ) ) {
			$ok = 'delivered' === sanitize_key( $_GET['pe_ns'] );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( $ok ? 'Lead sent to Nutshell.' : 'Nutshell did not accept the lead. See the Nutshell CRM box for the error.' ) );
		}
		if ( isset( $_GET['pe_ns_queued'] ) ) {
			$n = absint( $_GET['pe_ns_queued'] );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( '%d %s queued to send to Nutshell.', $n, 1 === $n ? 'lead' : 'leads' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$q = new WP_Query( array( 'post_type' => 'nse_lead', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => self::META_STATUS, 'meta_value' => 'failed' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $q->found_posts ) {
			printf( '<div class="notice notice-error"><p><strong>%s</strong> Fix the problem under Estimators &gt; Settings, then use "Send to Nutshell" on those leads.</p></div>', esc_html( sprintf( '%d %s could not be sent to Nutshell.', $q->found_posts, 1 === (int) $q->found_posts ? 'lead' : 'leads' ) ) );
		}
	}
}

<?php
/**
 * Novoslate Ops: sends each lead to Ops for lead quality tracking (source, campaign, CRM status) when an Ops token
 * is set under Estimators > Settings. Email and phone are hashed here (SHA-256 of the normalized value) and no other
 * contact details are sent: only the first name, no address, notes, photos or PDF.
 * Sent after the response like the webhook, retried with backoff, and sent again when Nutshell returns its lead id
 * or the lead's status changes, so Ops can match it and track won and lost leads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Ops {

	const META        = '_pe_ops';
	const CRON        = 'pe_ops_retry';
	const DEFAULT_URL = 'https://ops.novoslate.com';
	const BACKOFF     = array( 60, 300, 1800, 7200, 21600 );

	private static $after_response = array();

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'deliver' ) );
		add_action( 'shutdown', array( __CLASS__, 'run_after_response' ), 2 ); // After Nutshell (1), so its lead id is ready.
		add_action( 'pe_nutshell_delivered', array( __CLASS__, 'resend' ) );
		add_action( 'pe_status_changed', array( __CLASS__, 'resend' ) );
	}

	public static function defaults() {
		return array( 'token' => '', 'url' => self::DEFAULT_URL );
	}

	public static function sanitize( $in, $current = null ) {
		$in    = is_array( $in ) ? $in : array();
		$cur   = is_array( $current ) ? $current : self::defaults();
		$given = isset( $in['token'] ) ? trim( (string) $in['token'] ) : '';
		$token = strtolower( preg_replace( '/[^A-Fa-f0-9]/', '', $given ) );
		$url   = isset( $in['url'] ) ? esc_url_raw( trim( (string) $in['url'] ) ) : '';
		if ( '-' === $given ) {
			$token = ''; // "-" clears the token.
		} elseif ( 48 !== strlen( $token ) ) {
			$token = isset( $cur['token'] ) ? (string) $cur['token'] : ''; // Blank or invalid keeps the saved token.
		}
		return array(
			'token' => $token,
			'url'   => $url && 0 === strpos( $url, 'https://' ) ? untrailingslashit( $url ) : self::DEFAULT_URL,
		);
	}

	public static function settings() {
		$s = NSE_Settings::get();
		return isset( $s['ops'] ) ? $s['ops'] : self::defaults();
	}

	public static function active() {
		$o = self::settings();
		return 48 === strlen( $o['token'] );
	}

	/* ---------- Hashing (must match Ops: src/lib/leadRules.ts) ---------- */

	public static function email_hash( $email ) {
		$e = strtolower( trim( (string) $email ) );
		return is_email( $e ) ? hash( 'sha256', $e ) : '';
	}

	public static function phone_hash( $phone ) {
		$raw = trim( (string) $phone );
		$d   = preg_replace( '/\D/', '', $raw );
		if ( '' === $d ) {
			return '';
		}
		if ( 0 !== strpos( $raw, '+' ) && 10 === strlen( $d ) ) {
			$d = '1' . $d;
		}
		return strlen( $d ) >= 8 && strlen( $d ) <= 15 ? hash( 'sha256', '+' . $d ) : '';
	}

	/* ---------- Payload ---------- */

	public static function payload( $lead_id ) {
		$lead  = get_post_meta( $lead_id, '_nse_lead', true );
		$lead  = is_array( $lead ) ? $lead : array();
		$a     = isset( $lead['attribution'] ) && is_array( $lead['attribution'] ) ? $lead['attribution'] : array();
		$ns    = NSE_Nutshell::state( $lead_id );
		$wp    = NSE_Status::get( $lead_id );
		$parts = preg_split( '/\s+/', trim( isset( $lead['name'] ) ? (string) $lead['name'] : '' ) );
		$body  = array(
			'source'         => 'estimator',
			'external_id'    => (string) $lead_id,
			'created_at'     => get_post_time( 'c', true, $lead_id ),
			'name'           => $parts ? $parts[0] : '',
			'email_hash'     => self::email_hash( isset( $lead['email'] ) ? $lead['email'] : '' ),
			'phone_hash'     => self::phone_hash( isset( $lead['phone'] ) ? $lead['phone'] : '' ),
			'service'        => isset( $lead['project'] ) ? $lead['project'] : '',
			'estimate_low'   => isset( $lead['estimate_low'] ) ? $lead['estimate_low'] : null,
			'estimate_high'  => isset( $lead['estimate_high'] ) ? $lead['estimate_high'] : null,
			'attribution'    => $a,
			'landing_page'   => ! empty( $a['landing_page'] ) ? $a['landing_page'] : ( isset( $lead['page'] ) ? $lead['page'] : '' ),
			'page'           => isset( $lead['page'] ) ? $lead['page'] : '',
			'estimator'      => isset( $lead['estimator'] ) ? $lead['estimator'] : '',
			'option'         => isset( $lead['option'] ) ? $lead['option'] : '',
			'size'           => isset( $lead['size'] ) ? $lead['size'] : '',
			'extras'         => isset( $lead['extras'] ) ? array_values( (array) $lead['extras'] ) : array(),
			'timeline'       => isset( $lead['timeline'] ) ? $lead['timeline'] : '',
			'source_label'   => isset( $lead['source'] ) ? $lead['source'] : '',
			'wp_status'      => $wp,
			'site'           => home_url(),
			'plugin_version' => NSE_VERSION,
		);
		/* The lead's own status counts in Ops only when no CRM tracks it (Ops ignores it once a Nutshell id is set). */
		if ( 'booked' === $wp ) {
			$body['status'] = 'won';
			$v              = NSE_Status::booked_value( $lead_id );
			if ( null !== $v ) {
				$body['value'] = $v;
			}
		} elseif ( 'lost' === $wp ) {
			$body['status'] = 'lost';
		}
		if ( $ns && ! empty( $ns['lead_id'] ) ) {
			$body['crm']         = 'nutshell';
			$body['crm_lead_id'] = (string) (int) $ns['lead_id'];
		}
		return $body;
	}

	/* ---------- Delivery ---------- */

	private static function blank() {
		return array( 'status' => 'pending', 'tries' => 0, 'last' => '', 'next' => 0, 'ops_id' => '' );
	}

	public static function state( $lead_id ) {
		$s = get_post_meta( $lead_id, self::META, true );
		return is_array( $s ) ? wp_parse_args( $s, self::blank() ) : null;
	}

	public static function queue( $lead_id ) {
		if ( ! self::active() ) {
			return;
		}
		update_post_meta( $lead_id, self::META, self::blank() );
		self::$after_response[] = (int) $lead_id;
		/* Safety net: if this request dies before shutdown, cron still sends it. */
		wp_schedule_single_event( time() + 150, self::CRON, array( (int) $lead_id ) );
	}

	/**
	 * Send again after a change (Nutshell id, status), at the end of this request.
	 */
	public static function resend( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( ! self::active() || 'nse_lead' !== get_post_type( $lead_id ) ) {
			return;
		}
		$s           = self::state( $lead_id );
		$s           = $s ? $s : self::blank();
		$s['status'] = 'pending';
		$s['tries']  = 0;
		update_post_meta( $lead_id, self::META, $s );
		if ( ! in_array( $lead_id, self::$after_response, true ) ) {
			self::$after_response[] = $lead_id;
		}
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
		foreach ( array_unique( self::$after_response ) as $id ) {
			self::deliver( $id );
		}
		self::$after_response = array();
	}

	public static function deliver( $lead_id ) {
		$lead_id = (int) $lead_id;
		$s       = self::state( $lead_id );
		if ( ! $s || 'delivered' === $s['status'] || ! self::active() ) {
			return $s;
		}
		$lock = 'pe_ops_lock_' . $lead_id;
		if ( get_transient( $lock ) ) {
			return $s;
		}
		set_transient( $lock, 1, 60 );
		$o = self::settings();
		$s['tries']++;
		$res  = wp_safe_remote_post(
			$o['url'] . '/api/leads/ingest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-novoslate-token' => $o['token'],
					'User-Agent'        => 'ProjectEstimator/' . NSE_VERSION . '; ' . home_url(),
				),
				'body'    => wp_json_encode( self::payload( $lead_id ) ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		wp_clear_scheduled_hook( self::CRON, array( $lead_id ) );
		if ( $code >= 200 && $code < 300 ) {
			$body        = json_decode( wp_remote_retrieve_body( $res ), true );
			$s['status'] = 'delivered';
			$s['next']   = 0;
			$s['last']   = 'Sent to Ops (HTTP ' . $code . ')';
			$s['ops_id'] = is_array( $body ) && ! empty( $body['id'] ) ? sanitize_text_field( $body['id'] ) : $s['ops_id'];
		} else {
			$s['last'] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
			/* 400 and 401 will not fix themselves; anything else is retried. */
			if ( ! in_array( $code, array( 400, 401 ), true ) && $s['tries'] <= count( self::BACKOFF ) ) {
				$s['status'] = 'retrying';
				$s['next']   = time() + self::BACKOFF[ $s['tries'] - 1 ];
				wp_schedule_single_event( $s['next'], self::CRON, array( $lead_id ) );
			} else {
				$s['status'] = 'failed';
				$s['next']   = 0;
			}
		}
		update_post_meta( $lead_id, self::META, $s );
		delete_transient( $lock );
		return $s;
	}
}

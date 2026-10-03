<?php
/**
 * Google reCAPTCHA v2 (checkbox) and v3 (invisible) for quote requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Recaptcha {

	const ACTION = 'quote_request';

	public static function defaults() {
		return array(
			'mode'       => 'off',
			'site_key'   => '',
			'secret_key' => '',
			'threshold'  => 0.5,
			'hide_badge' => false,
		);
	}

	public static function sanitize( $r ) {
		$r   = is_array( $r ) ? $r : array();
		$key = function ( $v ) {
			return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v );
		};
		$t   = isset( $r['threshold'] ) ? (float) $r['threshold'] : 0.5;
		return array(
			'mode'       => isset( $r['mode'] ) && in_array( $r['mode'], array( 'off', 'v2', 'v3' ), true ) ? $r['mode'] : 'off',
			'site_key'   => isset( $r['site_key'] ) ? substr( $key( $r['site_key'] ), 0, 100 ) : '',
			'secret_key' => isset( $r['secret_key'] ) ? substr( $key( $r['secret_key'] ), 0, 100 ) : '',
			'threshold'  => max( 0.1, min( 0.9, round( $t, 1 ) ) ),
			'hide_badge' => ! empty( $r['hide_badge'] ),
		);
	}

	/**
	 * Active settings, or null when off or keys are missing.
	 */
	public static function active() {
		$s = NSE_Settings::get();
		$r = $s['recaptcha'];
		if ( 'off' === $r['mode'] || ! $r['site_key'] || ! $r['secret_key'] ) {
			return null;
		}
		return $r;
	}

	/**
	 * Safe for the browser: never includes the secret key.
	 */
	public static function public_config() {
		$r = self::active();
		return $r ? array(
			'mode'       => $r['mode'],
			'site_key'   => $r['site_key'],
			'hide_badge' => 'v3' === $r['mode'] && $r['hide_badge'],
		) : null;
	}

	public static function script_url() {
		$r = self::active();
		if ( ! $r ) {
			return '';
		}
		return 'v2' === $r['mode']
			? 'https://www.google.com/recaptcha/api.js?render=explicit'
			: 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $r['site_key'] );
	}

	/**
	 * Verify a token with Google.
	 *
	 * @return array{ok:bool,message:string,score:?float}
	 */
	public static function verify( $token, $ip, $phone = '' ) {
		$r = self::active();
		if ( ! $r ) {
			return array( 'ok' => true, 'message' => '', 'score' => null );
		}
		$call  = $phone ? ' or call us at ' . $phone : '';
		$retry = array(
			'ok'      => false,
			'message' => 'v2' === $r['mode']
				? 'Please check the "I\'m not a robot" box and try again.'
				: 'We could not verify your request. Please refresh the page and try again' . $call . '.',
			'score'   => null,
		);
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( '' === $token || strlen( $token ) > 4000 ) {
			return $retry;
		}

		$res = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $r['secret_key'],
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);

		/* If Google can't be reached, let the lead through rather than lose it. */
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			error_log( 'Project Estimator reCAPTCHA: verification service unavailable (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) . '). Lead accepted.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return array( 'ok' => true, 'message' => '', 'score' => null );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			$codes = is_array( $body ) && ! empty( $body['error-codes'] ) ? implode( ', ', (array) $body['error-codes'] ) : 'unknown';
			if ( preg_match( '/invalid-input-secret|missing-input-secret/', $codes ) ) {
				/* Misconfigured secret key: an admin problem, not a visitor problem. */
				error_log( 'Project Estimator reCAPTCHA: the secret key is invalid (' . $codes . '). Lead accepted. Check Estimators > Settings.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return array( 'ok' => true, 'message' => '', 'score' => null );
			}
			return $retry;
		}

		if ( 'v3' === $r['mode'] ) {
			$score = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
			if ( isset( $body['action'] ) && self::ACTION !== $body['action'] ) {
				return $retry;
			}
			if ( $score < $r['threshold'] ) {
				return array(
					'ok'      => false,
					'message' => 'Your request looked automated, so it was not sent. Please try again' . ( $call ? $call : ' later' ) . '.',
					'score'   => $score,
				);
			}
			return array( 'ok' => true, 'message' => '', 'score' => $score );
		}
		return array( 'ok' => true, 'message' => '', 'score' => null );
	}
}

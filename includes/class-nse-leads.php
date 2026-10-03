<?php
/**
 * Lead capture: REST endpoint, storage as a private post type, email alert, optional webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Leads {

	const META_KEY = '_nse_lead';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'add_meta_boxes_nse_lead', array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'manage_nse_lead_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_nse_lead_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function routes() {
		register_rest_route(
			'nse/v1',
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function fail( $msg, $status = 400 ) {
		return new WP_Error( 'nse_invalid', $msg, array( 'status' => $status ) );
	}

	public static function submit( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		if ( ! is_array( $p ) ) {
			return self::fail( 'Something went wrong. Please try again.' );
		}

		// Honeypot: bots fill the hidden field. Pretend success.
		if ( ! empty( $p['website'] ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Too-fast submissions are almost always bots.
		$ts = isset( $p['ts'] ) ? (int) $p['ts'] : 0;
		if ( $ts && ( time() - $ts ) < 3 ) {
			return self::fail( 'Please take a moment to review your details and try again.' );
		}

		// Basic rate limit per visitor.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rl    = 'nse_rl_' . md5( $ip );
		$count = (int) get_transient( $rl );
		if ( $count >= 6 ) {
			return self::fail( 'Too many requests. Please call us or try again later.', 429 );
		}
		set_transient( $rl, $count + 1, 10 * MINUTE_IN_SECONDS );

		$id   = isset( $p['estimator_id'] ) ? absint( $p['estimator_id'] ) : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'nse_estimator' !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::fail( 'This estimator is no longer available.', 404 );
		}
		$c = NSE_Config::get( $id );
		$f = $c['fields'];

		$get = function ( $k ) use ( $p ) {
			return isset( $p[ $k ] ) ? sanitize_text_field( (string) $p[ $k ] ) : '';
		};

		$lead = array(
			'name'     => $get( 'name' ),
			'phone'    => $get( 'phone' ),
			'email'    => 'off' === $f['email'] ? '' : sanitize_email( $get( 'email' ) ),
			'zip'      => 'off' === $f['zip'] ? '' : $get( 'zip' ),
			'address'  => 'off' === $f['address'] ? '' : $get( 'address' ),
			'timeline' => 'off' === $f['timeline'] ? '' : $get( 'timeline' ),
			'notes'    => ( 'off' === $f['notes'] || ! isset( $p['notes'] ) ) ? '' : sanitize_textarea_field( (string) $p['notes'] ),
		);

		// Validate.
		if ( '' === $lead['name'] ) {
			return self::fail( 'Enter your name.' );
		}
		if ( strlen( preg_replace( '/\D/', '', $lead['phone'] ) ) < 10 ) {
			return self::fail( 'Enter a 10 digit phone number.' );
		}
		if ( 'off' !== $f['email'] && $get( 'email' ) && ! is_email( $lead['email'] ) ) {
			return self::fail( 'Check the email address format.' );
		}
		if ( 'off' !== $f['zip'] && $lead['zip'] && ! preg_match( '/^\d{5}$/', $lead['zip'] ) ) {
			return self::fail( 'Enter a 5 digit ZIP code.' );
		}
		$labels = array(
			'email'    => 'email address',
			'zip'      => 'ZIP code',
			'address'  => 'project address',
			'timeline' => 'timeline',
			'notes'    => 'project details',
		);
		foreach ( $labels as $k => $label ) {
			if ( 'required' === $f[ $k ] && '' === $lead[ $k ] ) {
				return self::fail( 'Enter your ' . $label . '.' );
			}
		}

		// Recompute the estimate on the server so the stored range can't be tampered with.
		$opt  = isset( $p['option'] ) ? absint( $p['option'] ) : 0;
		$opt  = isset( $c['options'][ $opt ] ) ? $opt : 0;
		$dims = array();
		foreach ( $c['dims'] as $i => $d ) {
			$v      = isset( $p['dims'][ $i ] ) ? (float) $p['dims'][ $i ] : $d['default'];
			$dims[] = min( max( $v, $d['min'] ), $d['max'] );
		}
		$addon_ids = array();
		foreach ( (array) ( isset( $p['addons'] ) ? $p['addons'] : array() ) as $a ) {
			$a = absint( $a );
			if ( isset( $c['addons'][ $a ] ) ) {
				$addon_ids[] = $a;
			}
		}
		$addon_ids = array_values( array_unique( $addon_ids ) );
		$est       = NSE_Config::estimate( $c, $opt, $dims, $addon_ids );

		$lead += array(
			'estimator'     => get_the_title( $post ),
			'estimator_id'  => $id,
			'option'        => isset( $c['options'][ $opt ] ) ? $c['options'][ $opt ]['name'] : '',
			'measurements'  => self::measurements( $c, $dims ),
			'quantity'      => $est['qty'] . ' ' . $c['unit_label'],
			'extras'        => array_map(
				function ( $i ) use ( $c ) {
					return $c['addons'][ $i ]['name'];
				},
				$addon_ids
			),
			'estimate_low'  => $est['low'],
			'estimate_high' => $est['high'],
			'page'          => isset( $p['page'] ) ? esc_url_raw( (string) $p['page'] ) : '',
			'submitted'     => current_time( 'mysql' ),
		);

		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'nse_lead',
				'post_status' => 'publish',
				'post_title'  => $lead['name'] . ' (' . $lead['estimator'] . ')',
			),
			true
		);
		if ( is_wp_error( $lead_id ) ) {
			return self::fail( 'We could not save your request. Please call us instead.', 500 );
		}
		update_post_meta( $lead_id, self::META_KEY, $lead );
		update_post_meta( $lead_id, '_nse_estimator_id', $id );

		self::notify( $c, $lead );

		return rest_ensure_response(
			array(
				'ok'   => true,
				'low'  => $est['low'],
				'high' => $est['high'],
			)
		);
	}

	private static function measurements( $c, $dims ) {
		$parts = array();
		foreach ( $c['dims'] as $i => $d ) {
			$parts[] = $d['label'] . ': ' . $dims[ $i ];
		}
		return implode( ', ', $parts );
	}

	private static function money( $n ) {
		return '$' . number_format( (float) $n );
	}

	private static function notify( $c, $lead ) {
		$to = $c['business']['notify_email'] ? $c['business']['notify_email'] : get_option( 'admin_email' );

		$lines = array(
			'New quote request from ' . $lead['estimator'],
			'',
			'Name: ' . $lead['name'],
			'Phone: ' . $lead['phone'],
		);
		foreach ( array( 'email' => 'Email', 'zip' => 'ZIP', 'address' => 'Address', 'timeline' => 'Timeline', 'notes' => 'Notes' ) as $k => $label ) {
			if ( '' !== $lead[ $k ] ) {
				$lines[] = $label . ': ' . $lead[ $k ];
			}
		}
		$lines[] = '';
		$lines[] = 'Choice: ' . $lead['option'];
		$lines[] = 'Size: ' . $lead['measurements'] . ' (' . $lead['quantity'] . ')';
		$lines[] = 'Extras: ' . ( $lead['extras'] ? implode( ', ', $lead['extras'] ) : 'None' );
		$lines[] = 'Estimate shown: ' . self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] );
		if ( $lead['page'] ) {
			$lines[] = 'Page: ' . $lead['page'];
		}

		$headers = array();
		if ( $lead['email'] ) {
			$headers[] = 'Reply-To: ' . $lead['name'] . ' <' . $lead['email'] . '>';
		}
		wp_mail( $to, 'New quote request: ' . $lead['name'], implode( "\n", $lines ), $headers );

		if ( $c['business']['webhook_url'] ) {
			wp_remote_post(
				$c['business']['webhook_url'],
				array(
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( $lead ),
					'timeout'  => 5,
					'blocking' => false,
				)
			);
		}
	}

	public static function meta_boxes() {
		add_meta_box( 'nse_lead_details', 'Request details', array( __CLASS__, 'render_details' ), 'nse_lead', 'normal', 'high' );
	}

	public static function render_details( $post ) {
		$lead = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_array( $lead ) ) {
			echo '<p>No details saved.</p>';
			return;
		}
		$rows = array(
			'Name'           => $lead['name'],
			'Phone'          => $lead['phone'],
			'Email'          => $lead['email'],
			'ZIP'            => $lead['zip'],
			'Address'        => $lead['address'],
			'Timeline'       => $lead['timeline'],
			'Notes'          => $lead['notes'],
			'Estimator'      => $lead['estimator'],
			'Choice'         => $lead['option'],
			'Size'           => $lead['measurements'] . ' (' . $lead['quantity'] . ')',
			'Extras'         => $lead['extras'] ? implode( ', ', $lead['extras'] ) : 'None',
			'Estimate shown' => self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] ),
			'Page'           => $lead['page'],
			'Submitted'      => $lead['submitted'],
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $val ) {
			if ( '' === (string) $val ) {
				continue;
			}
			printf( '<tr><th style="width:160px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $val ) );
		}
		echo '</tbody></table>';
	}

	public static function columns( $cols ) {
		return array(
			'cb'           => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'        => 'Lead',
			'nse_phone'    => 'Phone',
			'nse_choice'   => 'Choice',
			'nse_estimate' => 'Estimate',
			'date'         => 'Date',
		);
	}

	public static function column( $col, $post_id ) {
		$lead = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $lead ) ) {
			return;
		}
		if ( 'nse_phone' === $col ) {
			printf( '<a href="tel:%s">%s</a>', esc_attr( preg_replace( '/[^\d+]/', '', $lead['phone'] ) ), esc_html( $lead['phone'] ) );
		} elseif ( 'nse_choice' === $col ) {
			echo esc_html( $lead['option'] . ', ' . $lead['quantity'] );
		} elseif ( 'nse_estimate' === $col ) {
			echo esc_html( self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] ) );
		}
	}
}

<?php
/**
 * Lead capture: REST endpoint, storage as a private post type, email alert, optional webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Leads {

	const META_KEY = '_nse_lead';

	const ATTR_KEYS = array(
		'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
		'campaignid', 'adgroupid', 'keyword', 'matchtype', 'device',
		'landing_page', 'referrer', 'captured_at',
	);

	/**
	 * Keep only known attribution keys with short, clean values.
	 */
	public static function sanitize_attribution( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( self::ATTR_KEYS as $k ) {
			if ( ! isset( $raw[ $k ] ) || ! is_scalar( $raw[ $k ] ) || '' === (string) $raw[ $k ] ) {
				continue;
			}
			$v         = in_array( $k, array( 'landing_page', 'referrer' ), true ) ? esc_url_raw( (string) $raw[ $k ] ) : sanitize_text_field( (string) $raw[ $k ] );
			$out[ $k ] = substr( $v, 0, 300 );
		}
		return $out;
	}

	/**
	 * Short human label for where a lead came from.
	 */
	public static function source_label( $a ) {
		$a = is_array( $a ) ? $a : array();
		if ( ! empty( $a['gclid'] ) || ! empty( $a['gbraid'] ) || ! empty( $a['wbraid'] ) ) {
			$label = 'Google Ads';
		} elseif ( ! empty( $a['msclkid'] ) ) {
			$label = 'Microsoft Ads';
		} elseif ( ! empty( $a['fbclid'] ) ) {
			$label = 'Facebook';
		} elseif ( ! empty( $a['utm_source'] ) ) {
			$label = $a['utm_source'] . ( ! empty( $a['utm_medium'] ) ? ' / ' . $a['utm_medium'] : '' );
		} elseif ( ! empty( $a['referrer'] ) ) {
			$host  = wp_parse_url( $a['referrer'], PHP_URL_HOST );
			$host  = $host ? preg_replace( '/^www\./', '', $host ) : 'Referral';
			$label = preg_match( '/(^|\.)(google|bing|yahoo|duckduckgo)\./', $host ) ? 'Organic search (' . $host . ')' : $host;
		} else {
			return 'Direct';
		}
		if ( ! empty( $a['utm_campaign'] ) ) {
			$label .= ': ' . $a['utm_campaign'];
		}
		return $label;
	}

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
		$pi   = isset( $p['project'] ) ? absint( $p['project'] ) : 0;
		$pi   = isset( $c['projects'][ $pi ] ) ? $pi : 0;
		$proj = $c['projects'][ $pi ];
		$opt  = isset( $p['option'] ) ? absint( $p['option'] ) : 0;
		$opt  = isset( $proj['options'][ $opt ] ) ? $opt : 0;
		$dims = array();
		foreach ( $proj['dims'] as $i => $d ) {
			$v      = isset( $p['dims'][ $i ] ) ? (float) $p['dims'][ $i ] : $d['default'];
			$dims[] = min( max( $v, $d['min'] ), $d['max'] );
		}
		$addon_ids = array();
		foreach ( (array) ( isset( $p['addons'] ) ? $p['addons'] : array() ) as $a ) {
			$a = absint( $a );
			if ( isset( $proj['addons'][ $a ] ) ) {
				$addon_ids[] = $a;
			}
		}
		$addon_ids = array_values( array_unique( $addon_ids ) );
		$ci        = isset( $p['color'] ) ? absint( $p['color'] ) : 0;
		$ci        = isset( $proj['colors'][ $ci ] ) ? $ci : 0;
		$est       = NSE_Config::estimate( $c, $pi, $opt, $dims, $addon_ids, $ci );

		$lead += array(
			'estimator'     => get_the_title( $post ),
			'estimator_id'  => $id,
			'project'       => $proj['name'],
			'option'        => isset( $proj['options'][ $opt ] ) ? $proj['options'][ $opt ]['name'] : '',
			'color'         => isset( $proj['colors'][ $ci ] ) ? $proj['colors'][ $ci ]['name'] : '',
			'measurements'  => self::measurements( $proj, $dims ),
			'quantity'      => $est['qty'] . ' ' . $proj['unit_label'],
			'size'          => implode( ' x ', array_map( function ( $d ) { return $d . ' ft'; }, $dims ) ) . ( count( $dims ) > 1 ? ' (' . $est['qty'] . ' ' . $proj['unit_label'] . ')' : '' ),
			'extras'        => array_map(
				function ( $i ) use ( $proj ) {
					return $proj['addons'][ $i ]['name'];
				},
				$addon_ids
			),
			'estimate_low'  => $est['low'],
			'estimate_high' => $est['high'],
			'page'          => isset( $p['page'] ) ? esc_url_raw( (string) $p['page'] ) : '',
			'submitted'     => current_time( 'mysql' ),
		);
		$attr = self::sanitize_attribution( isset( $p['attribution'] ) ? $p['attribution'] : null );
		if ( ! $attr && isset( $_COOKIE['pe_attr'] ) ) {
			// Fallback if the page script could not read the cookie.
			$attr = self::sanitize_attribution( json_decode( wp_unslash( $_COOKIE['pe_attr'] ), true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$lead['source']      = self::source_label( $attr );
		$lead['attribution'] = $attr;

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
		NSE_Status::set( $lead_id, 'new' );
		if ( ! empty( $attr['gclid'] ) ) {
			update_post_meta( $lead_id, '_pe_gclid', $attr['gclid'] );
		}
		$lead['lead_id'] = $lead_id;

		// Branded PDF estimate with the visitor's illustration. A PDF problem never blocks the lead or its emails.
		$pdf_path = '';
		try {
			$pdf_path = NSE_Pdf::create( $lead_id, $lead, $c, isset( $p['illustration'] ) ? $p['illustration'] : '' );
		} catch ( Throwable $e ) {
			NSE_Pdf::log( $e );
		}
		$lead['pdf_url'] = $pdf_path ? NSE_Pdf::url( $lead_id ) : '';
		update_post_meta( $lead_id, self::META_KEY, $lead );

		try {
			self::notify( $c, $lead, $pdf_path );
		} catch ( Throwable $e ) {
			NSE_Pdf::log( $e );
		}
		if ( $pdf_path ) {
			try {
				self::email_customer( $c, $lead, $pdf_path );
			} catch ( Throwable $e ) {
				NSE_Pdf::log( $e );
			}
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'lead_id' => $lead_id,
				'pdf_url' => $lead['pdf_url'],
				'low'     => $est['low'],
				'high'    => $est['high'],
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

	private static function notify( $c, $lead, $pdf_path = '' ) {
		$rcpt = NSE_Settings::recipients( $c );

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
		$lines[] = 'Project: ' . $lead['project'];
		$lines[] = 'Choice: ' . $lead['option'];
		if ( $lead['color'] ) {
			$lines[] = 'Color: ' . $lead['color'];
		}
		$lines[] = 'Size: ' . $lead['measurements'] . ' (' . $lead['quantity'] . ')';
		$lines[] = 'Extras: ' . ( $lead['extras'] ? implode( ', ', $lead['extras'] ) : 'None' );
		$lines[] = 'Estimate shown: ' . self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] );
		if ( $lead['page'] ) {
			$lines[] = 'Page: ' . $lead['page'];
		}
		$lines[] = '';
		$lines[] = 'Source: ' . $lead['source'];
		foreach ( self::attribution_rows( $lead['attribution'] ) as $label => $val ) {
			$lines[] = $label . ': ' . $val;
		}

		$headers = array();
		if ( $lead['email'] ) {
			$headers[] = 'Reply-To: ' . $lead['name'] . ' <' . $lead['email'] . '>';
		}
		foreach ( $rcpt['cc'] as $addr ) {
			$headers[] = 'Cc: ' . $addr;
		}
		foreach ( $rcpt['bcc'] as $addr ) {
			$headers[] = 'Bcc: ' . $addr;
		}
		if ( ! empty( $lead['pdf_url'] ) ) {
			$lines[] = '';
			$lines[] = 'PDF estimate: ' . $lead['pdf_url'];
		}
		$settings    = NSE_Settings::get();
		$attachments = ( $pdf_path && $settings['pdf']['attach_business'] ) ? array( $pdf_path ) : array();
		wp_mail( $rcpt['to'], 'New ' . strtolower( $lead['project'] ) . ' quote request: ' . $lead['name'], implode( "\n", $lines ), $headers, $attachments );

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

	/**
	 * Send the customer their PDF estimate, when they gave an email and the setting is on.
	 */
	private static function email_customer( $c, $lead, $pdf_path ) {
		$settings = NSE_Settings::get();
		if ( empty( $settings['pdf']['send_customer'] ) || empty( $lead['email'] ) || ! is_email( $lead['email'] ) ) {
			return;
		}
		$parts = preg_split( '/\s+/', trim( $lead['name'] ) );
		$vars  = array(
			'{first_name}' => $parts ? $parts[0] : $lead['name'],
			'{name}'       => $lead['name'],
			'{business}'   => $c['business']['name'],
			'{phone}'      => $c['business']['phone'] ? $c['business']['phone'] : 'the number on our website',
			'{project}'    => strtolower( $lead['project'] ),
			'{low}'        => self::money( $lead['estimate_low'] ),
			'{high}'       => self::money( $lead['estimate_high'] ),
		);
		$subj_tpl = $settings['pdf']['customer_subject'];
		$body_tpl = $settings['pdf']['customer_message'];
		if ( '.' === substr( $c['business']['name'], -1 ) ) {
			/* Avoid "Co.." when the business name already ends with a period. */
			$subj_tpl = str_replace( '{business}.', '{business}', $subj_tpl );
			$body_tpl = str_replace( '{business}.', '{business}', $body_tpl );
		}
		$subject = ucfirst( strtr( $subj_tpl, $vars ) );
		$body    = strtr( $body_tpl, $vars );
		$body   .= "\n\nDownload your estimate anytime: " . $lead['pdf_url'];

		/* Replies go to the Reply-to setting, or the first lead recipient. */
		$rcpt     = NSE_Settings::recipients( $c );
		$reply_to = $settings['reply_to'] ? $settings['reply_to'] : ( ! empty( $rcpt['to'][0] ) ? $rcpt['to'][0] : '' );
		$headers  = array();
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . str_replace( array( '"', '<', '>' ), '', $c['business']['name'] ) . ' <' . $reply_to . '>';
		}
		wp_mail( $lead['email'], $subject, $body, $headers, array( $pdf_path ) );
	}

	/**
	 * Labeled attribution fields for display.
	 */
	public static function attribution_rows( $a ) {
		$labels = array(
			'utm_campaign' => 'Campaign',
			'utm_term'     => 'Keyword (utm_term)',
			'keyword'      => 'Keyword',
			'matchtype'    => 'Match type',
			'utm_source'   => 'UTM source',
			'utm_medium'   => 'UTM medium',
			'utm_content'  => 'UTM content',
			'campaignid'   => 'Campaign ID',
			'adgroupid'    => 'Ad group ID',
			'device'       => 'Device',
			'gclid'        => 'GCLID',
			'gbraid'       => 'GBRAID',
			'wbraid'       => 'WBRAID',
			'msclkid'      => 'MSCLKID',
			'fbclid'       => 'FBCLID',
			'landing_page' => 'Landing page',
			'referrer'     => 'Referrer',
			'captured_at'  => 'Source captured',
		);
		$rows = array();
		foreach ( $labels as $k => $label ) {
			if ( ! empty( $a[ $k ] ) ) {
				$rows[ $label ] = $a[ $k ];
			}
		}
		return $rows;
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
			'Project'        => isset( $lead['project'] ) ? $lead['project'] : '',
			'Choice'         => $lead['option'],
			'Color'          => isset( $lead['color'] ) ? $lead['color'] : '',
			'Size'           => $lead['measurements'] . ' (' . $lead['quantity'] . ')',
			'Extras'         => $lead['extras'] ? implode( ', ', $lead['extras'] ) : 'None',
			'Estimate shown' => self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] ),
			'Page'           => $lead['page'],
			'Submitted'      => $lead['submitted'],
			'Source'         => isset( $lead['source'] ) ? $lead['source'] : '',
		);
		$rows += self::attribution_rows( isset( $lead['attribution'] ) ? $lead['attribution'] : array() );
		if ( NSE_Pdf::path_for( $post->ID ) ) {
			printf( '<p><a class="button" href="%s" target="_blank" rel="noopener">View PDF estimate</a></p>', esc_url( NSE_Pdf::url( $post->ID ) ) );
		}
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
			'nse_choice'   => 'Project',
			'nse_estimate' => 'Estimate',
			'nse_source'   => 'Source',
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
			$proj = isset( $lead['project'] ) ? $lead['project'] . ': ' : '';
			echo esc_html( $proj . $lead['option'] . ', ' . $lead['quantity'] );
		} elseif ( 'nse_source' === $col ) {
			echo esc_html( isset( $lead['source'] ) ? $lead['source'] : 'Unknown' );
		} elseif ( 'nse_estimate' === $col ) {
			echo esc_html( self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] ) );
		}
	}
}

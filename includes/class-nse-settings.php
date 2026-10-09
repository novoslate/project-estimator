<?php
/**
 * Global settings (Estimators > Settings): lead email defaults and design defaults.
 * Also resolves each estimator's design into CSS variables and classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Settings {

	const OPTION = 'pe_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ---------- Defaults and access ---------- */

	public static function design_defaults() {
		return array(
			'style'              => 'card',
			'layout'             => 'mobile',
			'accent'             => '#1E3A3F',
			'text'               => '#1D2B2E',
			'muted'              => '#5D6F72',
			'background'         => '#FFFFFF',
			'border'             => '#D5DCDB',
			'font'               => 'inherit',
			'radius'             => 14,
			'max_width'          => 760,
			'show_business_name' => true,
			'show_step_numbers'  => true,
			'sticky_bar'         => true,
			'auto_advance'       => true,
		);
	}

	public static function pdf_defaults() {
		return array(
			'enabled'          => true,
			'send_customer'    => true,
			'attach_business'  => true,
			'customer_subject' => 'Your {project} estimate from {business}',
			'customer_message' => "Hi {first_name},\n\nThank you for your {project} request. Your estimate from {business} is attached as a PDF, along with the details you picked and an illustration of your project.\n\nEstimated price: {low} to {high}\n\nWe will reach out within one business day to set up a free on-site visit. Questions before then? Call us at {phone} or just reply to this email.\n\n{business}",
		);
	}

	public static function defaults() {
		return array(
			'notify_email' => '',
			'cc'           => '',
			'bcc'          => '',
			'logo_id'      => 0,
			'website'      => '',
			'reply_to'     => '',
			'booking_url'  => '',
			'webhook_alert' => '',
			'nutshell'     => NSE_Nutshell::defaults(),
			'recaptcha'    => NSE_Recaptcha::defaults(),
			'pdf'          => self::pdf_defaults(),
			'design'       => self::design_defaults(),
		);
	}

	public static function get() {
		$raw = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $raw ) ? $raw : array() );
	}

	public static function choices() {
		return array(
			'style'  => array(
				'card'   => 'Card (border)',
				'shadow' => 'Card (soft shadow)',
				'flat'   => 'Flat (blends into the page)',
			),
			'layout' => array(
				'mobile' => 'Step by step on phones, single page on larger screens',
				'always' => 'Step by step on all screens',
				'single' => 'Single page on all screens',
			),
			'font'   => array(
				'inherit' => 'Match the theme',
				'system'  => 'System sans-serif',
				'serif'   => 'Serif',
				'rounded' => 'Rounded',
			),
			'radius' => array(
				'2'  => 'Square',
				'8'  => 'Slightly rounded',
				'14' => 'Rounded',
				'22' => 'Extra rounded',
			),
		);
	}

	/* ---------- Sanitizing ---------- */

	/**
	 * Comma-separated list of valid emails, up to 10.
	 */
	public static function sanitize_emails( $raw ) {
		$parts = preg_split( '/[\s,;]+/', (string) $raw );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$e = sanitize_email( $p );
			if ( $e && is_email( $e ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
		}
		return implode( ', ', array_slice( $out, 0, 10 ) );
	}

	/**
	 * Website as shown to people: "rkcconstruction.com" from "https://www.rkcconstruction.com/" style input.
	 * Keeps "www." if entered, drops the scheme and trailing slash.
	 */
	public static function sanitize_website( $raw ) {
		$w = strtolower( trim( sanitize_text_field( (string) $raw ) ) );
		$w = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $w );
		$w = rtrim( $w, '/' );
		return preg_match( '#^[a-z0-9-]+(\.[a-z0-9-]+)+(/[a-z0-9._~/-]*)?$#', $w ) ? substr( $w, 0, 120 ) : '';
	}

	/**
	 * Website for PDFs: the setting, or this site's own domain.
	 */
	public static function website() {
		$s = self::get();
		if ( $s['website'] ) {
			return $s['website'];
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? $host : '';
	}

	/**
	 * Booking page URL (https only).
	 */
	public static function sanitize_booking_url( $raw ) {
		$u = esc_url_raw( trim( (string) $raw ), array( 'https' ) );
		return ( $u && wp_parse_url( $u, PHP_URL_HOST ) ) ? substr( $u, 0, 500 ) : '';
	}

	/**
	 * The booking link for an estimator: its own, or the site-wide default.
	 */
	public static function booking_url( array $c ) {
		if ( ! empty( $c['booking']['url'] ) ) {
			return $c['booking']['url'];
		}
		$s = self::get();
		return $s['booking_url'];
	}

	/**
	 * Add the customer's name and email for booking tools that support it (Calendly, Cal.com).
	 */
	public static function booking_prefill( $url, $name, $email ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! preg_match( '/(^|\.)(calendly\.com|cal\.com)$/', $host ) ) {
			return $url;
		}
		$args = array_filter( array( 'name' => $name, 'email' => $email ) );
		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
	}

	public static function sanitize_design( $d ) {
		$d   = is_array( $d ) ? $d : array();
		$def = self::design_defaults();
		$ch  = self::choices();
		$out = array();

		foreach ( array( 'accent', 'text', 'muted', 'background', 'border' ) as $k ) {
			$hex       = isset( $d[ $k ] ) ? sanitize_hex_color( $d[ $k ] ) : '';
			$out[ $k ] = $hex ? $hex : $def[ $k ];
		}
		$out['style']     = ( isset( $d['style'] ) && isset( $ch['style'][ $d['style'] ] ) ) ? $d['style'] : $def['style'];
		$out['layout']    = ( isset( $d['layout'] ) && isset( $ch['layout'][ $d['layout'] ] ) ) ? $d['layout'] : $def['layout'];
		$out['font']      = ( isset( $d['font'] ) && isset( $ch['font'][ $d['font'] ] ) ) ? $d['font'] : $def['font'];
		$out['radius']    = isset( $d['radius'] ) ? max( 0, min( 30, (int) $d['radius'] ) ) : $def['radius'];
		$out['max_width'] = isset( $d['max_width'] ) ? max( 320, min( 1600, (int) $d['max_width'] ) ) : $def['max_width'];
		foreach ( array( 'show_business_name', 'show_step_numbers', 'sticky_bar', 'auto_advance' ) as $k ) {
			$out[ $k ] = isset( $d[ $k ] ) ? (bool) $d[ $k ] : $def[ $k ];
		}
		return $out;
	}

	public static function sanitize_pdf( $p ) {
		$p   = is_array( $p ) ? $p : array();
		$def = self::pdf_defaults();
		return array(
			'enabled'          => isset( $p['enabled'] ) ? (bool) $p['enabled'] : $def['enabled'],
			'send_customer'    => isset( $p['send_customer'] ) ? (bool) $p['send_customer'] : $def['send_customer'],
			'attach_business'  => isset( $p['attach_business'] ) ? (bool) $p['attach_business'] : $def['attach_business'],
			'customer_subject' => isset( $p['customer_subject'] ) && '' !== trim( $p['customer_subject'] ) ? sanitize_text_field( $p['customer_subject'] ) : $def['customer_subject'],
			'customer_message' => isset( $p['customer_message'] ) && '' !== trim( $p['customer_message'] ) ? sanitize_textarea_field( $p['customer_message'] ) : $def['customer_message'],
		);
	}

	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'notify_email' => isset( $in['notify_email'] ) ? self::sanitize_emails( $in['notify_email'] ) : '',
			'cc'           => isset( $in['cc'] ) ? self::sanitize_emails( $in['cc'] ) : '',
			'bcc'          => isset( $in['bcc'] ) ? self::sanitize_emails( $in['bcc'] ) : '',
			'logo_id'      => isset( $in['logo_id'] ) ? absint( $in['logo_id'] ) : 0,
			'website'      => isset( $in['website'] ) ? self::sanitize_website( $in['website'] ) : '',
			'reply_to'     => isset( $in['reply_to'] ) && is_email( sanitize_email( $in['reply_to'] ) ) ? sanitize_email( $in['reply_to'] ) : '',
			'booking_url'  => isset( $in['booking_url'] ) ? self::sanitize_booking_url( $in['booking_url'] ) : '',
			'webhook_alert' => isset( $in['webhook_alert'] ) ? self::sanitize_emails( $in['webhook_alert'] ) : '',
			'nutshell'     => NSE_Nutshell::sanitize( isset( $in['nutshell'] ) ? $in['nutshell'] : array() ),
			'recaptcha'    => NSE_Recaptcha::sanitize( isset( $in['recaptcha'] ) ? $in['recaptcha'] : array() ),
			'pdf'          => self::sanitize_pdf( isset( $in['pdf'] ) ? $in['pdf'] : array() ),
			'design'       => self::sanitize_design( isset( $in['design'] ) ? $in['design'] : array() ),
		);
	}

	/**
	 * Settings API callback. Checkboxes are absent from POST when unchecked.
	 */
	public static function sanitize_option( $in ) {
		if ( isset( $_POST['pe_reset_defaults'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the nonce.
			add_settings_error( self::OPTION, 'pe_reset', 'Settings reset to defaults.', 'updated' );
			return self::defaults();
		}
		$in = is_array( $in ) ? $in : array();
		if ( isset( $in['design'] ) && is_array( $in['design'] ) ) {
			foreach ( array( 'show_business_name', 'show_step_numbers', 'sticky_bar', 'auto_advance' ) as $k ) {
				$in['design'][ $k ] = ! empty( $in['design'][ $k ] );
			}
		}
		if ( isset( $in['nutshell'] ) && is_array( $in['nutshell'] ) ) {
			$in['nutshell']['enabled'] = ! empty( $in['nutshell']['enabled'] );
			/* A blank API key field keeps the saved key. */
			if ( empty( $in['nutshell']['api_key'] ) ) {
				$saved                     = self::get();
				$in['nutshell']['api_key'] = $saved['nutshell']['api_key'];
			}
		}
		if ( isset( $in['recaptcha'] ) && is_array( $in['recaptcha'] ) ) {
			$in['recaptcha']['hide_badge'] = ! empty( $in['recaptcha']['hide_badge'] );
		}
		if ( isset( $in['pdf'] ) && is_array( $in['pdf'] ) ) {
			foreach ( array( 'enabled', 'send_customer', 'attach_business' ) as $k ) {
				$in['pdf'][ $k ] = ! empty( $in['pdf'][ $k ] );
			}
		}
		return self::sanitize( $in );
	}

	/* ---------- Resolving for an estimator ---------- */

	/**
	 * Lead recipients for an estimator. Blank estimator fields fall back to global settings.
	 *
	 * @return array{to:string,cc:string[],bcc:string[]}
	 */
	public static function recipients( array $c ) {
		$g    = self::get();
		$b    = $c['business'];
		$to   = $b['notify_email'] ? $b['notify_email'] : ( $g['notify_email'] ? $g['notify_email'] : get_option( 'admin_email' ) );
		$cc   = '' !== $b['cc'] ? $b['cc'] : $g['cc'];
		$bcc  = '' !== $b['bcc'] ? $b['bcc'] : $g['bcc'];
		$list = function ( $s ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', (string) $s ) ) ) );
		};
		return array(
			'to'  => $list( $to ),
			'cc'  => $list( $cc ),
			'bcc' => $list( $bcc ),
		);
	}

	public static function resolve_design( array $c ) {
		if ( isset( $c['design_mode'] ) && 'custom' === $c['design_mode'] && ! empty( $c['design'] ) ) {
			return self::sanitize_design( $c['design'] );
		}
		$g = self::get();
		return $g['design'];
	}

	private static function rgb( $hex ) {
		$n = hexdec( ltrim( $hex, '#' ) );
		return array( ( $n >> 16 ) & 255, ( $n >> 8 ) & 255, $n & 255 );
	}

	/**
	 * Blend two hex colors. $t = 0 returns $a, 1 returns $b.
	 */
	public static function mix( $a, $b, $t ) {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		$r = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$r[] = (int) round( $x[ $i ] + ( $y[ $i ] - $x[ $i ] ) * $t );
		}
		return sprintf( '#%02X%02X%02X', $r[0], $r[1], $r[2] );
	}

	/**
	 * Readable text color (white or near-black) on top of a background color.
	 */
	public static function on_color( $hex ) {
		list( $r, $g, $b ) = self::rgb( $hex );
		$lum               = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;
		return $lum > 0.6 ? '#1D2B2E' : '#FFFFFF';
	}

	public static function font_stack( $font ) {
		$stacks = array(
			'inherit' => 'inherit',
			'system'  => 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
			'serif'   => 'Georgia, "Times New Roman", serif',
			'rounded' => 'ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", system-ui, sans-serif',
		);
		return isset( $stacks[ $font ] ) ? $stacks[ $font ] : 'inherit';
	}

	/**
	 * Inline CSS variables for the estimator wrapper.
	 */
	public static function css_vars( array $d ) {
		$vars = array(
			'--nse-accent'    => $d['accent'],
			'--nse-on-accent' => self::on_color( $d['accent'] ),
			'--nse-ink'       => $d['text'],
			'--nse-muted'     => $d['muted'],
			'--nse-bg'        => $d['background'],
			'--nse-line'      => $d['border'],
			'--nse-soft'      => self::mix( $d['background'], $d['accent'], 0.07 ),
			'--nse-radius'    => (int) $d['radius'] . 'px',
			'--nse-maxw'      => (int) $d['max_width'] . 'px',
			'--nse-font'      => self::font_stack( $d['font'] ),
		);
		$css = '';
		foreach ( $vars as $k => $v ) {
			$css .= $k . ':' . $v . ';';
		}
		return $css;
	}

	public static function css_classes( array $d ) {
		$cls = array( 'nse', 'nse--' . $d['style'] );
		if ( ! $d['show_step_numbers'] ) {
			$cls[] = 'nse--no-steps';
		}
		if ( ! $d['sticky_bar'] ) {
			$cls[] = 'nse--static-bar';
		}
		return implode( ' ', $cls );
	}

	/* ---------- Admin page ---------- */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=nse_estimator', 'Estimator settings', 'Settings', 'manage_options', 'pe-settings', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'pe_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_option' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function assets( $hook ) {
		if ( 'nse_estimator_page_pe-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){'
			. '$(".pe-color").wpColorPicker();'
			. 'var frame;'
			. '$("#pe-logo-pick").on("click",function(e){e.preventDefault();'
			. 'if(!frame){frame=wp.media({title:"Choose a logo",library:{type:"image"},button:{text:"Use this logo"},multiple:false});'
			. 'frame.on("select",function(){var a=frame.state().get("selection").first().toJSON();'
			. '$("#pe-logo-id").val(a.id);$("#pe-logo-preview").html("<img src=\\""+(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url)+"\\" style=\\"max-height:60px;max-width:220px\\">");$("#pe-logo-remove").show();});}'
			. 'frame.open();});'
			. '$("#pe-logo-remove").on("click",function(e){e.preventDefault();$("#pe-logo-id").val("0");$("#pe-logo-preview").empty();$(this).hide();});'
			. '});'
		);
	}

	private static function name( $key, $design = false ) {
		return esc_attr( self::OPTION . ( $design ? '[design]' : '' ) . '[' . $key . ']' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s  = self::get();
		$d  = $s['design'];
		$ch = self::choices();

		echo '<div class="wrap"><h1>Estimator settings</h1>';
		echo '<p>These defaults apply to every estimator. Each estimator can override them in its own settings.</p>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'pe_settings' );

		echo '<h2>Lead email defaults</h2>';
		echo '<p>Used when an estimator leaves these fields blank. Separate multiple addresses with commas.</p>';
		echo '<table class="form-table" role="presentation">';
		$email_rows = array(
			'notify_email' => array( 'Send leads to', 'Falls back to the site admin email (' . get_option( 'admin_email' ) . ') when blank.' ),
			'cc'           => array( 'CC', 'Copied on every lead. Visible to other recipients.' ),
			'bcc'          => array( 'BCC', 'Blind copied on every lead. Hidden from other recipients.' ),
		);
		foreach ( $email_rows as $key => $row ) {
			printf(
				'<tr><th scope="row"><label for="pe-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="pe-%1$s" name="%3$s" value="%4$s" placeholder="name@example.com"><p class="description">%5$s</p></td></tr>',
				esc_attr( $key ),
				esc_html( $row[0] ),
				self::name( $key ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
				esc_attr( $s[ $key ] ),
				esc_html( $row[1] )
			);
		}
		echo '</table>';

		$p    = $s['pdf'];
		$logo = $s['logo_id'] ? wp_get_attachment_image_url( $s['logo_id'], 'medium' ) : '';
		echo '<h2>Estimate PDF and customer email</h2>';
		echo '<p>When someone requests a quote, a branded PDF with their selections, price range, and project illustration is created for the lead.</p>';
		echo '<table class="form-table" role="presentation">';
		printf(
			'<tr><th scope="row">Logo</th><td><input type="hidden" id="pe-logo-id" name="%s" value="%d"><div id="pe-logo-preview" style="margin-bottom:8px;display:inline-flex;align-items:center;min-height:60px;min-width:120px;padding:12px 18px;border-radius:6px;background:%s">%s</div><br><button type="button" class="button" id="pe-logo-pick">Choose logo</button> <button type="button" class="button-link" id="pe-logo-remove"%s>Remove</button><p class="description">Shown at the top of the PDF on the accent color band. A PNG with a transparent background works best. The preview shows it on the current accent color.</p></td></tr>',
			self::name( 'logo_id' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
			(int) $s['logo_id'],
			esc_attr( $d['accent'] ),
			$logo ? '<img src="' . esc_url( $logo ) . '" style="max-height:60px;max-width:220px" alt="">' : '',
			$logo ? '' : ' style="display:none"'
		);
		printf(
			'<tr><th scope="row"><label for="pe-website">Website shown on PDF</label></th><td><input type="text" class="regular-text" id="pe-website" name="%s" value="%s" placeholder="%s"><p class="description">Shown in the PDF header and footer. Use this when the estimator runs on a landing page subdomain but customers should see the main website. Leave blank to use %s.</p></td></tr>',
			self::name( 'website' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
			esc_attr( $s['website'] ),
			esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ),
			esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) )
		);
		echo '<tr><th scope="row">PDF</th><td><fieldset>';
		foreach ( array(
			'enabled'         => 'Create a PDF estimate for every quote request',
			'send_customer'   => 'Email the PDF to the customer when they enter an email address',
			'attach_business' => 'Attach the PDF to the lead email sent to the business',
		) as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%s" value="1"%s> %s</label><br>',
				esc_attr( self::OPTION . '[pdf][' . $key . ']' ),
				checked( $p[ $key ], true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></td></tr>';
		printf(
			'<tr><th scope="row"><label for="pe-booking">Booking link</label></th><td><input type="url" class="large-text" id="pe-booking" name="%s" value="%s" placeholder="https://calendly.com/your-company/on-site-visit"><p class="description">%s</p></td></tr>',
			self::name( 'booking_url' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
			esc_attr( $s['booking_url'] ),
			esc_html( 'Where customers book their on-site visit (Calendly, a Google Calendar booking page, Cal.com, Acuity, and others). Shown after the quote request, in the customer email, and in the PDF. Each estimator can use its own link instead.' )
		);
		printf(
			'<tr><th scope="row"><label for="pe-reply-to">Reply-to address</label></th><td><input type="email" class="regular-text" id="pe-reply-to" name="%s" value="%s" placeholder="%s"><p class="description">Where replies go when a customer answers their estimate email. Leave blank to use the first "Send leads to" address.</p></td></tr>',
			self::name( 'reply_to' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
			esc_attr( $s['reply_to'] ),
			esc_attr( $s['notify_email'] ? strtok( $s['notify_email'], ',' ) : get_option( 'admin_email' ) )
		);
		printf(
			'<tr><th scope="row"><label for="pe-cs">Customer email subject</label></th><td><input type="text" class="large-text" id="pe-cs" name="%s" value="%s"></td></tr>',
			esc_attr( self::OPTION . '[pdf][customer_subject]' ),
			esc_attr( $p['customer_subject'] )
		);
		printf(
			'<tr><th scope="row"><label for="pe-cm">Customer email message</label></th><td><textarea class="large-text" rows="9" id="pe-cm" name="%s">%s</textarea><p class="description">Placeholders: {first_name}, {name}, {business}, {phone}, {project}, {low}, {high}. A link to download the PDF is added below the message.</p></td></tr>',
			esc_attr( self::OPTION . '[pdf][customer_message]' ),
			esc_textarea( $p['customer_message'] )
		);
		echo '</table>';

		$r     = $s['recaptcha'];
		$rname = function ( $k ) {
			return esc_attr( self::OPTION . '[recaptcha][' . $k . ']' );
		};
		self::render_nutshell( $s );

		echo '<h2 id="pe-webhook">CRM webhook</h2>';
		echo '<p>Each estimator can send its leads to a webhook URL (for example a Zapier Catch Hook connected to the client\'s CRM). Failed deliveries (webhook or Nutshell) are retried for about 9 hours; this address is emailed if a lead still could not be delivered.</p>';
		echo '<table class="form-table" role="presentation">';
		printf(
			'<tr><th scope="row"><label for="pe-wh-alert">CRM failure alerts</label></th><td><input type="text" class="regular-text" id="pe-wh-alert" name="%s" value="%s" placeholder="%s"><p class="description">Leave blank to use the site admin email. Separate multiple addresses with commas.</p></td></tr>',
			self::name( 'webhook_alert' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
			esc_attr( $s['webhook_alert'] ),
			esc_attr( get_option( 'admin_email' ) )
		);
		echo '</table>';

		echo '<h2 id="pe-spam">Spam protection</h2>';
		echo '<p>Google reCAPTCHA blocks bots from submitting quote requests. Create keys at <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">google.com/recaptcha/admin</a>, choosing the same type as below and adding this site\'s domain. v2 and v3 keys are not interchangeable.</p>';
		if ( 'off' !== $r['mode'] && ( ! $r['site_key'] || ! $r['secret_key'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>reCAPTCHA is not running:</strong> add both the site key and the secret key.</p></div>';
		}
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">reCAPTCHA</th><td><fieldset>';
		foreach ( array(
			'off' => array( 'Off', 'The honeypot, minimum fill time, and rate limit still apply.' ),
			'v2'  => array( 'v2 Checkbox', 'Visitors check "I\'m not a robot" before sending. Sometimes asks for an image challenge.' ),
			'v3'  => array( 'v3 Invisible', 'No checkbox. Google scores each request in the background and low scores are blocked.' ),
		) as $val => $row ) {
			printf(
				'<label style="display:block;margin-bottom:6px"><input type="radio" name="%s" value="%s"%s> <strong>%s</strong> <span class="description">%s</span></label>',
				$rname( 'mode' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $rname.
				esc_attr( $val ),
				checked( $r['mode'], $val, false ),
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}
		echo '</fieldset></td></tr>';
		printf(
			'<tr class="pe-rc"><th scope="row"><label for="pe-rc-site">Site key</label></th><td><input type="text" class="regular-text code" id="pe-rc-site" name="%s" value="%s" autocomplete="off"></td></tr>',
			$rname( 'site_key' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $rname.
			esc_attr( $r['site_key'] )
		);
		printf(
			'<tr class="pe-rc"><th scope="row"><label for="pe-rc-secret">Secret key</label></th><td><input type="password" class="regular-text code" id="pe-rc-secret" name="%s" value="%s" autocomplete="new-password"><p class="description">Kept on the server. Never sent to visitors.</p></td></tr>',
			$rname( 'secret_key' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $rname.
			esc_attr( $r['secret_key'] )
		);
		echo '<tr class="pe-rc-v3"><th scope="row"><label for="pe-rc-th">Minimum score</label></th><td><select id="pe-rc-th" name="' . $rname( 'threshold' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $rname.
		foreach ( array( '0.3' => '0.3 (lenient, fewer real people blocked)', '0.5' => '0.5 (recommended)', '0.7' => '0.7 (strict, blocks more bots)' ) as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( (string) $r['threshold'], $val, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">Scores run from 0.0 (bot) to 1.0 (person). Each lead\'s score is saved so you can tune this.</p></td></tr>';
		printf(
			'<tr class="pe-rc-v3"><th scope="row">Badge</th><td><label><input type="checkbox" name="%s" value="1"%s> Hide the floating reCAPTCHA badge</label><p class="description">Google requires a notice instead, which is added under the form automatically.</p></td></tr>',
			$rname( 'hide_badge' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $rname.
			checked( $r['hide_badge'], true, false )
		);
		echo '</table>';
		echo '<script>(function(){function s(){var m=(document.querySelector(\'input[name="pe_settings[recaptcha][mode]"]:checked\')||{}).value;document.querySelectorAll(".pe-rc").forEach(function(r){r.style.display=m==="off"?"none":"";});document.querySelectorAll(".pe-rc-v3").forEach(function(r){r.style.display=m==="v3"?"":"none";});}document.querySelectorAll(\'input[name="pe_settings[recaptcha][mode]"]\').forEach(function(i){i.addEventListener("change",s);});s();})();</script>';

		echo '<h2>Design defaults</h2>';
		echo '<table class="form-table" role="presentation">';
		self::select_row( 'Style', 'style', $ch['style'], $d['style'] );
		self::select_row( 'Layout', 'layout', $ch['layout'], $d['layout'] );
		echo '<tr><td></td><td style="padding-top:0"><p class="description">Step by step shows one question per screen with a progress bar and the 3D preview kept on screen. "Phones" also applies when the estimator sits in a narrow column.</p></td></tr>';
		foreach ( array(
			'accent'     => array( 'Accent color', 'Buttons, selected options, and the price bar. Text on it switches between white and dark automatically.' ),
			'text'       => array( 'Text color', '' ),
			'muted'      => array( 'Secondary text color', 'Descriptions, labels, and fine print.' ),
			'background' => array( 'Background color', '' ),
			'border'     => array( 'Border color', '' ),
		) as $key => $row ) {
			printf(
				'<tr><th scope="row">%s</th><td><input type="text" class="pe-color" name="%s" value="%s" data-default-color="%s">%s</td></tr>',
				esc_html( $row[0] ),
				self::name( $key, true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
				esc_attr( $d[ $key ] ),
				esc_attr( self::design_defaults()[ $key ] ),
				$row[1] ? '<p class="description">' . esc_html( $row[1] ) . '</p>' : ''
			);
		}
		self::select_row( 'Font', 'font', $ch['font'], $d['font'] );
		self::select_row( 'Corners', 'radius', $ch['radius'], (string) $d['radius'] );
		printf(
			'<tr><th scope="row"><label for="pe-max-width">Max width</label></th><td><input type="number" id="pe-max-width" min="320" max="1600" step="10" name="%s" value="%d"> px</td></tr>',
			self::name( 'max_width', true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
			(int) $d['max_width']
		);
		echo '<tr><th scope="row">Show</th><td><fieldset>';
		foreach ( array(
			'show_business_name' => 'Business name above the headline',
			'show_step_numbers'  => 'Step numbers',
			'sticky_bar'         => 'Keep the price bar stuck to the bottom while scrolling',
			'auto_advance'       => 'Step by step: move to the next step after a project type, style, or color is picked',
		) as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%s" value="1"%s> %s</label><br>',
				self::name( $key, true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
				checked( $d[ $key ], true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></td></tr></table>';

		echo '<p class="submit">';
		submit_button( 'Save settings', 'primary', 'submit', false );
		echo ' ';
		submit_button( 'Reset to defaults', 'secondary', 'pe_reset_defaults', false, array( 'onclick' => "return confirm('Reset all estimator settings to defaults?');" ) );
		echo '</p></form></div>';
	}

	private static function render_nutshell( array $s ) {
		$n    = $s['nutshell'];
		$name = function ( $k ) {
			return esc_attr( self::OPTION . '[nutshell][' . $k . ']' );
		};
		echo '<h2 id="pe-nutshell">Nutshell CRM</h2>';
		echo '<p>Send every quote request straight to Nutshell as a contact and a lead, with the estimate, selections, source, and links to the PDF and photos in the lead note. Create an API key in Nutshell (Settings, then API keys) and use the email address of a Nutshell user.</p>';
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th scope="row">Nutshell</th><td><label><input type="checkbox" name="%s" value="1"%s> Send quote requests to Nutshell</label></td></tr>', $name( 'enabled' ), checked( $n['enabled'], true, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<tr><th scope="row"><label for="pe-ns-user">Nutshell user email</label></th><td><input type="text" class="regular-text" id="pe-ns-user" name="%s" value="%s" autocomplete="off" placeholder="you@company.com"></td></tr>', $name( 'username' ), esc_attr( $n['username'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<tr><th scope="row"><label for="pe-ns-key">API key</label></th><td><input type="password" class="regular-text code" id="pe-ns-key" name="%s" value="" autocomplete="new-password" placeholder="%s"><p class="description">%s</p><p><button type="button" class="button" id="pe-ns-test">Test connection</button> <span id="pe-ns-result" role="status"></span></p></td></tr>',
			$name( 'api_key' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $n['api_key'] ? 'Saved (leave blank to keep it)' : 'Paste the API key' ),
			esc_html( 'Stored on this site only. Never shown again, and never included in Import / Export.' )
		);
		$labels = $n['labels'];
		$asg    = '<option value="">Nutshell default (no assignee)</option>';
		$prd    = '<option value="0">None (leads have no dollar value)</option>';
		foreach ( $labels as $val => $label ) {
			if ( preg_match( '/^(Users|Teams):\d+$/', $val ) ) {
				$asg .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $n['assignee'], $val, false ), esc_html( ( 0 === strpos( $val, 'Teams' ) ? 'Team: ' : '' ) . $label ) );
			} elseif ( ctype_digit( (string) $val ) ) {
				$prd .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( (string) $n['product_id'], (string) $val, false ), esc_html( $label ) );
			}
		}
		printf( '<tr><th scope="row"><label for="pe-ns-asg">Assign new leads to</label></th><td><select id="pe-ns-asg" name="%s">%s</select><p class="description">Click Test connection to load your Nutshell users and teams.</p></td></tr>', $name( 'assignee' ), $asg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<tr><th scope="row"><label for="pe-ns-prd">Lead value</label></th><td><select id="pe-ns-prd" name="%s">%s</select><p class="description">Nutshell sets a lead\'s value from its products. Pick a product (for example "Patio project") and each lead gets it priced at the middle of the estimate.</p></td></tr>', $name( 'product_id' ), $prd ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<tr><th scope="row">Lead source</th><td><label><input type="radio" name="%1$s" value="attribution"%2$s> The visitor\'s source (Google Ads, Organic search, Facebook...)</label><br><label><input type="radio" name="%1$s" value="fixed"%3$s> Always the name below</label><p><input type="text" class="regular-text" name="%4$s" value="%5$s"></p><p class="description">Used as the fixed source, and for direct visits. Sources are created in Nutshell if they don\'t exist.</p></td></tr>',
			$name( 'source_mode' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			checked( $n['source_mode'], 'attribution', false ),
			checked( $n['source_mode'], 'fixed', false ),
			$name( 'source_name' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $n['source_name'] )
		);
		printf( '<tr><th scope="row"><label for="pe-ns-tags">Tags</label></th><td><input type="text" class="regular-text" id="pe-ns-tags" name="%s" value="%s"><p class="description">Comma separated. Added to each lead and created in Nutshell if needed.</p></td></tr>', $name( 'tags' ), esc_attr( $n['tags'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table><div id="pe-ns-labels">';
		foreach ( $labels as $val => $label ) {
			printf( '<input type="hidden" name="%s[%s]" value="%s">', esc_attr( self::OPTION . '[nutshell][labels]' ), esc_attr( $val ), esc_attr( $label ) );
		}
		echo '</div>';
		$nonce = wp_create_nonce( 'pe_nutshell_test' );
		?>
		<script>
		(function () {
			var btn = document.getElementById('pe-ns-test'), out = document.getElementById('pe-ns-result');
			if (!btn) return;
			function fill(sel, items, keep, first) {
				var cur = keep || sel.value;
				sel.innerHTML = '';
				sel.appendChild(new Option(first[1], first[0]));
				items.forEach(function (it) { var o = new Option(it.label, it.value); if (it.value === cur) o.selected = true; sel.appendChild(o); });
			}
			btn.addEventListener('click', function () {
				var fd = new FormData();
				fd.append('action', 'pe_nutshell_test');
				fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
				fd.append('username', document.getElementById('pe-ns-user').value);
				fd.append('api_key', document.getElementById('pe-ns-key').value);
				btn.disabled = true; out.textContent = 'Connecting...'; out.style.color = '';
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
					out.textContent = (j && j.data && j.data.message) || 'Unexpected response.';
					out.style.color = j && j.success ? '#1F6B35' : '#B32D2E';
					if (j && j.success) {
						var people = j.data.users.concat(j.data.teams.map(function (t) { return { value: t.value, label: 'Team: ' + t.label }; }));
						fill(document.getElementById('pe-ns-asg'), people, null, ['', 'Nutshell default (no assignee)']);
						fill(document.getElementById('pe-ns-prd'), j.data.products, null, ['0', 'None (leads have no dollar value)']);
						var box = document.getElementById('pe-ns-labels'); box.innerHTML = '';
						j.data.users.concat(j.data.teams, j.data.products).forEach(function (it) {
							var h = document.createElement('input'); h.type = 'hidden'; h.name = 'pe_settings[nutshell][labels][' + it.value + ']'; h.value = it.label; box.appendChild(h);
						});
					}
				}).catch(function () { out.textContent = 'Could not reach WordPress.'; out.style.color = '#B32D2E'; })
				.then(function () { btn.disabled = false; });
			});
		})();
		</script>
		<?php
	}

	private static function select_row( $label, $key, array $choices, $current ) {
		printf( '<tr><th scope="row"><label for="pe-%1$s">%2$s</label></th><td><select id="pe-%1$s" name="%3$s">', esc_attr( $key ), esc_html( $label ), self::name( $key, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
		foreach ( $choices as $val => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( (string) $current, (string) $val, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}
}

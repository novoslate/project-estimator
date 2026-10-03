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
		$out['font']      = ( isset( $d['font'] ) && isset( $ch['font'][ $d['font'] ] ) ) ? $d['font'] : $def['font'];
		$out['radius']    = isset( $d['radius'] ) ? max( 0, min( 30, (int) $d['radius'] ) ) : $def['radius'];
		$out['max_width'] = isset( $d['max_width'] ) ? max( 320, min( 1600, (int) $d['max_width'] ) ) : $def['max_width'];
		foreach ( array( 'show_business_name', 'show_step_numbers', 'sticky_bar' ) as $k ) {
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
			foreach ( array( 'show_business_name', 'show_step_numbers', 'sticky_bar' ) as $k ) {
				$in['design'][ $k ] = ! empty( $in['design'][ $k ] );
			}
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

		echo '<h2>Design defaults</h2>';
		echo '<table class="form-table" role="presentation">';
		self::select_row( 'Style', 'style', $ch['style'], $d['style'] );
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

	private static function select_row( $label, $key, array $choices, $current ) {
		printf( '<tr><th scope="row"><label for="pe-%1$s">%2$s</label></th><td><select id="pe-%1$s" name="%3$s">', esc_attr( $key ), esc_html( $label ), self::name( $key, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in name().
		foreach ( $choices as $val => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( (string) $current, (string) $val, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}
}

<?php
/**
 * Front end: [project_estimator id="123"] shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'project_estimator', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'novoslate_estimator', array( __CLASS__, 'shortcode' ) ); // Legacy alias.
	}

	public static function register_assets() {
		wp_register_style( 'nse-estimator', NSE_URL . 'assets/estimator.css', array(), NSE_VERSION );
		wp_register_script( 'pe-preview', NSE_URL . 'assets/preview.js', array(), NSE_VERSION, true );
		wp_register_script( 'nse-estimator', NSE_URL . 'assets/estimator.js', array( 'pe-preview' ), NSE_VERSION, true );

		/**
		 * Attribution capture runs on every page so ad clicks are remembered
		 * even when visitors land on one page and request a quote on another.
		 * Disable with: add_filter( 'pe_capture_attribution', '__return_false' );
		 */
		if ( apply_filters( 'pe_capture_attribution', true ) ) {
			wp_enqueue_script( 'pe-attribution', NSE_URL . 'assets/attribution.js', array(), NSE_VERSION, false );
		}
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'project_estimator' );
		$id   = absint( $atts['id'] );
		$post = $id ? get_post( $id ) : null;

		if ( ! $post || 'nse_estimator' !== $post->post_type || 'publish' !== $post->post_status ) {
			return current_user_can( 'edit_posts' ) ? '<p><strong>Estimator not found.</strong> Check the id in the shortcode and make sure the estimator is published.</p>' : '';
		}

		if ( ! wp_script_is( 'nse-estimator', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'nse-estimator' );
		wp_enqueue_script( 'nse-estimator' );
		$rc_url = NSE_Recaptcha::script_url();
		if ( $rc_url ) {
			// Only pages with an estimator load Google's script.
			wp_enqueue_script( 'pe-recaptcha', $rc_url, array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's URL must not get a version query.
		}

		$config = NSE_Config::public_config( NSE_Config::get( $id ) );
		$design = $config['design'];

		return sprintf(
			'<div class="%s" style="%s" data-id="%d" data-name="%s" data-ts="%d" data-endpoint="%s" data-track="%s" data-config="%s"><noscript>Turn on JavaScript to use the price estimator.</noscript></div>',
			esc_attr( NSE_Settings::css_classes( $design ) ),
			esc_attr( NSE_Settings::css_vars( $design ) ),
			$id,
			esc_attr( get_the_title( $post ) ),
			time(),
			esc_url( rest_url( 'nse/v1/lead' ) ),
			/* Editors and admins previewing their own site are not counted in the dashboard. */
			current_user_can( 'edit_posts' ) ? '' : esc_url( rest_url( 'nse/v1/track' ) ),
			esc_attr( wp_json_encode( $config ) )
		);
	}
}

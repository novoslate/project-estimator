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
		wp_register_script( 'nse-estimator', NSE_URL . 'assets/estimator.js', array(), NSE_VERSION, true );
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

		$config = NSE_Config::public_config( NSE_Config::get( $id ) );

		return sprintf(
			'<div class="nse" data-id="%d" data-ts="%d" data-endpoint="%s" data-config="%s"><noscript>Turn on JavaScript to use the price estimator.</noscript></div>',
			$id,
			time(),
			esc_url( rest_url( 'nse/v1/lead' ) ),
			esc_attr( wp_json_encode( $config ) )
		);
	}
}

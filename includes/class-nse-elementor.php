<?php
/**
 * Elementor integration. Loads only when Elementor is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Elementor {

	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( 'NSE_Frontend', 'register_assets' ) );
	}

	public static function register( $widgets_manager ) {
		require_once NSE_PATH . 'includes/elementor/class-nse-elementor-widget.php';
		$widgets_manager->register( new NSE_Elementor_Widget() );
	}

	/**
	 * True inside the Elementor editor or its preview frame.
	 */
	public static function is_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return false;
		}
		$el = \Elementor\Plugin::$instance;
		return ( isset( $el->editor ) && $el->editor->is_edit_mode() ) || ( isset( $el->preview ) && $el->preview->is_preview_mode() );
	}
}

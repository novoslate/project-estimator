<?php
/**
 * Admin screens: estimator post type, the visual config editor, and shortcode helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Admin {

	public static function init() {
		add_action( 'add_meta_boxes_nse_estimator', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_nse_estimator', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_nse_estimator_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_nse_estimator_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function register_post_types() {
		register_post_type(
			'nse_estimator',
			array(
				'labels'       => array(
					'name'          => 'Estimators',
					'singular_name' => 'Estimator',
					'add_new_item'  => 'Add new estimator',
					'edit_item'     => 'Edit estimator',
					'all_items'     => 'All estimators',
					'menu_name'     => 'Estimators',
				),
				'public'       => false,
				'show_ui'      => true,
				'menu_icon'    => 'dashicons-calculator',
				'supports'     => array( 'title' ),
				'show_in_rest' => false,
			)
		);

		register_post_type(
			'nse_lead',
			array(
				'labels'       => array(
					'name'          => 'Leads',
					'singular_name' => 'Lead',
					'edit_item'     => 'Lead details',
					'all_items'     => 'Leads',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=nse_estimator',
				'supports'     => array( 'title' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function meta_boxes() {
		add_meta_box( 'nse_editor', 'Estimator settings', array( __CLASS__, 'render_editor' ), 'nse_estimator', 'normal', 'high' );
		add_meta_box( 'nse_shortcode', 'Add to a page', array( __CLASS__, 'render_shortcode' ), 'nse_estimator', 'side', 'high' );
	}

	public static function render_editor( $post ) {
		wp_nonce_field( 'nse_save', 'nse_nonce' );
		echo '<div id="nse-editor"><p>Loading settings...</p></div>';
		echo '<input type="hidden" name="nse_config_json" id="nse_config_json" value="">';
	}

	public static function render_shortcode( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Publish this estimator to get its shortcode.</p>';
			return;
		}
		$code = sprintf( '[project_estimator id="%d"]', $post->ID );
		printf(
			'<p>Paste this into any page, or into an Elementor Shortcode widget:</p><input type="text" readonly class="widefat code" value="%s" onclick="this.select()">',
			esc_attr( $code )
		);
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['nse_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nse_nonce'] ) ), 'nse_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['nse_config_json'] ) ) {
			return;
		}
		// Decoded JSON is fully sanitized by NSE_Config::sanitize().
		$raw = json_decode( wp_unslash( $_POST['nse_config_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $raw ) ) {
			return;
		}
		update_post_meta( $post_id, NSE_Config::META_KEY, wp_slash( wp_json_encode( NSE_Config::sanitize( $raw ) ) ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'nse_estimator' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		global $post;
		$saved = ( $post && get_post_meta( $post->ID, NSE_Config::META_KEY, true ) ) ? NSE_Config::get( $post->ID ) : null;

		$templates = array();
		foreach ( NSE_Templates::all() as $slug => $t ) {
			$templates[ $slug ] = array(
				'label'  => $t['label'],
				'config' => NSE_Config::sanitize( $t['config'] ),
			);
		}

		wp_enqueue_style( 'nse-admin', NSE_URL . 'assets/admin.css', array(), NSE_VERSION );
		wp_enqueue_script( 'nse-admin', NSE_URL . 'assets/admin.js', array(), NSE_VERSION, true );
		wp_localize_script(
			'nse-admin',
			'NSE_ADMIN',
			array(
				'templates'  => $templates,
				'library'    => array_map( array( 'NSE_Config', 'sanitize_project' ), NSE_Templates::library() ),
				'preview'    => NSE_Preview::admin_catalog(),
				'config'     => $saved,
				'defaults'   => NSE_Config::defaults(),
				'adminEmail' => get_option( 'admin_email' ),
			)
		);
	}

	public static function columns( $cols ) {
		$cols['nse_shortcode'] = 'Shortcode';
		return $cols;
	}

	public static function column( $col, $post_id ) {
		if ( 'nse_shortcode' === $col ) {
			printf( '<code>[project_estimator id="%d"]</code>', (int) $post_id );
		}
	}
}

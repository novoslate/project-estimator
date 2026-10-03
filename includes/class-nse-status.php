<?php
/**
 * Lead status tracking: New, Contacted, Quoted, Booked, Lost, plus a booked job value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Status {

	const META       = '_pe_status';
	const META_LOG   = '_pe_status_log';
	const META_VALUE = '_pe_booked_value';

	public static function statuses() {
		return array(
			'new'       => 'New',
			'contacted' => 'Contacted',
			'quoted'    => 'Quoted',
			'booked'    => 'Booked',
			'lost'      => 'Lost',
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes_nse_lead', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_nse_lead', array( __CLASS__, 'save' ) );
		add_filter( 'manage_nse_lead_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_nse_lead_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
		add_filter( 'bulk_actions-edit-nse_lead', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-nse_lead', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'styles' ) );
	}

	/* ---------- Data ---------- */

	public static function get( $lead_id ) {
		$s = get_post_meta( $lead_id, self::META, true );
		return isset( self::statuses()[ $s ] ) ? $s : 'new';
	}

	/**
	 * Set a status and remember when each status was first reached.
	 */
	public static function set( $lead_id, $status ) {
		if ( ! isset( self::statuses()[ $status ] ) || self::get( $lead_id ) === $status && get_post_meta( $lead_id, self::META, true ) ) {
			return;
		}
		update_post_meta( $lead_id, self::META, $status );
		$log = get_post_meta( $lead_id, self::META_LOG, true );
		$log = is_array( $log ) ? $log : array();
		if ( empty( $log[ $status ] ) ) {
			$log[ $status ] = time();
			update_post_meta( $lead_id, self::META_LOG, $log );
		}
	}

	/**
	 * Unix time the lead was first marked with a status, or 0.
	 */
	public static function reached( $lead_id, $status ) {
		$log = get_post_meta( $lead_id, self::META_LOG, true );
		return ( is_array( $log ) && ! empty( $log[ $status ] ) ) ? (int) $log[ $status ] : 0;
	}

	public static function booked_value( $lead_id ) {
		$v = get_post_meta( $lead_id, self::META_VALUE, true );
		return '' === $v ? null : (float) $v;
	}

	/* ---------- Lead screen ---------- */

	public static function meta_box() {
		add_meta_box( 'pe_status', 'Status', array( __CLASS__, 'render_box' ), 'nse_lead', 'side', 'high' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'pe_status_save', 'pe_status_nonce' );
		$current = self::get( $post->ID );
		echo '<p><label for="pe-status" class="screen-reader-text">Status</label><select id="pe-status" name="pe_status" style="width:100%">';
		foreach ( self::statuses() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		$value = self::booked_value( $post->ID );
		printf(
			'<p><label for="pe-booked-value"><strong>Booked job value</strong></label><br><input type="text" inputmode="decimal" id="pe-booked-value" name="pe_booked_value" value="%s" style="width:100%%" placeholder="$18,500"></p><p class="description">Optional. Used as the conversion value in the Google Ads offline conversions export. Without it, the middle of the estimate is used.</p>',
			null === $value ? '' : esc_attr( '$' . number_format( $value, floor( $value ) == $value ? 0 : 2 ) )
		);
		$log = get_post_meta( $post->ID, self::META_LOG, true );
		if ( is_array( $log ) && $log ) {
			echo '<p class="description" style="margin-top:10px">';
			foreach ( self::statuses() as $key => $label ) {
				if ( ! empty( $log[ $key ] ) ) {
					echo esc_html( $label . ': ' . wp_date( 'M j, Y g:i a', $log[ $key ] ) ) . '<br>';
				}
			}
			echo '</p>';
		}
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['pe_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pe_status_nonce'] ) ), 'pe_status_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['pe_status'] ) ) {
			self::set( $post_id, sanitize_key( wp_unslash( $_POST['pe_status'] ) ) );
		}
		if ( isset( $_POST['pe_booked_value'] ) ) {
			$raw = trim( sanitize_text_field( wp_unslash( $_POST['pe_booked_value'] ) ) );
			if ( '' === $raw ) {
				delete_post_meta( $post_id, self::META_VALUE );
			} else {
				update_post_meta( $post_id, self::META_VALUE, max( 0, round( (float) preg_replace( '/[^0-9.]/', '', $raw ), 2 ) ) );
			}
		}
	}

	/* ---------- Leads list ---------- */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['pe_status'] = 'Status';
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'pe_status' !== $col ) {
			return;
		}
		$s = self::get( $post_id );
		printf( '<span class="pe-badge pe-badge--%s">%s</span>', esc_attr( $s ), esc_html( self::statuses()[ $s ] ) );
		$v = self::booked_value( $post_id );
		if ( 'booked' === $s && null !== $v ) {
			echo '<br><small>$' . esc_html( number_format( $v ) ) . '</small>';
		}
	}

	public static function styles() {
		if ( 'nse_lead' !== get_current_screen()->post_type ) {
			return;
		}
		echo '<style>
			.column-pe_status{width:110px}
			.pe-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;line-height:1.6}
			.pe-badge--new{background:#E5F0FA;color:#1D5A8E}
			.pe-badge--contacted{background:#FFF4D6;color:#7A5A00}
			.pe-badge--quoted{background:#EFE7FA;color:#5B3A8E}
			.pe-badge--booked{background:#DFF3E4;color:#1F6B35}
			.pe-badge--lost{background:#F1F1F1;color:#666}
		</style>';
	}

	public static function filter_dropdown( $post_type ) {
		if ( 'nse_lead' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['pe_status'] ) ? sanitize_key( wp_unslash( $_GET['pe_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label for="pe-filter-status" class="screen-reader-text">Filter by status</label><select name="pe_status" id="pe-filter-status"><option value="">All statuses</option>';
		foreach ( self::statuses() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Meta query for a status. Leads saved before statuses existed count as New.
	 */
	public static function meta_query( $status ) {
		if ( 'new' === $status ) {
			return array(
				'relation' => 'OR',
				array( 'key' => self::META, 'value' => 'new' ),
				array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
			);
		}
		return array( array( 'key' => self::META, 'value' => $status ) );
	}

	public static function apply_filter( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || 'nse_lead' !== $q->get( 'post_type' ) || empty( $_GET['pe_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['pe_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::statuses()[ $status ] ) ) {
			$q->set( 'meta_query', self::meta_query( $status ) );
		}
	}

	public static function bulk_actions( $actions ) {
		foreach ( self::statuses() as $key => $label ) {
			$actions[ 'pe_mark_' . $key ] = 'Mark as ' . $label;
		}
		$actions['pe_export'] = 'Export to CSV';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'pe_export' === $action ) {
			if ( current_user_can( NSE_Export::CAP ) ) {
				NSE_Export::stream( array_map( 'absint', (array) $ids ), 'full', array() );
			}
			return $redirect;
		}
		if ( 0 !== strpos( $action, 'pe_mark_' ) ) {
			return $redirect;
		}
		$status = substr( $action, 8 );
		$count  = 0;
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				self::set( (int) $id, $status );
				$count++;
			}
		}
		return add_query_arg( array( 'pe_marked' => $count, 'pe_marked_as' => $status ), $redirect );
	}

	public static function bulk_notice() {
		if ( empty( $_GET['pe_marked'] ) || empty( $_GET['pe_marked_as'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$n      = absint( $_GET['pe_marked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_GET['pe_marked_as'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::statuses()[ $status ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( '%d %s marked as %s.', $n, 1 === $n ? 'lead' : 'leads', self::statuses()[ $status ] ) ) );
		}
	}
}

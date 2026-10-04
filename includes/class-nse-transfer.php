<?php
/**
 * Import / Export of estimators and site-wide settings as a JSON file,
 * plus Duplicate and Export row actions on the Estimators list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Transfer {

	const CAP     = 'manage_options';
	const FORMAT  = 1;
	const MAX_MB  = 8;

	/** Estimator business fields that identify a specific client. */
	const BUSINESS_KEYS = array( 'name', 'phone', 'notify_email', 'cc', 'bcc', 'webhook_url' );

	/** Site-wide settings that identify a specific client. */
	const SETTINGS_CLIENT_KEYS = array( 'notify_email', 'cc', 'bcc', 'reply_to', 'webhook_alert', 'website' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_action( 'admin_post_pe_settings_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_pe_settings_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_pe_settings_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_pe_duplicate', array( __CLASS__, 'handle_duplicate' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'duplicate_notice' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=nse_estimator', 'Import / Export', 'Import / Export', self::CAP, 'pe-transfer', array( __CLASS__, 'render' ) );
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=nse_estimator&page=pe-transfer' ) );
	}

	private static function transient_key() {
		return 'pe_import_' . get_current_user_id();
	}

	/* ---------- Building the export ---------- */

	public static function build( array $ids, $include_settings, $include_business ) {
		$data = array(
			'plugin'            => 'project-estimator',
			'format'            => self::FORMAT,
			'plugin_version'    => NSE_VERSION,
			'exported_at'       => gmdate( 'c' ),
			'site'              => home_url(),
			'includes_business' => (bool) $include_business,
			'estimators'        => array(),
			'settings'          => null,
			'logo'              => null,
		);
		foreach ( $ids as $id ) {
			if ( 'nse_estimator' !== get_post_type( $id ) ) {
				continue;
			}
			$c = NSE_Config::get( $id );
			if ( ! $include_business ) {
				foreach ( self::BUSINESS_KEYS as $k ) {
					$c['business'][ $k ] = '';
				}
			}
			$data['estimators'][] = array(
				'title'  => get_the_title( $id ),
				'status' => get_post_status( $id ),
				'config' => $c,
			);
		}
		if ( $include_settings ) {
			$s = NSE_Settings::get();
			if ( ! $include_business ) {
				foreach ( self::SETTINGS_CLIENT_KEYS as $k ) {
					$s[ $k ] = '';
				}
			}
			/* reCAPTCHA keys are tied to a domain, and the secret must never sit in a file. */
			$s['recaptcha']['site_key']   = '';
			$s['recaptcha']['secret_key'] = '';
			$logo_id                      = $s['logo_id'];
			unset( $s['logo_id'], $s['nutshell'] ); // CRM connections belong to one client and include a secret key.
			$data['settings'] = $s;
			$data['logo']     = self::logo_payload( $logo_id );
		}
		return $data;
	}

	private static function logo_payload( $id ) {
		if ( ! $id ) {
			return null;
		}
		$path = get_attached_file( $id );
		if ( ! $path || ! file_exists( $path ) || filesize( $path ) > 3 * MB_IN_BYTES ) {
			return null;
		}
		$type = wp_check_filetype( $path );
		if ( ! in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ), true ) ) {
			return null;
		}
		return array(
			'filename' => basename( $path ),
			'mime'     => $type['type'],
			'data'     => base64_encode( file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have permission to export settings.' );
		}
		check_admin_referer( 'pe_settings_export', 'pe_nonce' );
		$ids = isset( $_POST['estimators'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['estimators'] ) ) : array();
		$set = ! empty( $_POST['include_settings'] );
		if ( ! $ids && ! $set ) {
			wp_safe_redirect( self::page_url( array( 'pe_msg' => 'nothing' ) ) );
			exit;
		}
		$data = self::build( $ids, $set, ! empty( $_POST['include_business'] ) );
		$host = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$name = 'project-estimator-' . $host . '-' . wp_date( 'Y-m-d' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/* ---------- Reading an import file ---------- */

	/**
	 * Validate an uploaded file. Returns the data array or a WP_Error.
	 */
	public static function parse( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pe_bad_json', 'That file is not a valid Project Estimator export (it could not be read as JSON).' );
		}
		if ( ! isset( $data['plugin'] ) || 'project-estimator' !== $data['plugin'] ) {
			return new WP_Error( 'pe_wrong_file', 'That file is not a Project Estimator export.' );
		}
		if ( ! isset( $data['format'] ) || (int) $data['format'] > self::FORMAT ) {
			return new WP_Error( 'pe_newer', 'That file was made by a newer version of Project Estimator. Update this site first, then import again.' );
		}
		$data['estimators'] = isset( $data['estimators'] ) && is_array( $data['estimators'] ) ? array_values( array_filter( $data['estimators'], 'is_array' ) ) : array();
		$data['settings']   = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : null;
		if ( ! $data['estimators'] && ! $data['settings'] ) {
			return new WP_Error( 'pe_empty', 'That file has no estimators or settings in it.' );
		}
		return $data;
	}

	public static function handle_upload() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have permission to import settings.' );
		}
		check_admin_referer( 'pe_settings_upload', 'pe_nonce' );
		$f = isset( $_FILES['pe_file'] ) ? $_FILES['pe_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			wp_safe_redirect( self::page_url( array( 'pe_err' => rawurlencode( 'Choose an export file to upload.' ) ) ) );
			exit;
		}
		if ( $f['size'] > self::MAX_MB * MB_IN_BYTES ) {
			wp_safe_redirect( self::page_url( array( 'pe_err' => rawurlencode( 'That file is too large to be a settings export.' ) ) ) );
			exit;
		}
		$data = self::parse( file_get_contents( $f['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $data ) ) {
			wp_safe_redirect( self::page_url( array( 'pe_err' => rawurlencode( $data->get_error_message() ) ) ) );
			exit;
		}
		set_transient( self::transient_key(), $data, HOUR_IN_SECONDS );
		wp_safe_redirect( self::page_url( array( 'step' => 'review' ) ) );
		exit;
	}

	/* ---------- Applying an import ---------- */

	public static function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have permission to import settings.' );
		}
		check_admin_referer( 'pe_settings_import', 'pe_nonce' );
		$data = get_transient( self::transient_key() );
		if ( ! is_array( $data ) ) {
			wp_safe_redirect( self::page_url( array( 'pe_err' => rawurlencode( 'The uploaded file expired. Upload it again.' ) ) ) );
			exit;
		}
		$targets  = isset( $_POST['target'] ) ? (array) wp_unslash( $_POST['target'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is checked below.
		$business = ! empty( $data['includes_business'] );
		$done     = array();

		foreach ( $data['estimators'] as $i => $e ) {
			$target = isset( $targets[ $i ] ) ? sanitize_key( $targets[ $i ] ) : 'new';
			if ( 'skip' === $target ) {
				continue;
			}
			$incoming = NSE_Config::sanitize( isset( $e['config'] ) ? $e['config'] : array() );
			if ( 'new' !== $target && 'nse_estimator' === get_post_type( absint( $target ) ) && current_user_can( 'edit_post', absint( $target ) ) ) {
				$id = absint( $target );
				if ( ! $business ) {
					/* Keep this site's client details. */
					$current = NSE_Config::get( $id );
					foreach ( self::BUSINESS_KEYS as $k ) {
						$incoming['business'][ $k ] = $current['business'][ $k ];
					}
				}
				update_post_meta( $id, NSE_Config::META_KEY, wp_slash( wp_json_encode( $incoming ) ) );
				$done[] = array( $id, 'replaced' );
				continue;
			}
			if ( ! $business && '' === $incoming['business']['name'] ) {
				$incoming['business']['name'] = get_bloginfo( 'name' );
			}
			$title = isset( $e['title'] ) ? sanitize_text_field( $e['title'] ) : 'Imported estimator';
			$id    = wp_insert_post(
				array(
					'post_type'   => 'nse_estimator',
					'post_status' => ( isset( $e['status'] ) && 'publish' === $e['status'] ) ? 'publish' : 'draft',
					'post_title'  => '' !== $title ? $title : 'Imported estimator',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( $id, NSE_Config::META_KEY, wp_slash( wp_json_encode( $incoming ) ) );
				$done[] = array( $id, 'created' );
			}
		}

		$settings_done = false;
		if ( $data['settings'] && ! empty( $_POST['apply_settings'] ) ) {
			$current  = NSE_Settings::get();
			$incoming = $data['settings'];
			if ( ! $business ) {
				foreach ( self::SETTINGS_CLIENT_KEYS as $k ) {
					$incoming[ $k ] = $current[ $k ];
				}
			}
			/*
			 * reCAPTCHA keys always stay with this site. Keys only work with their own type
			 * (v2 or v3), so a site that already has keys also keeps its mode.
			 */
			$incoming['recaptcha']               = isset( $incoming['recaptcha'] ) && is_array( $incoming['recaptcha'] ) ? $incoming['recaptcha'] : array();
			$incoming['recaptcha']['site_key']   = $current['recaptcha']['site_key'];
			$incoming['recaptcha']['secret_key'] = $current['recaptcha']['secret_key'];
			if ( $current['recaptcha']['site_key'] || $current['recaptcha']['secret_key'] ) {
				$incoming['recaptcha']['mode'] = $current['recaptcha']['mode'];
			}
			$incoming['nutshell']                = $current['nutshell']; // This site keeps its own CRM connection.
			$logo                                = self::import_logo( isset( $data['logo'] ) ? $data['logo'] : null );
			$incoming['logo_id']                 = $logo ? $logo : $current['logo_id'];
			update_option( NSE_Settings::OPTION, NSE_Settings::sanitize( $incoming ) );
			$settings_done = true;
		}

		delete_transient( self::transient_key() );
		set_transient( 'pe_import_result_' . get_current_user_id(), array( 'estimators' => $done, 'settings' => $settings_done ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url( array( 'step' => 'done' ) ) );
		exit;
	}

	/**
	 * Add an embedded logo to the media library (reusing it if already imported). Returns an attachment ID or 0.
	 */
	public static function import_logo( $logo ) {
		if ( ! is_array( $logo ) || empty( $logo['data'] ) || empty( $logo['filename'] ) ) {
			return 0;
		}
		$bin = base64_decode( (string) $logo['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! $bin || strlen( $bin ) > 3 * MB_IN_BYTES ) {
			return 0;
		}
		$info = @getimagesizefromstring( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ok   = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
		if ( ! $info || ! isset( $ok[ $info[2] ] ) ) {
			return 0;
		}
		$hash  = md5( $bin );
		$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_pe_logo_hash', 'meta_value' => $hash ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			return (int) $found[0];
		}
		$name   = sanitize_file_name( pathinfo( $logo['filename'], PATHINFO_FILENAME ) ) . '.' . $ok[ $info[2] ];
		$upload = wp_upload_bits( $name, null, $bin );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $info['mime'],
				'post_title'     => 'Estimator logo',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_pe_logo_hash', $hash );
		return (int) $id;
	}

	/* ---------- Duplicate ---------- */

	public static function row_actions( $actions, $post ) {
		if ( 'nse_estimator' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$actions['pe_duplicate'] = sprintf(
			'<a href="%s">Duplicate</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pe_duplicate&estimator=' . (int) $post->ID ), 'pe_duplicate_' . (int) $post->ID ) )
		);
		if ( current_user_can( self::CAP ) ) {
			$actions['pe_export'] = sprintf( '<a href="%s">Export</a>', esc_url( self::page_url( array( 'estimator' => (int) $post->ID ) ) ) );
		}
		return $actions;
	}

	public static function handle_duplicate() {
		$id = isset( $_GET['estimator'] ) ? absint( $_GET['estimator'] ) : 0;
		check_admin_referer( 'pe_duplicate_' . $id );
		if ( ! $id || 'nse_estimator' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'You do not have permission to duplicate this estimator.' );
		}
		$new = wp_insert_post(
			array(
				'post_type'   => 'nse_estimator',
				'post_status' => 'draft',
				'post_title'  => get_the_title( $id ) . ' (copy)',
			)
		);
		update_post_meta( $new, NSE_Config::META_KEY, wp_slash( wp_json_encode( NSE_Config::get( $id ) ) ) );
		wp_safe_redirect( add_query_arg( array( 'post' => $new, 'action' => 'edit', 'pe_duplicated' => 1 ), admin_url( 'post.php' ) ) );
		exit;
	}

	public static function duplicate_notice() {
		if ( empty( $_GET['pe_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>Estimator duplicated as a draft. Rename it if you like, then click Publish to use its shortcode.</p></div>';
	}

	/* ---------- Page ---------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : '';
		echo '<div class="wrap"><h1>Import / Export</h1>';
		if ( ! empty( $_GET['pe_err'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['pe_err'] ) ) ) );
		}
		if ( isset( $_GET['pe_msg'] ) && 'nothing' === $_GET['pe_msg'] ) {
			echo '<div class="notice notice-warning"><p>Pick at least one estimator or the site-wide settings to export.</p></div>';
		}
		if ( 'review' === $step ) {
			self::render_review();
			echo '</div>';
			return;
		}
		if ( 'done' === $step ) {
			self::render_done();
		}
		$pre = isset( $_GET['estimator'] ) ? absint( $_GET['estimator'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$ests = get_posts( array( 'post_type' => 'nse_estimator', 'numberposts' => -1, 'post_status' => array( 'publish', 'draft', 'private' ), 'orderby' => 'title', 'order' => 'ASC' ) );

		echo '<div class="pe-tx"><style>.pe-tx{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;margin-top:12px}.pe-tx .card{max-width:none;margin:0;padding:16px 20px}.pe-tx h2{margin-top:0}.pe-tx .pe-list{max-height:240px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;background:#fff;margin:8px 0 14px}.pe-tx label{display:block;margin:4px 0}.pe-tx p.description{margin:2px 0 10px 24px}</style>';

		/* Export */
		echo '<div class="card"><h2>Export</h2><p>Download estimators and settings as a file you can import on another site.</p>';
		printf( '<form method="post" action="%s"><input type="hidden" name="action" value="pe_settings_export">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'pe_settings_export', 'pe_nonce' );
		echo '<strong>Estimators</strong><div class="pe-list">';
		if ( ! $ests ) {
			echo '<p class="description" style="margin:0">No estimators yet.</p>';
		}
		foreach ( $ests as $e ) {
			printf(
				'<label><input type="checkbox" name="estimators[]" value="%d"%s> %s%s</label>',
				(int) $e->ID,
				checked( ! $pre || $pre === $e->ID, true, false ),
				esc_html( $e->post_title ),
				'publish' === $e->post_status ? '' : ' <span class="description">(' . esc_html( $e->post_status ) . ')</span>'
			);
		}
		echo '</div>';
		printf( '<label><input type="checkbox" name="include_settings" value="1"%s> <strong>Site-wide settings</strong></label><p class="description">Design defaults, PDF and customer email text, logo, and the spam protection mode. reCAPTCHA keys are never exported.</p>', checked( ! $pre, true, false ) );
		echo '<label><input type="checkbox" name="include_business" value="1"> <strong>Include business details</strong></label><p class="description">Business name, phone, lead emails, CC/BCC, reply-to, webhook URLs, and the PDF website. Leave unchecked when copying a setup to a different client.</p>';
		submit_button( 'Download export file', 'primary', 'submit', false );
		echo '</form></div>';

		/* Import */
		echo '<div class="card"><h2>Import</h2><p>Upload an export file. You will review what is inside before anything changes.</p>';
		printf( '<form method="post" enctype="multipart/form-data" action="%s"><input type="hidden" name="action" value="pe_settings_upload">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'pe_settings_upload', 'pe_nonce' );
		echo '<p><input type="file" name="pe_file" accept=".json,application/json" required></p>';
		submit_button( 'Upload and review', 'secondary', 'submit', false );
		echo '</form></div></div></div>';
	}

	private static function render_review() {
		$data = get_transient( self::transient_key() );
		if ( ! is_array( $data ) ) {
			echo '<div class="notice notice-error"><p>The uploaded file expired. Upload it again.</p></div>';
			printf( '<p><a class="button" href="%s">Back</a></p>', esc_url( self::page_url() ) );
			return;
		}
		$ests = get_posts( array( 'post_type' => 'nse_estimator', 'numberposts' => -1, 'post_status' => array( 'publish', 'draft', 'private' ), 'orderby' => 'title', 'order' => 'ASC' ) );
		printf(
			'<p>File from <strong>%s</strong>, exported %s with version %s. %s</p>',
			esc_html( wp_parse_url( isset( $data['site'] ) ? $data['site'] : '', PHP_URL_HOST ) ),
			esc_html( isset( $data['exported_at'] ) ? wp_date( 'M j, Y g:i a', strtotime( $data['exported_at'] ) ) : '' ),
			esc_html( isset( $data['plugin_version'] ) ? $data['plugin_version'] : '?' ),
			! empty( $data['includes_business'] ) ? '<strong>It includes business details</strong> (name, phone, lead emails, webhook URLs).' : 'Business details are not included, so this site keeps its own.'
		);
		printf( '<form method="post" action="%s"><input type="hidden" name="action" value="pe_settings_import">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'pe_settings_import', 'pe_nonce' );
		if ( $data['estimators'] ) {
			echo '<h2>Estimators</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>In the file</th><th>Project types</th><th>Import as</th></tr></thead><tbody>';
			foreach ( $data['estimators'] as $i => $e ) {
				$c     = isset( $e['config'] ) && is_array( $e['config'] ) ? NSE_Config::sanitize( $e['config'] ) : NSE_Config::defaults();
				$names = implode( ', ', wp_list_pluck( $c['projects'], 'name' ) );
				$title = isset( $e['title'] ) ? $e['title'] : 'Untitled';
				printf( '<tr><td><strong>%s</strong></td><td>%s</td><td><select name="target[%d]"><option value="new">Create a new estimator</option>', esc_html( $title ), esc_html( $names ), (int) $i );
				foreach ( $ests as $x ) {
					printf( '<option value="%d">Replace "%s"</option>', (int) $x->ID, esc_html( $x->post_title ) );
				}
				echo '<option value="skip">Skip</option></select></td></tr>';
			}
			echo '</tbody></table><p class="description">Replacing keeps that estimator\'s title and shortcode, so pages using it keep working.</p>';
		}
		if ( $data['settings'] ) {
			echo '<h2>Site-wide settings</h2><label><input type="checkbox" name="apply_settings" value="1" checked> Replace this site\'s settings with the ones in the file</label>';
			echo '<p class="description" style="margin-left:24px">Design defaults, PDF and customer email text, and spam protection options' . ( ! empty( $data['logo'] ) ? ', plus the logo (added to the media library)' : '' ) . '. This site\'s reCAPTCHA keys and mode are kept' . ( empty( $data['includes_business'] ) ? ', and so are its lead emails, reply-to, alerts address, and PDF website' : '' ) . '.</p>';
		}
		echo '<p>';
		submit_button( 'Import', 'primary', 'submit', false );
		printf( ' <a class="button" href="%s">Cancel</a></p></form>', esc_url( self::page_url() ) );
	}

	private static function render_done() {
		$key = 'pe_import_result_' . get_current_user_id();
		$r   = get_transient( $key );
		if ( ! is_array( $r ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-success"><p><strong>Import complete.</strong></p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $r['estimators'] as $row ) {
			printf(
				'<li>%s <a href="%s">%s</a>: shortcode <code>[project_estimator id="%d"]</code>%s</li>',
				'created' === $row[1] ? 'Created' : 'Updated',
				esc_url( get_edit_post_link( $row[0] ) ),
				esc_html( get_the_title( $row[0] ) ),
				(int) $row[0],
				'draft' === get_post_status( $row[0] ) ? ' (draft, publish it to use the shortcode)' : ''
			);
		}
		if ( $r['settings'] ) {
			echo '<li>Site-wide settings updated. Check the reCAPTCHA keys under Settings if spam protection is turned on.</li>';
		}
		if ( ! $r['estimators'] && ! $r['settings'] ) {
			echo '<li>Nothing was selected, so nothing changed.</li>';
		}
		echo '</ul></div>';
	}
}

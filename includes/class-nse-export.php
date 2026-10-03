<?php
/**
 * CSV export (Estimators > Export): full lead details, or Google Ads offline conversions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Export {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_pe_export', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=nse_estimator', 'Export leads', 'Export leads', self::CAP, 'pe-export', array( __CLASS__, 'render' ) );
	}

	/* ---------- Page ---------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$estimators = get_posts( array( 'post_type' => 'nse_estimator', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) );
		$counts     = self::counts();
		$notice     = isset( $_GET['pe_empty'] ) ? '<div class="notice notice-warning"><p>No leads matched those filters, so there was nothing to export.</p></div>' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1>Export leads</h1>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
			<p><?php echo esc_html( sprintf( '%d leads total: %d new, %d contacted, %d quoted, %d booked, %d lost.', array_sum( $counts ), $counts['new'], $counts['contacted'], $counts['quoted'], $counts['booked'], $counts['lost'] ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pe_export">
				<?php wp_nonce_field( 'pe_export', 'pe_export_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Format</th>
						<td><fieldset>
							<label><input type="radio" name="format" value="full" checked> <strong>All lead details</strong></label>
							<p class="description" style="margin:2px 0 10px 24px">Contact info, project selections, estimate, status, source, campaign, keyword, click IDs, and PDF link. Opens in Excel or Google Sheets.</p>
							<label><input type="radio" name="format" value="google_ads"> <strong>Google Ads offline conversions</strong></label>
							<p class="description" style="margin:2px 0 0 24px">Booked leads that came from a Google ad click (have a GCLID), in Google's upload format. Upload under Goals &gt; Conversions &gt; Uploads in Google Ads.</p>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><label for="pe-from">Submitted between</label></th>
						<td><input type="date" id="pe-from" name="from"> and <input type="date" id="pe-to" name="to" aria-label="End date"><p class="description">Leave blank for all dates.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pe-est">Estimator</label></th>
						<td><select id="pe-est" name="estimator"><option value="">All estimators</option>
							<?php foreach ( $estimators as $e ) : ?>
								<option value="<?php echo (int) $e->ID; ?>"><?php echo esc_html( $e->post_title ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr class="pe-full-only">
						<th scope="row">Status</th>
						<td><fieldset>
							<?php foreach ( NSE_Status::statuses() as $key => $label ) : ?>
								<label style="margin-right:14px"><input type="checkbox" name="status[]" value="<?php echo esc_attr( $key ); ?>" checked> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset></td>
					</tr>
					<tr class="pe-ads-only">
						<th scope="row"><label for="pe-conv">Conversion name</label></th>
						<td><input type="text" class="regular-text" id="pe-conv" name="conversion_name" value="Booked job"><p class="description">Must match the name of an "Import" conversion action in Google Ads exactly.</p></td>
					</tr>
					<tr class="pe-ads-only">
						<th scope="row"><label for="pe-cur">Currency</label></th>
						<td><input type="text" id="pe-cur" name="currency" value="USD" maxlength="3" style="width:70px;text-transform:uppercase"><p class="description">Value is the lead's booked job value, or the middle of its estimate when none is set.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Download CSV' ); ?>
			</form>
		</div>
		<script>
		(function () {
			var rows = function (cls, on) { document.querySelectorAll('.' + cls).forEach(function (r) { r.style.display = on ? '' : 'none'; }); };
			function sync() {
				var ads = document.querySelector('input[name=format][value=google_ads]').checked;
				rows('pe-ads-only', ads); rows('pe-full-only', !ads);
			}
			document.querySelectorAll('input[name=format]').forEach(function (r) { r.addEventListener('change', sync); });
			sync();
		})();
		</script>
		<?php
	}

	public static function counts() {
		$out = array();
		foreach ( array_keys( NSE_Status::statuses() ) as $s ) {
			$q         = new WP_Query(
				array(
					'post_type'      => 'nse_lead',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => NSE_Status::meta_query( $s ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			$out[ $s ] = (int) $q->found_posts;
		}
		return $out;
	}

	/* ---------- Request handling ---------- */

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have permission to export leads.' );
		}
		check_admin_referer( 'pe_export', 'pe_export_nonce' );
		$format = ( isset( $_POST['format'] ) && 'google_ads' === $_POST['format'] ) ? 'google_ads' : 'full';
		$args   = array(
			'post_type'      => 'nse_lead',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		$date = array( 'inclusive' => true );
		foreach ( array( 'from' => 'after', 'to' => 'before' ) as $field => $key ) {
			$v = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$date[ $key ] = $v . ( 'before' === $key ? ' 23:59:59' : ' 00:00:00' );
			}
		}
		if ( count( $date ) > 1 ) {
			$args['date_query'] = array( $date );
		}

		$meta = array( 'relation' => 'AND' );
		if ( ! empty( $_POST['estimator'] ) ) {
			$meta[] = array( 'key' => '_nse_estimator_id', 'value' => absint( $_POST['estimator'] ) );
		}
		if ( 'google_ads' === $format ) {
			$meta[] = NSE_Status::meta_query( 'booked' );
			$meta[] = array( 'key' => '_pe_gclid', 'compare' => 'EXISTS' );
		} else {
			$picked = isset( $_POST['status'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['status'] ) ) : array();
			$picked = array_values( array_intersect( $picked, array_keys( NSE_Status::statuses() ) ) );
			if ( ! $picked ) {
				$picked = array( '__none__' );
			}
			if ( count( $picked ) < count( NSE_Status::statuses() ) ) {
				$any = array( 'relation' => 'OR' );
				foreach ( $picked as $s ) {
					$any[] = '__none__' === $s ? array( 'key' => NSE_Status::META, 'value' => '__none__' ) : NSE_Status::meta_query( $s );
				}
				$meta[] = $any;
			}
		}
		if ( count( $meta ) > 1 ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$ids = get_posts( $args );
		if ( ! $ids ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=nse_estimator&page=pe-export&pe_empty=1' ) );
			exit;
		}
		$opts = array(
			'conversion_name' => isset( $_POST['conversion_name'] ) && '' !== trim( wp_unslash( $_POST['conversion_name'] ) ) ? sanitize_text_field( wp_unslash( $_POST['conversion_name'] ) ) : 'Booked job', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'currency'        => isset( $_POST['currency'] ) && preg_match( '/^[A-Za-z]{3}$/', wp_unslash( $_POST['currency'] ) ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : 'USD', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		self::stream( $ids, $format, $opts );
	}

	/* ---------- CSV ---------- */

	/**
	 * Neutralize spreadsheet formulas in text cells (CSV injection).
	 */
	public static function cell( $v ) {
		if ( is_array( $v ) ) {
			$v = implode( ', ', $v );
		}
		$v = (string) $v;
		$v = str_replace( array( "\r\n", "\r" ), "\n", $v );
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t" ), true ) ) {
			$v = "'" . $v;
		}
		return $v;
	}

	/**
	 * Google Ads time zone parameter: a region name, or an offset like -0700.
	 */
	public static function timezone_param() {
		$tz = wp_timezone_string();
		if ( preg_match( '/^([+-])(\d{2}):(\d{2})$/', $tz, $m ) ) {
			return $m[1] . $m[2] . $m[3];
		}
		return $tz;
	}

	public static function rows_full( array $ids ) {
		$head = array(
			'Lead ID', 'Submitted', 'Status', 'Booked value', 'Name', 'Phone', 'Email', 'ZIP', 'Address', 'Timeline', 'Notes',
			'Estimator', 'Project', 'Style', 'Color', 'Size', 'Extras', 'Estimate low', 'Estimate high',
			'Source', 'Campaign', 'Keyword', 'UTM source', 'UTM medium', 'UTM content', 'Campaign ID', 'Ad group ID', 'Match type', 'Device',
			'GCLID', 'GBRAID', 'WBRAID', 'MSCLKID', 'FBCLID', 'Landing page', 'Referrer', 'Submitted from', 'PDF', 'reCAPTCHA score',
		);
		$rows = array( $head );
		foreach ( $ids as $id ) {
			$l = get_post_meta( $id, '_nse_lead', true );
			if ( ! is_array( $l ) ) {
				continue;
			}
			$a = isset( $l['attribution'] ) && is_array( $l['attribution'] ) ? $l['attribution'] : array();
			$g = function ( $k ) use ( $l ) {
				return isset( $l[ $k ] ) ? $l[ $k ] : '';
			};
			$x = function ( $k ) use ( $a ) {
				return isset( $a[ $k ] ) ? $a[ $k ] : '';
			};
			$status = NSE_Status::get( $id );
			$value  = NSE_Status::booked_value( $id );
			$rows[] = array(
				$id,
				get_post_time( 'Y-m-d H:i', false, $id ),
				NSE_Status::statuses()[ $status ],
				null === $value ? '' : $value,
				self::cell( $g( 'name' ) ),
				self::cell( $g( 'phone' ) ),
				self::cell( $g( 'email' ) ),
				self::cell( $g( 'zip' ) ),
				self::cell( $g( 'address' ) ),
				self::cell( $g( 'timeline' ) ),
				self::cell( $g( 'notes' ) ),
				self::cell( $g( 'estimator' ) ),
				self::cell( $g( 'project' ) ),
				self::cell( $g( 'option' ) ),
				self::cell( $g( 'color' ) ),
				self::cell( $g( 'size' ) ? $g( 'size' ) : trim( $g( 'measurements' ) . ' ' . ( $g( 'quantity' ) ? '(' . $g( 'quantity' ) . ')' : '' ) ) ),
				self::cell( $g( 'extras' ) ),
				$g( 'estimate_low' ),
				$g( 'estimate_high' ),
				self::cell( $g( 'source' ) ),
				self::cell( $x( 'utm_campaign' ) ),
				self::cell( $x( 'utm_term' ) ? $x( 'utm_term' ) : $x( 'keyword' ) ),
				self::cell( $x( 'utm_source' ) ),
				self::cell( $x( 'utm_medium' ) ),
				self::cell( $x( 'utm_content' ) ),
				self::cell( $x( 'campaignid' ) ),
				self::cell( $x( 'adgroupid' ) ),
				self::cell( $x( 'matchtype' ) ),
				self::cell( $x( 'device' ) ),
				self::cell( $x( 'gclid' ) ),
				self::cell( $x( 'gbraid' ) ),
				self::cell( $x( 'wbraid' ) ),
				self::cell( $x( 'msclkid' ) ),
				self::cell( $x( 'fbclid' ) ),
				self::cell( $x( 'landing_page' ) ),
				self::cell( $x( 'referrer' ) ),
				self::cell( $g( 'page' ) ),
				NSE_Pdf::path_for( $id ) ? NSE_Pdf::url( $id ) : '',
				isset( $l['recaptcha_score'] ) ? $l['recaptcha_score'] : '',
			);
		}
		return $rows;
	}

	public static function rows_google_ads( array $ids, array $opts ) {
		$rows = array(
			array( 'Parameters:TimeZone=' . self::timezone_param() ),
			array( 'Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency' ),
		);
		$tz = wp_timezone();
		foreach ( $ids as $id ) {
			$l     = get_post_meta( $id, '_nse_lead', true );
			$gclid = get_post_meta( $id, '_pe_gclid', true );
			if ( ! is_array( $l ) || ! $gclid || 'booked' !== NSE_Status::get( $id ) ) {
				continue;
			}
			/* Conversion time: when the lead was marked booked (never before the click), else when it was submitted. */
			$ts    = NSE_Status::reached( $id, 'booked' );
			$ts    = $ts ? $ts : (int) get_post_time( 'U', true, $id );
			$value = NSE_Status::booked_value( $id );
			if ( null === $value ) {
				$value = round( ( (float) $l['estimate_low'] + (float) $l['estimate_high'] ) / 2 );
			}
			$rows[] = array(
				$gclid,
				self::cell( $opts['conversion_name'] ),
				wp_date( 'Y-m-d H:i:s', $ts, $tz ),
				$value,
				$opts['currency'],
			);
		}
		return $rows;
	}

	/**
	 * Send a CSV download and stop.
	 */
	public static function stream( array $ids, $format, array $opts ) {
		$opts = wp_parse_args( $opts, array( 'conversion_name' => 'Booked job', 'currency' => 'USD' ) );
		$rows = 'google_ads' === $format ? self::rows_google_ads( $ids, $opts ) : self::rows_full( $ids );
		$name = ( 'google_ads' === $format ? 'google-ads-conversions-' : 'leads-' ) . wp_date( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( 'full' === $format ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel reads accents correctly.
		}
		foreach ( $rows as $r ) {
			fputcsv( $out, $r, ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

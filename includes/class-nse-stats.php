<?php
/**
 * Funnel stats: daily view and start counters per estimator, plus aggregation
 * of leads (requests, bookings, values, sources, choices) for the dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Stats {

	const DB_VERSION = '1';

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pe_stats';
	}

	/**
	 * Create the counters table. Runs on updates too, since plugin updates skip activation hooks.
	 */
	public static function maybe_install() {
		if ( get_option( 'pe_stats_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
			day date NOT NULL,
			estimator_id bigint(20) unsigned NOT NULL,
			views int(10) unsigned NOT NULL DEFAULT 0,
			starts int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,estimator_id)
			) $charset;"
		);
		update_option( 'pe_stats_db', self::DB_VERSION, false );
		if ( ! get_option( 'pe_stats_since' ) ) {
			update_option( 'pe_stats_since', wp_date( 'Y-m-d' ), false );
		}
	}

	/* ---------- Tracking ---------- */

	public static function routes() {
		register_rest_route(
			'nse/v1',
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function track( WP_REST_Request $req ) {
		$p     = $req->get_json_params();
		$p     = is_array( $p ) ? $p : array();
		$id    = isset( $p['estimator_id'] ) ? absint( $p['estimator_id'] ) : 0;
		$event = isset( $p['event'] ) ? sanitize_key( $p['event'] ) : '';
		if ( ! in_array( $event, array( 'view', 'start' ), true ) || 'nse_estimator' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		/* Light abuse limit per visitor. */
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'pe_trk_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 60 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		self::bump( $id, $event );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function bump( $estimator_id, $event, $day = '' ) {
		global $wpdb;
		self::maybe_install();
		$day = $day ? $day : wp_date( 'Y-m-d' );
		$v   = 'view' === $event ? 1 : 0;
		$s   = 'start' === $event ? 1 : 0;
		$t   = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "INSERT INTO $t (day, estimator_id, views, starts) VALUES (%s, %d, %d, %d) ON DUPLICATE KEY UPDATE views = views + %d, starts = starts + %d", $day, $estimator_id, $v, $s, $v, $s ) );
	}

	/* ---------- Date ranges ---------- */

	public static function presets() {
		return array(
			'7'          => 'Last 7 days',
			'30'         => 'Last 30 days',
			'90'         => 'Last 90 days',
			'month'      => 'This month',
			'last_month' => 'Last month',
			'year'       => 'This year',
			'custom'     => 'Custom',
		);
	}

	/**
	 * [from, to] as Y-m-d in the site time zone.
	 */
	public static function range( $preset, $from = '', $to = '' ) {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		switch ( $preset ) {
			case '7':
			case '30':
			case '90':
				return array( $today->modify( '-' . ( (int) $preset - 1 ) . ' days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
			case 'month':
				return array( $today->modify( 'first day of this month' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
			case 'last_month':
				return array( $today->modify( 'first day of last month' )->format( 'Y-m-d' ), $today->modify( 'last day of last month' )->format( 'Y-m-d' ) );
			case 'year':
				return array( $today->format( 'Y' ) . '-01-01', $today->format( 'Y-m-d' ) );
		}
		$ok = function ( $d ) {
			return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		};
		$f  = $ok( $from ) ? $from : $today->modify( '-29 days' )->format( 'Y-m-d' );
		$t  = $ok( $to ) ? $to : $today->format( 'Y-m-d' );
		return $f <= $t ? array( $f, $t ) : array( $t, $f );
	}

	/**
	 * The same number of days immediately before a range.
	 */
	public static function previous( $from, $to, $preset = '' ) {
		$tz   = wp_timezone();
		$f    = new DateTimeImmutable( $from, $tz );
		$t    = new DateTimeImmutable( $to, $tz );
		/* Calendar presets compare with the matching calendar period. */
		if ( 'last_month' === $preset ) {
			$pm = $f->modify( 'first day of last month' );
			return array( $pm->format( 'Y-m-d' ), $pm->modify( 'last day of this month' )->format( 'Y-m-d' ) );
		}
		if ( 'month' === $preset ) {
			$pm  = $f->modify( 'first day of last month' );
			$end = min( (int) $t->format( 'j' ), (int) $pm->format( 't' ) );
			return array( $pm->format( 'Y-m-d' ), $pm->format( 'Y-m-' ) . sprintf( '%02d', $end ) );
		}
		if ( 'year' === $preset ) {
			return array( $f->modify( '-1 year' )->format( 'Y-m-d' ), $t->modify( '-1 year' )->format( 'Y-m-d' ) );
		}
		$days = (int) $f->diff( $t )->days + 1;
		return array( $f->modify( '-' . $days . ' days' )->format( 'Y-m-d' ), $f->modify( '-1 day' )->format( 'Y-m-d' ) );
	}

	/* ---------- Aggregation ---------- */

	private static function source_group( $label ) {
		$label = (string) $label;
		if ( '' === $label ) {
			return 'Unknown';
		}
		if ( 0 === strpos( $label, 'Organic search' ) ) {
			return 'Organic search';
		}
		$cut = strpos( $label, ':' );
		return false === $cut ? $label : trim( substr( $label, 0, $cut ) );
	}

	/**
	 * Everything the dashboard needs for one date range.
	 */
	public static function collect( $from, $to, $estimator_id = 0 ) {
		global $wpdb;
		self::maybe_install();
		$out = array(
			'from'      => $from,
			'to'        => $to,
			'views'     => 0,
			'starts'    => 0,
			'requests'  => 0,
			'booked'    => 0,
			'pipeline'  => 0,
			'booked_value' => 0,
			'avg'       => 0,
			'daily'     => array(),
			'sources'   => array(),
			'campaigns' => array(),
			'choices'   => array( 'Project types' => array(), 'Styles' => array(), 'Colors' => array(), 'Extras' => array() ),
		);

		/* Days in range, so the chart has no gaps. */
		$tz  = wp_timezone();
		$day = new DateTimeImmutable( $from, $tz );
		$end = new DateTimeImmutable( $to, $tz );
		while ( $day <= $end ) {
			$out['daily'][ $day->format( 'Y-m-d' ) ] = array( 'views' => 0, 'starts' => 0, 'requests' => 0 );
			$day = $day->modify( '+1 day' );
		}

		/* Views and starts. */
		$t   = self::table();
		$sql = "SELECT day, SUM(views) AS v, SUM(starts) AS s FROM $t WHERE day BETWEEN %s AND %s" . ( $estimator_id ? ' AND estimator_id = %d' : '' ) . ' GROUP BY day';
		$args = $estimator_id ? array( $from, $to, $estimator_id ) : array( $from, $to );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name is internal; values are prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		foreach ( $rows as $r ) {
			$d = substr( (string) $r->day, 0, 10 );
			if ( isset( $out['daily'][ $d ] ) ) {
				$out['daily'][ $d ]['views']  = (int) $r->v;
				$out['daily'][ $d ]['starts'] = (int) $r->s;
			}
			$out['views']  += (int) $r->v;
			$out['starts'] += (int) $r->s;
		}

		/* Leads. */
		$q = array(
			'post_type'      => 'nse_lead',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => array( array( 'after' => $from . ' 00:00:00', 'before' => $to . ' 23:59:59', 'inclusive' => true ) ),
		);
		if ( $estimator_id ) {
			$q['meta_query'] = array( array( 'key' => '_nse_estimator_id', 'value' => $estimator_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$ids = get_posts( $q );
		$sum = 0;
		$add = function ( &$bucket, $key ) {
			if ( '' === (string) $key ) {
				return;
			}
			$bucket[ $key ] = isset( $bucket[ $key ] ) ? $bucket[ $key ] + 1 : 1;
		};
		foreach ( $ids as $id ) {
			$l = get_post_meta( $id, '_nse_lead', true );
			if ( ! is_array( $l ) ) {
				continue;
			}
			$out['requests']++;
			$mid  = ( (float) ( isset( $l['estimate_low'] ) ? $l['estimate_low'] : 0 ) + (float) ( isset( $l['estimate_high'] ) ? $l['estimate_high'] : 0 ) ) / 2;
			$sum += $mid;
			$d    = get_post_time( 'Y-m-d', false, $id );
			if ( isset( $out['daily'][ $d ] ) ) {
				$out['daily'][ $d ]['requests']++;
			}
			$booked = 'booked' === NSE_Status::get( $id );
			if ( $booked ) {
				$out['booked']++;
				$v                    = NSE_Status::booked_value( $id );
				$out['booked_value'] += null === $v ? 0 : $v;
			}
			$src = self::source_group( isset( $l['source'] ) ? $l['source'] : '' );
			if ( ! isset( $out['sources'][ $src ] ) ) {
				$out['sources'][ $src ] = array( 'requests' => 0, 'booked' => 0 );
			}
			$out['sources'][ $src ]['requests']++;
			$out['sources'][ $src ]['booked'] += $booked ? 1 : 0;
			if ( ! empty( $l['attribution']['utm_campaign'] ) ) {
				$add( $out['campaigns'], $l['attribution']['utm_campaign'] );
			}
			$add( $out['choices']['Project types'], isset( $l['project'] ) ? $l['project'] : '' );
			$add( $out['choices']['Styles'], isset( $l['option'] ) ? $l['option'] : '' );
			$add( $out['choices']['Colors'], isset( $l['color'] ) ? $l['color'] : '' );
			foreach ( (array) ( isset( $l['extras'] ) ? $l['extras'] : array() ) as $x ) {
				$add( $out['choices']['Extras'], $x );
			}
		}
		$out['pipeline'] = round( $sum );
		$out['avg']      = $out['requests'] ? round( $sum / $out['requests'] ) : 0;
		uasort(
			$out['sources'],
			function ( $a, $b ) {
				return $b['requests'] - $a['requests'];
			}
		);
		arsort( $out['campaigns'] );
		foreach ( $out['choices'] as $k => $v ) {
			arsort( $v );
			$out['choices'][ $k ] = $v;
		}
		return $out;
	}
}

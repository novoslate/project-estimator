<?php
/**
 * Estimators > Dashboard: funnel, trends, sources, popular choices, and a copyable summary.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Dashboard {

	const CAP = 'edit_posts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'widget' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=nse_estimator', 'Estimator dashboard', 'Dashboard', self::CAP, 'pe-dashboard', array( __CLASS__, 'render' ), 0 );
	}

	/* ---------- Formatting ---------- */

	private static function n( $v ) {
		return number_format_i18n( (float) $v );
	}

	private static function money( $v ) {
		return '$' . number_format_i18n( round( (float) $v ) );
	}

	private static function pct( $part, $whole, $dec = 1 ) {
		return $whole > 0 ? number_format_i18n( 100 * $part / $whole, $dec ) . '%' : '';
	}

	/**
	 * "+12% vs previous period" style change.
	 */
	private static function delta( $now, $before, $is_rate = false ) {
		if ( $is_rate ) {
			if ( null === $now || null === $before ) {
				return '';
			}
			$d = round( ( $now - $before ) * 100, 1 );
			if ( 0.0 === (float) $d ) {
				return '<span class="pe-d pe-d--flat">No change</span>';
			}
			return sprintf( '<span class="pe-d pe-d--%s">%s%s pts</span>', $d > 0 ? 'up' : 'down', $d > 0 ? '+' : '', esc_html( number_format_i18n( $d, 1 ) ) );
		}
		if ( $before <= 0 ) {
			return $now > 0 ? '<span class="pe-d pe-d--up">New</span>' : '';
		}
		$d = round( 100 * ( $now - $before ) / $before );
		if ( 0.0 === (float) $d ) {
			return '<span class="pe-d pe-d--flat">No change</span>';
		}
		return sprintf( '<span class="pe-d pe-d--%s">%s%s%%</span>', $d > 0 ? 'up' : 'down', $d > 0 ? '+' : '', esc_html( number_format_i18n( $d ) ) );
	}

	private static function rate( $part, $whole ) {
		return $whole > 0 ? $part / $whole : null;
	}

	private static function label_range( $from, $to ) {
		$tz = wp_timezone();
		$f  = new DateTimeImmutable( $from, $tz );
		$t  = new DateTimeImmutable( $to, $tz );
		$fy = $f->format( 'Y' ) === $t->format( 'Y' ) ? 'M j' : 'M j, Y';
		return $f->format( $fy ) . ' to ' . $t->format( 'M j, Y' );
	}

	/* ---------- Chart ---------- */

	/**
	 * Inline SVG: quote requests as bars, views as a line on their own scale.
	 */
	private static function chart( array $daily ) {
		$W     = 900;
		$H     = 240;
		$pl    = 40;
		$pr    = 40;
		$pt    = 16;
		$pb    = 28;
		$n     = max( 1, count( $daily ) );
		$cw    = ( $W - $pl - $pr ) / $n;
		$maxr  = max( 1, max( array_column( $daily, 'requests' ) ?: array( 0 ) ) );
		$maxv  = max( 1, max( array_column( $daily, 'views' ) ?: array( 0 ) ) );
		$ih    = $H - $pt - $pb;
		$svg   = '<svg class="pe-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Daily views and quote requests">';
		/* Grid and axes labels */
		for ( $g = 0; $g <= 4; $g++ ) {
			$y    = $pt + $ih * $g / 4;
			$svg .= '<line x1="' . $pl . '" x2="' . ( $W - $pr ) . '" y1="' . $y . '" y2="' . $y . '" class="pe-grid"/>';
			$svg .= '<text x="' . ( $pl - 6 ) . '" y="' . ( $y + 4 ) . '" class="pe-ax pe-ax--r">' . esc_html( (string) round( $maxr * ( 4 - $g ) / 4, $maxr < 4 ? 1 : 0 ) ) . '</text>';
			$svg .= '<text x="' . ( $W - $pr + 6 ) . '" y="' . ( $y + 4 ) . '" class="pe-ax">' . esc_html( (string) round( $maxv * ( 4 - $g ) / 4 ) ) . '</text>';
		}
		$i    = 0;
		$line = array();
		$step = max( 1, (int) ceil( $n / 8 ) );
		foreach ( $daily as $day => $d ) {
			$x  = $pl + $cw * $i;
			$bh = $ih * $d['requests'] / $maxr;
			if ( $d['requests'] ) {
				$svg .= sprintf( '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" class="pe-bar"><title>%s: %d requests, %d views</title></rect>', $x + $cw * 0.18, $pt + $ih - $bh, max( 1, $cw * 0.64 ), $bh, esc_html( $day ), $d['requests'], $d['views'] );
			}
			$line[] = sprintf( '%.1f,%.1f', $x + $cw / 2, $pt + $ih - $ih * $d['views'] / $maxv );
			if ( 0 === $i % $step ) {
				$svg .= '<text x="' . round( $x + $cw / 2, 1 ) . '" y="' . ( $H - 8 ) . '" class="pe-ax pe-ax--c">' . esc_html( wp_date( 'M j', strtotime( $day . ' 12:00:00' ) ) ) . '</text>';
			}
			$i++;
		}
		$svg .= '<polyline points="' . implode( ' ', $line ) . '" class="pe-line"/>';
		$svg .= '</svg>';
		return $svg;
	}

	/* ---------- Page ---------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$preset = isset( $_GET['range'] ) && isset( NSE_Stats::presets()[ $_GET['range'] ] ) ? sanitize_key( $_GET['range'] ) : '30';
		$est    = isset( $_GET['estimator'] ) ? absint( $_GET['estimator'] ) : 0;
		list( $from, $to ) = NSE_Stats::range( $preset, isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '', isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		list( $pf, $pt ) = NSE_Stats::previous( $from, $to, $preset );
		$s     = NSE_Stats::collect( $from, $to, $est );
		$p     = NSE_Stats::collect( $pf, $pt, $est );
		$since = get_option( 'pe_stats_since' );
		$ests  = get_posts( array( 'post_type' => 'nse_estimator', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) );
		$name  = $est ? get_the_title( $est ) : 'All estimators';

		echo '<div class="wrap pe-dash"><h1>Estimator dashboard</h1>';
		self::styles();

		/* Filters */
		echo '<form method="get" class="pe-filters"><input type="hidden" name="post_type" value="nse_estimator"><input type="hidden" name="page" value="pe-dashboard">';
		echo '<select name="range" id="pe-range">';
		foreach ( NSE_Stats::presets() as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $preset, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		printf( ' <span class="pe-custom"%s><input type="date" name="from" value="%s"> to <input type="date" name="to" value="%s"></span>', 'custom' === $preset ? '' : ' style="display:none"', esc_attr( $from ), esc_attr( $to ) );
		echo ' <select name="estimator"><option value="0">All estimators</option>';
		foreach ( $ests as $e ) {
			printf( '<option value="%d"%s>%s</option>', (int) $e->ID, selected( $est, $e->ID, false ), esc_html( $e->post_title ) );
		}
		echo '</select> <button class="button">Apply</button>';
		printf( ' <span class="pe-range-label">%s</span></form>', esc_html( self::label_range( $from, $to ) . ' vs ' . self::label_range( $pf, $pt ) ) );
		echo '<script>document.getElementById("pe-range").addEventListener("change",function(){document.querySelector(".pe-custom").style.display=this.value==="custom"?"":"none";});</script>';

		if ( $since && $from < $since ) {
			printf( '<p class="pe-note">View and start tracking began on %s. Quote requests, bookings, and choices include all history.</p>', esc_html( wp_date( 'F j, Y', strtotime( $since . ' 12:00:00' ) ) ) );
		}

		/* KPI cards */
		$conv  = self::rate( $s['requests'], $s['views'] );
		$pconv = self::rate( $p['requests'], $p['views'] );
		$close = self::rate( $s['booked'], $s['requests'] );
		$cards = array(
			array( 'Views', self::n( $s['views'] ), self::delta( $s['views'], $p['views'] ), 'Visitors who saw an estimator' ),
			array( 'Started', self::n( $s['starts'] ), self::delta( $s['starts'], $p['starts'] ), $s['views'] ? self::pct( $s['starts'], $s['views'], 0 ) . ' of views' : 'Picked a style or size' ),
			array( 'Quote requests', self::n( $s['requests'] ), self::delta( $s['requests'], $p['requests'] ), $s['views'] ? self::pct( $s['requests'], $s['views'] ) . ' of views' : '' ),
			array( 'Conversion rate', null === $conv ? 'n/a' : esc_html( self::pct( $s['requests'], $s['views'] ) ), self::delta( $conv, $pconv, true ), 'Views to quote requests' ),
			array( 'Booked', self::n( $s['booked'] ), self::delta( $s['booked'], $p['booked'] ), null === $close ? '' : self::pct( $s['booked'], $s['requests'], 0 ) . ' close rate' ),
			array( 'Pipeline value', self::money( $s['pipeline'] ), self::delta( $s['pipeline'], $p['pipeline'] ), 'Sum of estimate midpoints' ),
			array( 'Booked value', self::money( $s['booked_value'] ), self::delta( $s['booked_value'], $p['booked_value'] ), 'From booked job values' ),
			array( 'Average estimate', $s['avg'] ? self::money( $s['avg'] ) : 'n/a', self::delta( $s['avg'], $p['avg'] ), 'Midpoint per request' ),
		);
		echo '<div class="pe-cards">';
		foreach ( $cards as $c ) {
			printf(
				'<div class="pe-card"><div class="pe-card-l">%s</div><div class="pe-card-v">%s</div><div class="pe-card-m">%s <span>%s</span></div></div>',
				esc_html( $c[0] ),
				$c[1], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers formatted above.
				$c[2], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped values.
				esc_html( $c[3] )
			);
		}
		echo '</div>';

		/* Chart */
		echo '<div class="pe-panel"><h2>Views and quote requests</h2><div class="pe-legend"><span class="pe-key pe-key--bar"></span> Quote requests (left) <span class="pe-key pe-key--line"></span> Views (right)</div>';
		echo self::chart( $s['daily'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped labels.
		echo '</div>';

		echo '<div class="pe-grid2">';

		/* Funnel */
		$steps = array(
			array( 'Viewed', $s['views'] ),
			array( 'Started', $s['starts'] ),
			array( 'Requested a quote', $s['requests'] ),
			array( 'Booked', $s['booked'] ),
		);
		$top   = max( 1, $s['views'], $s['requests'] );
		echo '<div class="pe-panel"><h2>Funnel</h2>';
		foreach ( $steps as $k => $st ) {
			$prev = $k ? $steps[ $k - 1 ][1] : 0;
			printf(
				'<div class="pe-fn"><div class="pe-fn-l">%s <strong>%s</strong>%s</div><div class="pe-fn-t"><div class="pe-fn-b" style="width:%s%%"></div></div></div>',
				esc_html( $st[0] ),
				esc_html( self::n( $st[1] ) ),
				$k && $prev ? ' <span>' . esc_html( self::pct( $st[1], $prev, 0 ) . ' of previous step' ) . '</span>' : '',
				esc_attr( round( 100 * $st[1] / $top, 1 ) )
			);
		}
		echo '</div>';

		/* Sources */
		echo '<div class="pe-panel"><h2>Where quote requests come from</h2>';
		if ( $s['sources'] ) {
			echo '<table class="widefat striped"><thead><tr><th>Source</th><th class="num">Requests</th><th class="num">Share</th><th class="num">Booked</th></tr></thead><tbody>';
			foreach ( $s['sources'] as $src => $v ) {
				printf( '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>', esc_html( $src ), esc_html( self::n( $v['requests'] ) ), esc_html( self::pct( $v['requests'], $s['requests'], 0 ) ), esc_html( self::n( $v['booked'] ) ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="pe-empty">No quote requests in this period.</p>';
		}
		if ( $s['campaigns'] ) {
			echo '<h3>Top campaigns</h3><table class="widefat striped"><tbody>';
			foreach ( array_slice( $s['campaigns'], 0, 6, true ) as $cmp => $cnt ) {
				printf( '<tr><td>%s</td><td class="num">%s</td></tr>', esc_html( $cmp ), esc_html( self::n( $cnt ) ) );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		echo '</div>';

		/* Popular choices */
		echo '<div class="pe-panel"><h2>Most popular choices</h2><div class="pe-grid4">';
		foreach ( $s['choices'] as $group => $items ) {
			echo '<div><h3>' . esc_html( $group ) . '</h3>';
			if ( ! $items ) {
				echo '<p class="pe-empty">None yet</p></div>';
				continue;
			}
			foreach ( array_slice( $items, 0, 6, true ) as $item => $cnt ) {
				$share = $s['requests'] ? 100 * $cnt / $s['requests'] : 0;
				printf(
					'<div class="pe-ch"><div class="pe-ch-l"><span>%s</span><span>%s</span></div><div class="pe-fn-t"><div class="pe-fn-b" style="width:%s%%"></div></div></div>',
					esc_html( $item ),
					esc_html( self::n( $cnt ) . ' (' . number_format_i18n( $share, 0 ) . '%)' ),
					esc_attr( round( min( 100, $share ), 1 ) )
				);
			}
			echo '</div>';
		}
		echo '</div><p class="description">Extras can add up to more than 100% because one request can include several.</p></div>';

		/* Copyable summary */
		$summary = self::summary( $s, $p, $name );
		printf(
			'<div class="pe-panel"><h2>Summary for client reports</h2><textarea id="pe-summary" class="large-text code" rows="%d" readonly>%s</textarea><p><button type="button" class="button button-primary" id="pe-copy">Copy summary</button> <span id="pe-copied" class="description" role="status"></span></p></div>',
			count( explode( "\n", $summary ) ) + 1,
			esc_textarea( $summary )
		);
		echo '<script>document.getElementById("pe-copy").addEventListener("click",function(){var t=document.getElementById("pe-summary");t.select();var done=function(){document.getElementById("pe-copied").textContent="Copied.";};if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(done,function(){document.execCommand("copy");done();});}else{document.execCommand("copy");done();}});</script>';
		echo '</div>';
	}

	/**
	 * Plain-text recap for client emails and reports.
	 */
	public static function summary( array $s, array $p, $name ) {
		$lines   = array();
		$lines[] = $name . ': ' . self::label_range( $s['from'], $s['to'] );
		$lines[] = '';
		$lines[] = sprintf( '%s estimator views, %s started (%s)', self::n( $s['views'] ), self::n( $s['starts'] ), self::pct( $s['starts'], $s['views'], 0 ) ?: 'n/a' );
		$lines[] = sprintf( '%s quote requests%s', self::n( $s['requests'] ), $s['views'] ? ' (' . self::pct( $s['requests'], $s['views'] ) . ' of views)' : '' ) . self::text_delta( $s['requests'], $p['requests'] );
		$lines[] = sprintf( '%s booked%s%s', self::n( $s['booked'] ), $s['requests'] ? ' (' . self::pct( $s['booked'], $s['requests'], 0 ) . ' close rate)' : '', $s['booked_value'] ? ', ' . self::money( $s['booked_value'] ) . ' in booked jobs' : '' );
		$lines[] = sprintf( '%s in estimated pipeline, average estimate %s', self::money( $s['pipeline'] ), self::money( $s['avg'] ) );
		if ( $s['sources'] ) {
			$parts = array();
			foreach ( array_slice( $s['sources'], 0, 3, true ) as $src => $v ) {
				$parts[] = $src . ' ' . self::n( $v['requests'] );
			}
			$lines[] = 'Top sources: ' . implode( ', ', $parts );
		}
		$pop = array();
		foreach ( array( 'Project types', 'Styles', 'Colors' ) as $g ) {
			if ( $s['choices'][ $g ] ) {
				$k     = array_key_first( $s['choices'][ $g ] );
				$pop[] = $k . ' (' . self::pct( $s['choices'][ $g ][ $k ], $s['requests'], 0 ) . ')';
			}
		}
		if ( $pop ) {
			$lines[] = 'Most chosen: ' . implode( ', ', $pop );
		}
		return implode( "\n", $lines );
	}

	private static function text_delta( $now, $before ) {
		if ( $before <= 0 ) {
			return '';
		}
		$d = round( 100 * ( $now - $before ) / $before );
		return $d ? sprintf( ', %s%d%% vs the previous period', $d > 0 ? 'up ' : 'down ', abs( $d ) ) : ', same as the previous period';
	}

	private static function styles() {
		echo '<style>
		.pe-dash .pe-filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:12px 0 16px}
		.pe-dash .pe-range-label{color:#646970;margin-left:6px}
		.pe-dash .pe-note{background:#fff;border-left:4px solid #72aee6;padding:8px 12px;margin:0 0 16px}
		.pe-dash .pe-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:16px}
		.pe-dash .pe-card,.pe-dash .pe-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}
		.pe-dash .pe-card-l{color:#50575e;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
		.pe-dash .pe-card-v{font-size:26px;font-weight:600;line-height:1.3;margin:4px 0;color:#1d2327}
		.pe-dash .pe-card-m{font-size:12px;color:#646970}
		.pe-dash .pe-d{font-weight:600;margin-right:4px}.pe-d--up{color:#1F6B35}.pe-d--down{color:#B32D2E}.pe-d--flat{color:#646970}
		.pe-dash .pe-panel{margin-bottom:16px}.pe-dash .pe-panel h2{margin:0 0 10px;font-size:15px}.pe-dash .pe-panel h3{font-size:13px;margin:14px 0 8px}
		.pe-dash .pe-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px}.pe-dash .pe-grid2 .pe-panel{margin-bottom:16px}
		.pe-dash .pe-grid4{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px}.pe-dash .pe-grid4 h3{margin-top:0}
		.pe-dash .pe-chart{width:100%;height:auto;display:block}
		.pe-dash .pe-grid{stroke:#f0f0f1}.pe-dash .pe-ax{font-size:11px;fill:#646970}.pe-dash .pe-ax--r{text-anchor:end}.pe-dash .pe-ax--c{text-anchor:middle}
		.pe-dash .pe-bar{fill:#2271b1}.pe-dash .pe-line{fill:none;stroke:#dba617;stroke-width:2.5;stroke-linejoin:round}
		.pe-dash .pe-legend{font-size:12px;color:#50575e;margin-bottom:6px}.pe-dash .pe-key{display:inline-block;width:12px;height:12px;vertical-align:-1px;margin:0 4px 0 10px}.pe-key--bar{background:#2271b1}.pe-key--line{background:#dba617;height:3px!important;vertical-align:3px!important}
		.pe-dash .pe-fn{margin-bottom:12px}.pe-dash .pe-fn-l{font-size:13px;margin-bottom:4px}.pe-dash .pe-fn-l span{color:#646970;font-size:12px}
		.pe-dash .pe-fn-t{background:#f0f0f1;border-radius:4px;height:10px;overflow:hidden}.pe-dash .pe-fn-b{background:#2271b1;height:100%;border-radius:4px;min-width:2px}
		.pe-dash .pe-ch{margin-bottom:10px}.pe-dash .pe-ch-l{display:flex;justify-content:space-between;gap:8px;font-size:13px;margin-bottom:3px}.pe-dash .pe-ch-l span:last-child{color:#646970;white-space:nowrap}
		.pe-dash td.num,.pe-dash th.num{text-align:right}.pe-dash .pe-empty{color:#646970}
		</style>';
	}

	/* ---------- WordPress dashboard widget ---------- */

	public static function widget() {
		if ( current_user_can( self::CAP ) ) {
			wp_add_dashboard_widget( 'pe_dashboard_widget', 'Project Estimator: last 30 days', array( __CLASS__, 'render_widget' ) );
		}
	}

	public static function render_widget() {
		list( $from, $to ) = NSE_Stats::range( '30' );
		list( $pf, $pt )   = NSE_Stats::previous( $from, $to );
		$s                 = NSE_Stats::collect( $from, $to );
		$p                 = NSE_Stats::collect( $pf, $pt );
		echo '<style>#pe_dashboard_widget .pe-w{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}#pe_dashboard_widget .pe-w div{background:#f6f7f7;border-radius:4px;padding:8px 10px}#pe_dashboard_widget strong{display:block;font-size:20px}#pe_dashboard_widget .pe-d--up{color:#1F6B35}#pe_dashboard_widget .pe-d--down{color:#B32D2E}</style><div class="pe-w">';
		foreach ( array(
			array( 'Quote requests', self::n( $s['requests'] ), self::delta( $s['requests'], $p['requests'] ) ),
			array( 'Conversion rate', $s['views'] ? esc_html( self::pct( $s['requests'], $s['views'] ) ) : 'n/a', '' ),
			array( 'Booked', self::n( $s['booked'] ), self::delta( $s['booked'], $p['booked'] ) ),
			array( 'Pipeline value', self::money( $s['pipeline'] ), '' ),
		) as $c ) {
			printf( '<div>%s<strong>%s</strong>%s</div>', esc_html( $c[0] ), $c[1], $c[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formatted above.
		}
		printf( '</div><p><a href="%s">Open the estimator dashboard</a></p>', esc_url( admin_url( 'edit.php?post_type=nse_estimator&page=pe-dashboard' ) ) );
	}
}

<?php
/**
 * Estimator config: defaults, sanitizing, loading, and the shared estimate formula.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Config {

	const META_KEY     = '_nse_config';
	const MAX_PROJECTS = 12;

	public static function defaults() {
		$c             = NSE_Templates::get( 'patio' );
		$c['business'] = array(
			'name'         => get_bloginfo( 'name' ),
			'phone'        => '',
			'notify_email' => '',
			'webhook_url'  => '',
			'accent'       => '#1E3A3F',
		);
		$c['tracking'] = self::tracking_defaults();
		return $c;
	}

	public static function tracking_defaults() {
		return array(
			'event_name'  => 'project_estimator_lead',
			'value'       => 'midpoint',
			'ads_send_to' => '',
			'ga4'         => true,
			'enhanced'    => false,
		);
	}

	/**
	 * Load a saved estimator config, falling back to defaults.
	 */
	public static function get( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		$arr = $raw ? json_decode( $raw, true ) : null;
		return is_array( $arr ) ? self::sanitize( $arr ) : self::defaults();
	}

	/**
	 * Config safe to print in page HTML (no lead routing details).
	 */
	public static function public_config( array $c ) {
		unset( $c['business']['notify_email'], $c['business']['webhook_url'] );
		return $c;
	}

	private static function num( $v, $min = 0 ) {
		return max( $min, round( (float) $v, 2 ) );
	}

	private static function text( $arr, $key, $fallback = '' ) {
		return isset( $arr[ $key ] ) ? sanitize_text_field( (string) $arr[ $key ] ) : $fallback;
	}

	private static function list_of( $arr, $key ) {
		return ( isset( $arr[ $key ] ) && is_array( $arr[ $key ] ) ) ? $arr[ $key ] : array();
	}

	/**
	 * Sanitize a raw config array into the fixed schema.
	 */
	public static function sanitize( $c ) {
		$c   = is_array( $c ) ? $c : array();
		$d   = NSE_Templates::get( 'patio' );
		$out = array();

		$out['template']        = isset( $c['template'] ) ? sanitize_key( $c['template'] ) : 'custom';
		$out['headline']        = self::text( $c, 'headline', $d['headline'] );
		$out['intro']           = isset( $c['intro'] ) ? sanitize_textarea_field( (string) $c['intro'] ) : $d['intro'];
		$out['cta_text']        = self::text( $c, 'cta_text', $d['cta_text'] );
		$out['success_message'] = isset( $c['success_message'] ) ? sanitize_textarea_field( (string) $c['success_message'] ) : $d['success_message'];
		$out['disclaimer']      = isset( $c['disclaimer'] ) ? sanitize_textarea_field( (string) $c['disclaimer'] ) : $d['disclaimer'];
		$out['project_label']   = self::text( $c, 'project_label', $d['project_label'] );
		$out['round_to']        = max( 1, (int) ( isset( $c['round_to'] ) ? $c['round_to'] : 100 ) );

		// Business.
		$b               = self::list_of( $c, 'business' );
		$accent          = isset( $b['accent'] ) ? sanitize_hex_color( $b['accent'] ) : '';
		$out['business'] = array(
			'name'         => self::text( $b, 'name', get_bloginfo( 'name' ) ),
			'phone'        => self::text( $b, 'phone' ),
			'notify_email' => isset( $b['notify_email'] ) ? sanitize_email( $b['notify_email'] ) : '',
			'webhook_url'  => isset( $b['webhook_url'] ) ? esc_url_raw( $b['webhook_url'], array( 'https', 'http' ) ) : '',
			'accent'       => $accent ? $accent : '#1E3A3F',
		);

		// Project types. Configs saved before 1.2.0 kept one project at the top level.
		$raw_projects = self::list_of( $c, 'projects' );
		if ( ! $raw_projects && ( isset( $c['options'] ) || isset( $c['dims'] ) ) ) {
			$legacy_name = 'Project';
			if ( in_array( $out['template'], array( 'patio', 'landscaping', 'turf', 'pavers', 'fencing' ), true ) ) {
				$tpl         = NSE_Templates::get( $out['template'] );
				$legacy_name = $tpl['projects'][0]['name'];
			}
			$raw_projects = array(
				array(
					'name'          => $legacy_name,
					'note'          => '',
					'pricing_model' => isset( $c['pricing_model'] ) ? $c['pricing_model'] : 'area',
					'unit_label'    => isset( $c['unit_label'] ) ? $c['unit_label'] : 'sq ft',
					'min_job'       => isset( $c['min_job'] ) ? $c['min_job'] : 0,
					'options_label' => isset( $c['options_label'] ) ? $c['options_label'] : 'Choose a style',
					'addons_label'  => isset( $c['addons_label'] ) ? $c['addons_label'] : 'Add extras',
					'dims'          => self::list_of( $c, 'dims' ),
					'options'       => self::list_of( $c, 'options' ),
					'addons'        => self::list_of( $c, 'addons' ),
				),
			);
		}
		$out['projects'] = array();
		foreach ( array_slice( $raw_projects, 0, self::MAX_PROJECTS ) as $p ) {
			if ( is_array( $p ) ) {
				$out['projects'][] = self::sanitize_project( $p );
			}
		}
		if ( ! $out['projects'] ) {
			$out['projects'] = $d['projects'];
		}

		// Conversion tracking.
		$t               = isset( $c['tracking'] ) && is_array( $c['tracking'] ) ? $c['tracking'] : self::tracking_defaults();
		$td              = self::tracking_defaults();
		$event           = isset( $t['event_name'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $t['event_name'] ) : '';
		$send_to         = isset( $t['ads_send_to'] ) ? trim( (string) $t['ads_send_to'] ) : '';
		$value           = isset( $t['value'] ) ? $t['value'] : $td['value'];
		$out['tracking'] = array(
			'event_name'  => $event ? substr( $event, 0, 40 ) : $td['event_name'],
			'value'       => in_array( $value, array( 'none', 'low', 'midpoint', 'high' ), true ) ? $value : 'midpoint',
			'ads_send_to' => preg_match( '/^AW-\d+\/[A-Za-z0-9_-]+$/', $send_to ) ? $send_to : '',
			'ga4'         => isset( $t['ga4'] ) ? (bool) $t['ga4'] : $td['ga4'],
			'enhanced'    => ! empty( $t['enhanced'] ),
		);

		// Form fields. Name and phone are always required.
		$f             = self::list_of( $c, 'fields' );
		$out['fields'] = array();
		foreach ( array( 'email', 'zip', 'address', 'timeline', 'notes' ) as $key ) {
			$v                     = isset( $f[ $key ] ) ? $f[ $key ] : $d['fields'][ $key ];
			$out['fields'][ $key ] = in_array( $v, array( 'off', 'optional', 'required' ), true ) ? $v : 'off';
		}

		$choices                 = isset( $c['timeline_choices'] ) ? (array) $c['timeline_choices'] : $d['timeline_choices'];
		$out['timeline_choices'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $choices ) ) ) );

		return $out;
	}

	/**
	 * Sanitize one project type.
	 */
	public static function sanitize_project( array $p ) {
		$out                  = array();
		$out['name']          = self::text( $p, 'name', 'Project' );
		$out['name']          = '' === $out['name'] ? 'Project' : $out['name'];
		$out['note']          = self::text( $p, 'note' );
		$out['pricing_model'] = ( isset( $p['pricing_model'] ) && 'linear' === $p['pricing_model'] ) ? 'linear' : 'area';
		$out['unit_label']    = self::text( $p, 'unit_label', 'sq ft' );
		$out['min_job']       = self::num( isset( $p['min_job'] ) ? $p['min_job'] : 0 );
		$out['options_label'] = self::text( $p, 'options_label', 'Choose a style' );
		$out['addons_label']  = self::text( $p, 'addons_label', 'Add extras' );

		// Live preview scene. Guessed from the name when not set.
		$scene          = isset( $p['preview'] ) ? sanitize_key( $p['preview'] ) : '';
		$out['preview'] = NSE_Preview::valid_scene( $scene ) ? $scene : NSE_Preview::guess_scene( $out['name'], $out['pricing_model'] );

		// Measurements.
		$need = 'area' === $out['pricing_model'] ? 2 : 1;
		$dims = array();
		foreach ( array_slice( self::list_of( $p, 'dims' ), 0, 2 ) as $dim ) {
			if ( ! is_array( $dim ) ) {
				continue;
			}
			$min  = (float) ( isset( $dim['min'] ) ? $dim['min'] : 1 );
			$max  = (float) ( isset( $dim['max'] ) ? $dim['max'] : 100 );
			$max  = $max <= $min ? $min + 1 : $max;
			$step = (float) ( isset( $dim['step'] ) ? $dim['step'] : 1 );
			$step = $step > 0 ? $step : 1;
			$def  = (float) ( isset( $dim['default'] ) ? $dim['default'] : $min );

			$dims[] = array(
				'label'   => self::text( $dim, 'label', 'Size' ),
				'min'     => $min,
				'max'     => $max,
				'step'    => $step,
				'default' => min( max( $def, $min ), $max ),
			);
		}
		while ( count( $dims ) < $need ) {
			$dims[] = array( 'label' => count( $dims ) ? 'Length (ft)' : 'Width (ft)', 'min' => 5, 'max' => 100, 'step' => 1, 'default' => 20 );
		}
		$out['dims'] = array_slice( $dims, 0, $need );

		// Choices (priced per unit).
		$out['options'] = array();
		foreach ( array_slice( self::list_of( $p, 'options' ), 0, 20 ) as $o ) {
			if ( ! is_array( $o ) || '' === trim( (string) ( isset( $o['name'] ) ? $o['name'] : '' ) ) ) {
				continue;
			}
			$low              = self::num( isset( $o['low'] ) ? $o['low'] : 0 );
			$name             = self::text( $o, 'name' );
			$variant          = isset( $o['variant'] ) ? sanitize_key( $o['variant'] ) : '';
			$out['options'][] = array(
				'name'    => $name,
				'note'    => self::text( $o, 'note' ),
				'low'     => $low,
				'high'    => max( $low, self::num( isset( $o['high'] ) ? $o['high'] : $low ) ),
				'variant' => NSE_Preview::valid_variant( $out['preview'], $variant ) ? $variant : NSE_Preview::guess_variant( $out['preview'], $name ),
			);
		}

		// Extras (flat or per unit).
		$out['addons'] = array();
		foreach ( array_slice( self::list_of( $p, 'addons' ), 0, 30 ) as $a ) {
			if ( ! is_array( $a ) || '' === trim( (string) ( isset( $a['name'] ) ? $a['name'] : '' ) ) ) {
				continue;
			}
			$low             = self::num( isset( $a['low'] ) ? $a['low'] : 0 );
			$name            = self::text( $a, 'name' );
			$feature         = isset( $a['feature'] ) ? sanitize_key( $a['feature'] ) : '';
			$out['addons'][] = array(
				'name'     => $name,
				'note'     => self::text( $a, 'note' ),
				'low'      => $low,
				'high'     => max( $low, self::num( isset( $a['high'] ) ? $a['high'] : $low ) ),
				'per_unit' => ! empty( $a['per_unit'] ),
				'feature'  => NSE_Preview::valid_feature( $feature ) ? $feature : NSE_Preview::guess_feature( $name ),
			);
		}

		return $out;
	}

	/**
	 * Price range for a selection. Mirrors the math in assets/estimator.js.
	 *
	 * @return array{low:float,high:float,qty:float}
	 */
	public static function estimate( array $c, $project_index, $option_index, array $dims, array $addon_indexes ) {
		$p   = isset( $c['projects'][ $project_index ] ) ? $c['projects'][ $project_index ] : $c['projects'][0];
		$qty = 'linear' === $p['pricing_model'] ? $dims[0] : $dims[0] * $dims[1];
		$opt = isset( $p['options'][ $option_index ] ) ? $p['options'][ $option_index ] : array( 'low' => 0, 'high' => 0 );

		$low  = $qty * $opt['low'];
		$high = $qty * $opt['high'];

		foreach ( $addon_indexes as $i ) {
			if ( ! isset( $p['addons'][ $i ] ) ) {
				continue;
			}
			$a     = $p['addons'][ $i ];
			$mult  = $a['per_unit'] ? $qty : 1;
			$low  += $a['low'] * $mult;
			$high += $a['high'] * $mult;
		}

		$r = max( 1, (int) $c['round_to'] );
		return array(
			'low'  => max( round( $low / $r ) * $r, $p['min_job'] ),
			'high' => max( round( $high / $r ) * $r, round( $p['min_job'] * 1.3 / $r ) * $r ),
			'qty'  => $qty,
		);
	}
}

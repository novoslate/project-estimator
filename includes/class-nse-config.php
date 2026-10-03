<?php
/**
 * Estimator config: defaults, sanitizing, loading, and the shared estimate formula.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Config {

	const META_KEY = '_nse_config';

	public static function defaults() {
		$c             = NSE_Templates::get( 'patio' );
		$c['business'] = array(
			'name'         => get_bloginfo( 'name' ),
			'phone'        => '',
			'notify_email' => '',
			'webhook_url'  => '',
			'accent'       => '#1E3A3F',
		);
		return $c;
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

	/**
	 * Sanitize a raw config array into the fixed schema.
	 */
	public static function sanitize( $c ) {
		$c   = is_array( $c ) ? $c : array();
		$d   = self::defaults();
		$out = array();

		$out['template']        = isset( $c['template'] ) ? sanitize_key( $c['template'] ) : 'custom';
		$out['headline']        = self::text( $c, 'headline', $d['headline'] );
		$out['intro']           = isset( $c['intro'] ) ? sanitize_textarea_field( (string) $c['intro'] ) : $d['intro'];
		$out['cta_text']        = self::text( $c, 'cta_text', $d['cta_text'] );
		$out['success_message'] = isset( $c['success_message'] ) ? sanitize_textarea_field( (string) $c['success_message'] ) : $d['success_message'];
		$out['disclaimer']      = isset( $c['disclaimer'] ) ? sanitize_textarea_field( (string) $c['disclaimer'] ) : $d['disclaimer'];
		$out['options_label']   = self::text( $c, 'options_label', $d['options_label'] );
		$out['addons_label']    = self::text( $c, 'addons_label', $d['addons_label'] );
		$out['unit_label']      = self::text( $c, 'unit_label', 'sq ft' );
		$out['pricing_model']   = ( isset( $c['pricing_model'] ) && 'linear' === $c['pricing_model'] ) ? 'linear' : 'area';
		$out['min_job']         = self::num( isset( $c['min_job'] ) ? $c['min_job'] : 0 );
		$out['round_to']        = max( 1, (int) ( isset( $c['round_to'] ) ? $c['round_to'] : 100 ) );

		// Business.
		$b               = ( isset( $c['business'] ) && is_array( $c['business'] ) ) ? $c['business'] : array();
		$accent          = isset( $b['accent'] ) ? sanitize_hex_color( $b['accent'] ) : '';
		$out['business'] = array(
			'name'         => self::text( $b, 'name', $d['business']['name'] ),
			'phone'        => self::text( $b, 'phone' ),
			'notify_email' => isset( $b['notify_email'] ) ? sanitize_email( $b['notify_email'] ) : '',
			'webhook_url'  => isset( $b['webhook_url'] ) ? esc_url_raw( $b['webhook_url'], array( 'https', 'http' ) ) : '',
			'accent'       => $accent ? $accent : '#1E3A3F',
		);

		// Measurements.
		$need = 'area' === $out['pricing_model'] ? 2 : 1;
		$dims = array();
		foreach ( array_slice( (array) ( isset( $c['dims'] ) ? $c['dims'] : array() ), 0, 2 ) as $dim ) {
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

		// Options (priced per unit).
		$out['options'] = array();
		foreach ( array_slice( (array) ( isset( $c['options'] ) ? $c['options'] : array() ), 0, 20 ) as $o ) {
			if ( ! is_array( $o ) || '' === trim( (string) ( isset( $o['name'] ) ? $o['name'] : '' ) ) ) {
				continue;
			}
			$low              = self::num( isset( $o['low'] ) ? $o['low'] : 0 );
			$out['options'][] = array(
				'name' => self::text( $o, 'name' ),
				'note' => self::text( $o, 'note' ),
				'low'  => $low,
				'high' => max( $low, self::num( isset( $o['high'] ) ? $o['high'] : $low ) ),
			);
		}

		// Add-ons (flat or per unit).
		$out['addons'] = array();
		foreach ( array_slice( (array) ( isset( $c['addons'] ) ? $c['addons'] : array() ), 0, 30 ) as $a ) {
			if ( ! is_array( $a ) || '' === trim( (string) ( isset( $a['name'] ) ? $a['name'] : '' ) ) ) {
				continue;
			}
			$low             = self::num( isset( $a['low'] ) ? $a['low'] : 0 );
			$out['addons'][] = array(
				'name'     => self::text( $a, 'name' ),
				'note'     => self::text( $a, 'note' ),
				'low'      => $low,
				'high'     => max( $low, self::num( isset( $a['high'] ) ? $a['high'] : $low ) ),
				'per_unit' => ! empty( $a['per_unit'] ),
			);
		}

		// Form fields. Name and phone are always required.
		$f             = ( isset( $c['fields'] ) && is_array( $c['fields'] ) ) ? $c['fields'] : array();
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
	 * Price range for a selection. Mirrors the math in assets/estimator.js.
	 *
	 * @return array{low:float,high:float,qty:float}
	 */
	public static function estimate( array $c, $option_index, array $dims, array $addon_indexes ) {
		$qty = 'linear' === $c['pricing_model'] ? $dims[0] : $dims[0] * $dims[1];
		$opt = isset( $c['options'][ $option_index ] ) ? $c['options'][ $option_index ] : array( 'low' => 0, 'high' => 0 );

		$low  = $qty * $opt['low'];
		$high = $qty * $opt['high'];

		foreach ( $addon_indexes as $i ) {
			if ( ! isset( $c['addons'][ $i ] ) ) {
				continue;
			}
			$a     = $c['addons'][ $i ];
			$mult  = $a['per_unit'] ? $qty : 1;
			$low  += $a['low'] * $mult;
			$high += $a['high'] * $mult;
		}

		$r = max( 1, (int) $c['round_to'] );
		return array(
			'low'  => max( round( $low / $r ) * $r, $c['min_job'] ),
			'high' => max( round( $high / $r ) * $r, round( $c['min_job'] * 1.3 / $r ) * $r ),
			'qty'  => $qty,
		);
	}
}

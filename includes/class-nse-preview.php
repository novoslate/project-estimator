<?php
/**
 * Live preview catalog: scenes, looks (variants), and visual features,
 * plus keyword matching so existing and custom estimators get sensible defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Preview {

	/**
	 * Scenes and their looks. The first look is the fallback.
	 * Keywords are matched against names in order.
	 */
	public static function scenes() {
		return array(
			'patio_cover' => array(
				'label'    => 'Patio cover',
				'variants' => array(
					'solid'   => array( 'Solid roof', array( 'solid', 'insulated' ) ),
					'combo'   => array( 'Solid and lattice', array( 'combo', 'combination', 'mixed' ) ),
					'lattice' => array( 'Lattice', array( 'lattice', 'open' ) ),
				),
			),
			'pergola'     => array(
				'label'    => 'Pergola',
				'variants' => array(
					'aluminum' => array( 'Aluminum', array( 'aluminum', 'aluminium', 'metal' ) ),
					'louvered' => array( 'Louvered', array( 'louver', 'louvre', 'adjustable' ) ),
					'wood'     => array( 'Wood', array( 'wood', 'cedar', 'timber' ) ),
				),
			),
			'sunroom'     => array(
				'label'    => 'Sunroom',
				'variants' => array(
					'three_season' => array( 'Three-season (glass)', array( 'three', '3 season', '3-season' ) ),
					'screen'       => array( 'Screen room', array( 'screen' ) ),
					'four_season'  => array( 'Four-season (insulated)', array( 'four', '4 season', '4-season', 'all season', 'year' ) ),
				),
			),
			'enclosure'   => array(
				'label'    => 'Patio enclosure',
				'variants' => array(
					'screen' => array( 'Screen', array( 'screen', 'mesh' ) ),
					'vinyl'  => array( 'Vinyl windows', array( 'vinyl', 'acrylic', 'window' ) ),
					'glass'  => array( 'Glass panels', array( 'glass' ) ),
				),
			),
			'landscape'   => array(
				'label'    => 'Landscaping',
				'variants' => array(
					'desert' => array( 'Desert plants and rock', array( 'desert', 'xeri', 'plant' ) ),
					'gravel' => array( 'Rock and gravel', array( 'rock', 'gravel', 'granite' ) ),
					'full'   => array( 'Full design with path', array( 'full', 'redesign', 'custom', 'premium' ) ),
				),
			),
			'turf'        => array(
				'label'    => 'Artificial turf',
				'variants' => array(
					'standard' => array( 'Standard turf', array( 'standard', 'basic' ) ),
					'premium'  => array( 'Premium turf', array( 'premium', 'luxury' ) ),
					'putting'  => array( 'Putting green', array( 'putting', 'golf' ) ),
				),
			),
			'pavers'      => array(
				'label'    => 'Pavers',
				'variants' => array(
					'concrete'   => array( 'Concrete pavers', array( 'concrete', 'brick' ) ),
					'travertine' => array( 'Travertine', array( 'travertine', 'stone' ) ),
					'porcelain'  => array( 'Porcelain planks', array( 'porcelain', 'tile' ) ),
				),
			),
			'fence'       => array(
				'label'    => 'Fence or wall',
				'variants' => array(
					'wood'  => array( 'Wood', array( 'wood', 'cedar' ) ),
					'block' => array( 'Block wall', array( 'block', 'masonry', 'cmu', 'wall', 'stucco' ) ),
					'iron'  => array( 'Wrought iron', array( 'iron', 'steel', 'metal', 'aluminum' ) ),
					'vinyl' => array( 'Vinyl', array( 'vinyl', 'pvc' ) ),
				),
			),
			'plan'        => array(
				'label'    => 'Top-down plan only',
				'variants' => array(),
			),
		);
	}

	/**
	 * Visual features an extra can turn on. Keywords match extra names.
	 */
	public static function features() {
		return array(
			'none'         => array( 'Not shown (listed under preview)', array() ),
			'lights'       => array( 'Lights', array( 'light', 'led', 'lighting' ) ),
			'fan'          => array( 'Ceiling fan', array( 'fan' ) ),
			'canopy'       => array( 'Shade canopy', array( 'canopy', 'fabric', 'sail' ) ),
			'privacy_wall' => array( 'Privacy wall', array( 'privacy', 'slat' ) ),
			'ac'           => array( 'AC unit', array( 'mini split', 'mini-split', 'hvac', 'air condition', '\bac\b' ) ),
			'knee_wall'    => array( 'Knee wall', array( 'knee' ) ),
			'screen_door'  => array( 'Door', array( 'door' ) ),
			'boulders'     => array( 'Boulders', array( 'boulder' ) ),
			'fire_pit'     => array( 'Fire pit', array( 'fire' ) ),
			'seat_wall'    => array( 'Seat wall', array( 'seat' ) ),
			'drive_gate'   => array( 'Driveway gate', array( 'driveway', '\brv\b', 'drive gate', 'rolling gate', 'double gate' ) ),
			'walk_gate'    => array( 'Walk gate', array( 'walk gate', 'side gate', 'pedestrian', 'gate' ) ),
			'stucco'       => array( 'Stucco finish', array( 'stucco' ) ),
			'edging'       => array( 'Border edging', array( 'edging', 'border', 'curb' ) ),
		);
	}

	private static function matches( $text, array $keywords ) {
		$text = strtolower( $text );
		foreach ( $keywords as $kw ) {
			if ( 0 === strpos( $kw, '\b' ) ) {
				if ( preg_match( '/' . $kw . '/', $text ) ) {
					return true;
				}
			} elseif ( false !== strpos( $text, $kw ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Guess a scene from the project type name and pricing model.
	 */
	public static function guess_scene( $name, $pricing_model ) {
		$n     = strtolower( $name );
		$rules = array(
			'pergola'     => array( 'pergola', 'arbor', 'gazebo' ),
			'sunroom'     => array( 'sunroom', 'sun room', 'screen room', 'florida room', 'solarium' ),
			'enclosure'   => array( 'enclos' ),
			'patio_cover' => array( 'patio cover', 'cover', 'awning', 'shade structure', 'carport' ),
			'turf'        => array( 'turf', 'synthetic grass', 'artificial grass', 'putting' ),
			'pavers'      => array( 'paver', 'hardscape', 'travertine', 'flagstone', 'patio', 'walkway', 'deck' ),
			'fence'       => array( 'fence', 'fencing', 'wall', 'gate', 'railing' ),
			'landscape'   => array( 'landscap', 'yard', 'xeriscape', 'garden', 'plant' ),
		);
		foreach ( $rules as $scene => $kws ) {
			if ( self::matches( $n, $kws ) ) {
				return $scene;
			}
		}
		return 'linear' === $pricing_model ? 'fence' : 'plan';
	}

	public static function guess_variant( $scene, $name ) {
		$scenes = self::scenes();
		if ( empty( $scenes[ $scene ]['variants'] ) ) {
			return '';
		}
		foreach ( $scenes[ $scene ]['variants'] as $key => $v ) {
			if ( self::matches( $name, $v[1] ) ) {
				return $key;
			}
		}
		$keys = array_keys( $scenes[ $scene ]['variants'] );
		return $keys[0];
	}

	public static function guess_feature( $name ) {
		foreach ( self::features() as $key => $f ) {
			if ( $f[1] && self::matches( $name, $f[1] ) ) {
				return $key;
			}
		}
		return 'none';
	}

	public static function valid_scene( $scene ) {
		return array_key_exists( $scene, self::scenes() );
	}

	public static function valid_variant( $scene, $variant ) {
		$scenes = self::scenes();
		return isset( $scenes[ $scene ]['variants'][ $variant ] );
	}

	public static function valid_feature( $feature ) {
		return array_key_exists( $feature, self::features() );
	}

	/**
	 * Labels for the admin editor dropdowns.
	 */
	public static function admin_catalog() {
		$scenes = array();
		foreach ( self::scenes() as $key => $s ) {
			$variants = array();
			foreach ( $s['variants'] as $vk => $v ) {
				$variants[] = array( $vk, $v[0] );
			}
			$scenes[ $key ] = array(
				'label'    => $s['label'],
				'variants' => $variants,
			);
		}
		$features = array();
		foreach ( self::features() as $fk => $f ) {
			$features[] = array( $fk, $f[0] );
		}
		return array(
			'scenes'   => $scenes,
			'features' => $features,
		);
	}
}

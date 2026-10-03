<?php
/**
 * Prebuilt estimator templates. Prices are starting placeholders; confirm real ranges with each client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Templates {

	/**
	 * Shared defaults merged into every template.
	 */
	private static function base( array $over ) {
		return array_merge(
			array(
				'headline'         => 'See what your project will cost',
				'intro'            => 'Answer a few quick questions and get a price range in under a minute.',
				'cta_text'         => 'Send my quote request',
				'success_message'  => 'Thanks! We will call you within one business day to set up a free on-site visit.',
				'disclaimer'       => 'Prices are estimates for typical installs. Your final price is confirmed after a free on-site visit.',
				'pricing_model'    => 'area',
				'unit_label'       => 'sq ft',
				'min_job'          => 2500,
				'round_to'         => 100,
				'options_label'    => 'Choose a style',
				'addons_label'     => 'Add extras',
				'fields'           => array(
					'email'    => 'optional',
					'zip'      => 'required',
					'address'  => 'off',
					'timeline' => 'optional',
					'notes'    => 'off',
				),
				'timeline_choices' => array( 'As soon as possible', 'In 1 to 3 months', 'In 3 to 6 months', 'Just pricing it out' ),
				'dims'             => array(),
				'options'          => array(),
				'addons'           => array(),
			),
			$over
		);
	}

	private static function dim( $label, $min, $max, $default, $step = 1 ) {
		return compact( 'label', 'min', 'max', 'step', 'default' );
	}

	private static function opt( $name, $note, $low, $high ) {
		return compact( 'name', 'note', 'low', 'high' );
	}

	private static function addon( $name, $note, $low, $high, $per_unit = false ) {
		return compact( 'name', 'note', 'low', 'high', 'per_unit' );
	}

	/**
	 * All templates keyed by slug.
	 */
	public static function all() {
		return array(
			'patio'       => array(
				'label'  => 'Patio covers and pergolas',
				'config' => self::base(
					array(
						'template' => 'patio',
						'headline' => 'See what your patio cover will cost',
						'intro'    => 'Pick a style, set your size, and get a price range in under a minute. No sales call needed to see the number.',
						'min_job'  => 3500,
						'dims'     => array(
							self::dim( 'Width along the house (ft)', 10, 50, 20 ),
							self::dim( 'Projection from the house (ft)', 8, 24, 12 ),
						),
						'options'  => array(
							self::opt( 'Solid roof', 'Full shade, insulated panels', 38, 55 ),
							self::opt( 'Lattice', 'Filtered shade, open feel', 22, 32 ),
							self::opt( 'Louvered pergola', 'Adjustable shade and rain cover', 65, 95 ),
						),
						'addons'   => array(
							self::addon( 'Ceiling fan', 'Mounted and wired', 450, 650 ),
							self::addon( 'LED lighting', 'Recessed lights with dimmer', 600, 1100 ),
							self::addon( 'New electrical run', 'If no outlet is near the patio', 700, 1400 ),
							self::addon( 'Remove existing cover', 'Tear-out and haul-away', 800, 1600 ),
							self::addon( 'Permit and HOA paperwork', 'We handle plans and approvals', 400, 800 ),
						),
					)
				),
			),
			'landscaping' => array(
				'label'  => 'Landscaping install',
				'config' => self::base(
					array(
						'template'      => 'landscaping',
						'headline'      => 'See what your new yard will cost',
						'options_label' => 'Choose a package',
						'dims'          => array(
							self::dim( 'Yard width (ft)', 10, 120, 30 ),
							self::dim( 'Yard length (ft)', 10, 120, 40 ),
						),
						'options'       => array(
							self::opt( 'Rock and gravel refresh', 'New decomposed granite and cleanup', 2, 4 ),
							self::opt( 'Desert landscape', 'Rock, plants, and drip irrigation', 6, 12 ),
							self::opt( 'Full redesign', 'Plants, hardscape, and features', 15, 30 ),
						),
						'addons'        => array(
							self::addon( 'Drip irrigation system', 'New lines, emitters, and timer', 1200, 2500 ),
							self::addon( 'Landscape lighting', 'Low voltage path and accent lights', 1500, 3500 ),
							self::addon( 'Accent boulders', 'Placed by machine', 400, 900 ),
							self::addon( 'Weed barrier', 'Under all rock areas', 0.3, 0.6, true ),
							self::addon( 'Remove old material', 'Haul away grass, rock, or debris', 1, 2, true ),
						),
					)
				),
			),
			'turf'        => array(
				'label'  => 'Artificial turf',
				'config' => self::base(
					array(
						'template'      => 'turf',
						'headline'      => 'See what your artificial turf will cost',
						'min_job'       => 2000,
						'options_label' => 'Choose your turf',
						'dims'          => array(
							self::dim( 'Area width (ft)', 5, 80, 20 ),
							self::dim( 'Area length (ft)', 5, 80, 25 ),
						),
						'options'       => array(
							self::opt( 'Standard turf', 'Soft, durable, pet friendly', 10, 14 ),
							self::opt( 'Premium turf', 'Thicker blades, cooler surface', 13, 18 ),
							self::opt( 'Putting green', 'Tour-grade surface with cups', 18, 28 ),
						),
						'addons'        => array(
							self::addon( 'Remove existing grass', 'Excavation and haul-away', 1, 2, true ),
							self::addon( 'Cooling infill', 'Lowers surface temperature', 0.75, 1.5, true ),
							self::addon( 'Pet odor treatment', 'Enzyme infill for dog areas', 0.5, 1, true ),
							self::addon( 'Border edging', 'Clean finished edges', 300, 800 ),
						),
					)
				),
			),
			'pavers'      => array(
				'label'  => 'Pavers and hardscape',
				'config' => self::base(
					array(
						'template'      => 'pavers',
						'headline'      => 'See what your paver project will cost',
						'min_job'       => 3000,
						'options_label' => 'Choose a material',
						'dims'          => array(
							self::dim( 'Area width (ft)', 5, 60, 15 ),
							self::dim( 'Area length (ft)', 5, 60, 20 ),
						),
						'options'       => array(
							self::opt( 'Concrete pavers', 'Many colors and patterns', 14, 20 ),
							self::opt( 'Travertine', 'Natural stone, stays cool', 22, 32 ),
							self::opt( 'Porcelain', 'Modern look, very low upkeep', 30, 45 ),
						),
						'addons'        => array(
							self::addon( 'Remove old concrete', 'Break out and haul away', 3, 6, true ),
							self::addon( 'Sealing', 'Protects color and resists stains', 1, 2, true ),
							self::addon( 'Fire pit', 'Built-in gas or wood', 2500, 5000 ),
							self::addon( 'Seat wall', 'Matching block with cap', 3500, 7000 ),
						),
					)
				),
			),
			'fencing'     => array(
				'label'  => 'Fencing and walls',
				'config' => self::base(
					array(
						'template'      => 'fencing',
						'headline'      => 'See what your new fence will cost',
						'pricing_model' => 'linear',
						'unit_label'    => 'linear ft',
						'options_label' => 'Choose a fence type',
						'dims'          => array(
							self::dim( 'Total fence length (ft)', 20, 400, 100, 5 ),
						),
						'options'       => array(
							self::opt( 'Block wall', 'Most private and durable', 60, 120 ),
							self::opt( 'Wrought iron', 'Open views, secure', 40, 70 ),
							self::opt( 'Vinyl', 'Clean look, no painting', 30, 50 ),
							self::opt( 'Wood', 'Classic look, lower cost', 25, 45 ),
						),
						'addons'        => array(
							self::addon( 'Walk gate', 'Standard 4 ft gate', 600, 1200 ),
							self::addon( 'Driveway or RV gate', 'Double swing or rolling', 2500, 5000 ),
							self::addon( 'Stucco finish', 'For block walls', 15, 30, true ),
							self::addon( 'Remove old fence', 'Tear-out and haul-away', 4, 8, true ),
						),
					)
				),
			),
			'custom'      => array(
				'label'  => 'Blank (start from scratch)',
				'config' => self::base(
					array(
						'template' => 'custom',
						'dims'     => array(
							self::dim( 'Width (ft)', 5, 100, 20 ),
							self::dim( 'Length (ft)', 5, 100, 20 ),
						),
						'options'  => array(
							self::opt( 'Option 1', 'Short description', 10, 20 ),
						),
					)
				),
			),
		);
	}

	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ]['config'] : $all['patio']['config'];
	}
}

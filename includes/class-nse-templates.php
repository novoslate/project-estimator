<?php
/**
 * Prebuilt estimator templates. Each template holds one or more project types.
 * Prices are starting placeholders; confirm real ranges with each client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Templates {

	/**
	 * Estimator-level defaults shared by every template.
	 */
	private static function base( array $over ) {
		return array_merge(
			array(
				'headline'         => 'See what your project will cost',
				'intro'            => 'Answer a few quick questions and get a price range in under a minute.',
				'cta_text'         => 'Send my quote request',
				'success_message'  => 'Thanks! We will call you within one business day to set up a free on-site visit.',
				'disclaimer'       => 'Prices are estimates for typical installs. Your final price is confirmed after a free on-site visit.',
				'project_label'    => 'What are you planning?',
				'round_to'         => 100,
				'fields'           => array(
					'email'    => 'optional',
					'zip'      => 'required',
					'address'  => 'off',
					'timeline' => 'optional',
					'notes'    => 'off',
				),
				'timeline_choices' => array( 'As soon as possible', 'In 1 to 3 months', 'In 3 to 6 months', 'Just pricing it out' ),
				'projects'         => array(),
			),
			$over
		);
	}

	/**
	 * One project type with its own measurements, choices, and extras.
	 */
	private static function project( $name, $note, array $over ) {
		return array_merge(
			array(
				'name'          => $name,
				'note'          => $note,
				'pricing_model' => 'area',
				'unit_label'    => 'sq ft',
				'min_job'       => 2500,
				'options_label' => 'Choose a style',
				'addons_label'  => 'Add extras',
				'colors_label'  => 'Choose a color',
				'colors'        => array(),
				'dims'          => array(),
				'options'       => array(),
				'addons'        => array(),
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

	private static function color( $name, $hex, $upcharge = 0 ) {
		return compact( 'name', 'hex', 'upcharge' );
	}

	/**
	 * Default color lists by preview scene. Also used to give existing
	 * project types colors when they were saved before colors existed.
	 */
	public static function default_colors( $scene ) {
		$frame = array(
			self::color( 'White', '#F7F6F2' ),
			self::color( 'Desert Sand', '#D8C6A4' ),
			self::color( 'Adobe', '#C9A983' ),
			self::color( 'Bronze', '#5B4636' ),
		);
		switch ( $scene ) {
			case 'patio_cover':
				return array( 'Frame color', array_merge( $frame, array( self::color( 'Woodgrain', '#9A6A42', 15 ) ) ) );
			case 'pergola':
				return array(
					'Finish color',
					array(
						self::color( 'White', '#F7F6F2' ),
						self::color( 'Bronze', '#5B4636' ),
						self::color( 'Charcoal', '#43474A' ),
						self::color( 'Woodgrain', '#9A6A42', 10 ),
					),
				);
			case 'sunroom':
			case 'enclosure':
				return array( 'Frame color', $frame );
			case 'landscape':
				return array(
					'Rock color',
					array(
						self::color( 'Madison Gold', '#C9A66B' ),
						self::color( 'Express Brown', '#8A6A50' ),
						self::color( 'Coral Gold', '#D19A6E' ),
						self::color( 'Table Mesa Brown', '#A07E5E' ),
					),
				);
			case 'pavers':
				return array(
					'Paver color',
					array(
						self::color( 'Sand', '#D9C29E' ),
						self::color( 'Ivory', '#EFE6D6' ),
						self::color( 'Gray', '#B9B8B3' ),
						self::color( 'Sierra', '#B97C5E' ),
					),
				);
			case 'fence':
				return array(
					'Fence color',
					array(
						self::color( 'Tan', '#D9C6A5' ),
						self::color( 'White', '#F5F5F2' ),
						self::color( 'Black', '#2F3336' ),
						self::color( 'Bronze', '#5B4636' ),
						self::color( 'Natural wood', '#B98352' ),
					),
				);
		}
		return array( 'Choose a color', array() );
	}

	private static function with_colors( array $project, $scene ) {
		$c                       = self::default_colors( $scene );
		$project['colors_label'] = $c[0];
		$project['colors']       = $c[1];
		return $project;
	}

	/* ---------- Project types ---------- */

	private static function patio_cover() {
		return self::with_colors( self::patio_cover_base(), 'patio_cover' );
	}

	private static function patio_cover_base() {
		return self::project(
			'Patio cover',
			'Shade attached to your home',
			array(
				'min_job' => 3500,
				'dims'    => array(
					self::dim( 'Width along the house (ft)', 10, 50, 20 ),
					self::dim( 'Projection from the house (ft)', 8, 24, 12 ),
				),
				'options' => array(
					self::opt( 'Solid roof', 'Full shade, insulated panels', 38, 55 ),
					self::opt( 'Lattice', 'Filtered shade, open feel', 22, 32 ),
					self::opt( 'Combination', 'Solid and lattice sections', 32, 45 ),
				),
				'addons'  => array(
					self::addon( 'Ceiling fan', 'Mounted and wired', 450, 650 ),
					self::addon( 'LED lighting', 'Recessed lights with dimmer', 600, 1100 ),
					self::addon( 'New electrical run', 'If no outlet is near the patio', 700, 1400 ),
					self::addon( 'Remove existing cover', 'Tear-out and haul-away', 800, 1600 ),
					self::addon( 'Permit and HOA paperwork', 'We handle plans and approvals', 400, 800 ),
				),
			)
		);
	}

	private static function pergola() {
		return self::with_colors( self::pergola_base(), 'pergola' );
	}

	private static function pergola_base() {
		return self::project(
			'Pergola',
			'Freestanding or attached shade structure',
			array(
				'min_job' => 4000,
				'dims'    => array(
					self::dim( 'Width (ft)', 8, 40, 14 ),
					self::dim( 'Depth (ft)', 8, 30, 12 ),
				),
				'options' => array(
					self::opt( 'Aluminum pergola', 'Low upkeep, open top', 30, 45 ),
					self::opt( 'Louvered pergola', 'Adjustable roof, closes for rain', 65, 95 ),
					self::opt( 'Wood pergola', 'Classic look, stained finish', 25, 40 ),
				),
				'addons'  => array(
					self::addon( 'Shade canopy', 'Retractable fabric cover', 900, 1800 ),
					self::addon( 'LED lighting', 'Integrated perimeter lights', 700, 1300 ),
					self::addon( 'Motorized louvers', 'Remote or app control', 1500, 3000 ),
					self::addon( 'Privacy wall', 'Slatted side panel', 1200, 2400 ),
				),
			)
		);
	}

	private static function sunroom() {
		return self::with_colors( self::sunroom_base(), 'sunroom' );
	}

	private static function sunroom_base() {
		return self::project(
			'Sunroom',
			'Enclosed room you can use year round',
			array(
				'min_job'       => 8000,
				'options_label' => 'Choose a sunroom type',
				'dims'          => array(
					self::dim( 'Width (ft)', 8, 30, 14 ),
					self::dim( 'Depth (ft)', 8, 20, 12 ),
				),
				'options'       => array(
					self::opt( 'Screen room', 'Insulated roof, screened walls', 25, 40 ),
					self::opt( 'Three-season room', 'Windows, not fully insulated', 90, 150 ),
					self::opt( 'Four-season room', 'Fully insulated, heated and cooled', 150, 250 ),
				),
				'addons'        => array(
					self::addon( 'Mini split AC', 'Ductless heating and cooling', 3500, 6000 ),
					self::addon( 'Ceiling fan and lights', 'Wired with wall switch', 800, 1500 ),
					self::addon( 'Upgraded flooring', 'Tile or luxury vinyl', 8, 15, true ),
					self::addon( 'Permit and engineering', 'Plans, permits, inspections', 1200, 2500 ),
				),
			)
		);
	}

	private static function patio_enclosure() {
		return self::with_colors( self::patio_enclosure_base(), 'enclosure' );
	}

	private static function patio_enclosure_base() {
		return self::project(
			'Patio enclosure',
			'Enclose an existing covered patio',
			array(
				'min_job'       => 5000,
				'options_label' => 'Choose an enclosure type',
				'dims'          => array(
					self::dim( 'Patio width (ft)', 8, 40, 20 ),
					self::dim( 'Patio depth (ft)', 6, 24, 12 ),
				),
				'options'       => array(
					self::opt( 'Screen enclosure', 'Bug-free, open air', 20, 35 ),
					self::opt( 'Vinyl windows', 'Blocks wind and dust', 40, 70 ),
					self::opt( 'Glass panels', 'Clear views, best insulation', 80, 130 ),
				),
				'addons'        => array(
					self::addon( 'Screen door', 'Self-closing entry door', 400, 800 ),
					self::addon( 'Retractable screens', 'Motorized, per opening', 2500, 4500 ),
					self::addon( 'Knee wall', 'Low solid wall around the base', 25, 45, true ),
				),
			)
		);
	}

	private static function landscaping() {
		return self::with_colors( self::landscaping_base(), 'landscape' );
	}

	private static function landscaping_base() {
		return self::project(
			'Landscaping',
			'New yard design and install',
			array(
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
		);
	}

	private static function turf() {
		return self::project(
			'Artificial turf',
			'Green lawn with no watering',
			array(
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
		);
	}

	private static function pavers() {
		return self::with_colors( self::pavers_base(), 'pavers' );
	}

	private static function pavers_base() {
		return self::project(
			'Pavers',
			'Patios, walkways, and pool decks',
			array(
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
		);
	}

	private static function fencing() {
		return self::with_colors( self::fencing_base(), 'fence' );
	}

	private static function fencing_base() {
		return self::project(
			'Fencing',
			'Privacy, security, and gates',
			array(
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
		);
	}

	/* ---------- Templates ---------- */

	/**
	 * All templates keyed by slug.
	 */
	public static function all() {
		return array(
			'outdoor'     => array(
				'label'  => 'Outdoor living (patio covers, pergolas, sunrooms, enclosures)',
				'config' => self::base(
					array(
						'template' => 'outdoor',
						'headline' => 'See what your outdoor project will cost',
						'intro'    => 'Pick your project, set your size, and get a price range in under a minute. No sales call needed to see the number.',
						'projects' => array( self::patio_cover(), self::pergola(), self::sunroom(), self::patio_enclosure() ),
					)
				),
			),
			'patio'       => array(
				'label'  => 'Patio covers only',
				'config' => self::base(
					array(
						'template' => 'patio',
						'headline' => 'See what your patio cover will cost',
						'intro'    => 'Pick a style, set your size, and get a price range in under a minute. No sales call needed to see the number.',
						'projects' => array( self::patio_cover() ),
					)
				),
			),
			'yard'        => array(
				'label'  => 'Landscaping, turf, and pavers',
				'config' => self::base(
					array(
						'template' => 'yard',
						'headline' => 'See what your yard project will cost',
						'projects' => array( self::landscaping(), self::turf(), self::pavers() ),
					)
				),
			),
			'landscaping' => array(
				'label'  => 'Landscaping only',
				'config' => self::base(
					array(
						'template' => 'landscaping',
						'headline' => 'See what your new yard will cost',
						'projects' => array( self::landscaping() ),
					)
				),
			),
			'turf'        => array(
				'label'  => 'Artificial turf only',
				'config' => self::base(
					array(
						'template' => 'turf',
						'headline' => 'See what your artificial turf will cost',
						'projects' => array( self::turf() ),
					)
				),
			),
			'pavers'      => array(
				'label'  => 'Pavers only',
				'config' => self::base(
					array(
						'template' => 'pavers',
						'headline' => 'See what your paver project will cost',
						'projects' => array( self::pavers() ),
					)
				),
			),
			'fencing'     => array(
				'label'  => 'Fencing only',
				'config' => self::base(
					array(
						'template' => 'fencing',
						'headline' => 'See what your new fence will cost',
						'projects' => array( self::fencing() ),
					)
				),
			),
			'custom'      => array(
				'label'  => 'Blank (start from scratch)',
				'config' => self::base(
					array(
						'template' => 'custom',
						'projects' => array(
							self::project(
								'Project 1',
								'',
								array(
									'dims'    => array( self::dim( 'Width (ft)', 5, 100, 20 ), self::dim( 'Length (ft)', 5, 100, 20 ) ),
									'options' => array( self::opt( 'Option 1', 'Short description', 10, 20 ) ),
								)
							),
						),
					)
				),
			),
		);
	}

	/**
	 * Every project type across all templates, for "Add from library" in the editor.
	 */
	public static function library() {
		return array(
			self::patio_cover(),
			self::pergola(),
			self::sunroom(),
			self::patio_enclosure(),
			self::landscaping(),
			self::turf(),
			self::pavers(),
			self::fencing(),
		);
	}

	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ]['config'] : $all['outdoor']['config'];
	}
}

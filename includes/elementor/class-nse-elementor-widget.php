<?php
/**
 * Project Estimator widget for Elementor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class NSE_Elementor_Widget extends Widget_Base {

	public function get_name() {
		return 'project_estimator';
	}

	public function get_title() {
		return 'Project Estimator';
	}

	public function get_icon() {
		return 'eicon-price-table';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'estimator', 'estimate', 'quote', 'calculator', 'price', 'pricing', 'lead', 'form' );
	}

	public function get_script_depends() {
		return array( 'nse-estimator' );
	}

	public function get_style_depends() {
		return array( 'nse-estimator' );
	}

	/**
	 * Never cache the rendered HTML: it differs for logged-in editors (no view tracking) and visitors.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	private function estimator_options() {
		$opts  = array( '' => 'Choose an estimator' );
		$posts = get_posts(
			array(
				'post_type'   => 'nse_estimator',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		foreach ( $posts as $p ) {
			$opts[ (string) $p->ID ] = $p->post_title ? $p->post_title : 'Estimator #' . $p->ID;
		}
		return $opts;
	}

	protected function register_controls() {
		/* ---------- Content ---------- */
		$this->start_controls_section( 'section_estimator', array( 'label' => 'Estimator' ) );

		$this->add_control(
			'estimator_id',
			array(
				'label'   => 'Estimator',
				'type'    => Controls_Manager::SELECT,
				'options' => $this->estimator_options(),
				'default' => '',
			)
		);

		$this->add_control(
			'estimator_links',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => sprintf(
					'<a href="%s" target="_blank" rel="noopener">Manage estimators</a> &nbsp;|&nbsp; <a href="%s" target="_blank" rel="noopener">Settings</a><br><span style="opacity:.75">Pricing, colors, and form fields are edited on the estimator. Only published estimators are listed.</span>',
					esc_url( admin_url( 'edit.php?post_type=nse_estimator' ) ),
					esc_url( admin_url( 'edit.php?post_type=nse_estimator&page=pe-settings' ) )
				),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'       => 'Layout',
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					''       => 'Use the estimator setting',
					'mobile' => 'Step by step on phones',
					'always' => 'Step by step on all screens',
					'single' => 'Single page',
				),
				'default'     => '',
				'separator'   => 'before',
				'description' => '"Phones" also applies when this widget sits in a narrow column.',
			)
		);

		$this->add_control(
			'auto_advance',
			array(
				'label'       => 'Auto advance',
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					''    => 'Use the estimator setting',
					'on'  => 'On: move to the next step after a pick',
					'off' => 'Off: visitors tap Next',
				),
				'default'     => '',
				'condition'   => array( 'layout!' => 'single' ),
			)
		);

		$this->end_controls_section();

		/* ---------- Style ---------- */
		$this->start_controls_section(
			'section_design',
			array(
				'label' => 'Design overrides',
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'design_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Optional. Anything left blank keeps the estimator\'s own design.',
				'content_classes' => 'elementor-descriptor',
			)
		);

		$colors = array(
			'accent_color' => array( 'Accent', '--nse-accent', 'Buttons, selected options, and the price bar.' ),
			'on_accent'    => array( 'Text on accent', '--nse-on-accent', 'Set this if you change the accent and need a different text color on it.' ),
			'text_color'   => array( 'Text', '--nse-ink', '' ),
			'muted_color'  => array( 'Secondary text', '--nse-muted', '' ),
			'bg_color'     => array( 'Background', '--nse-bg', '' ),
			'border_color' => array( 'Borders', '--nse-line', '' ),
			'soft_color'   => array( 'Selected option background', '--nse-soft', '' ),
		);
		foreach ( $colors as $key => $c ) {
			$this->add_control(
				$key,
				array(
					'label'       => $c[0],
					'type'        => Controls_Manager::COLOR,
					'description' => $c[2],
					/* !important beats the estimator's own inline design variables. */
					'selectors'   => array( '{{WRAPPER}} .nse' => $c[1] . ': {{VALUE}} !important;' ),
				)
			);
		}

		$this->add_responsive_control(
			'radius',
			array(
				'label'      => 'Corner radius',
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}} .nse' => '--nse-radius: {{SIZE}}{{UNIT}} !important;' ),
			)
		);

		$this->add_responsive_control(
			'max_width',
			array(
				'label'      => 'Max width',
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array( 'min' => 320, 'max' => 1600, 'step' => 10 ),
					'%'  => array( 'min' => 30, 'max' => 100 ),
				),
				'selectors'  => array( '{{WRAPPER}} .nse' => '--nse-maxw: {{SIZE}}{{UNIT}} !important;' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'label'    => 'Typography',
				'selector' => '{{WRAPPER}} .nse',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$s  = $this->get_settings_for_display();
		$id = isset( $s['estimator_id'] ) ? absint( $s['estimator_id'] ) : 0;
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			if ( NSE_Elementor::is_editor() ) {
				echo '<div style="padding:28px;border:2px dashed #c3c4c7;border-radius:8px;text-align:center;color:#50575e;font:14px/1.5 system-ui,sans-serif"><strong>Project Estimator</strong><br>' . ( $id ? 'The chosen estimator is no longer published.' : 'Choose an estimator in the widget settings.' ) . '</div>';
			}
			return;
		}
		echo NSE_Frontend::render( $id, array(
			'layout'       => isset( $s['layout'] ) ? $s['layout'] : '',
			'auto_advance' => isset( $s['auto_advance'] ) ? $s['auto_advance'] : '',
		) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
	}
}

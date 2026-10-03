<?php
/**
 * Plugin Name:       Project Estimator
 * Description:       Instant price estimators with lead capture. Includes templates for patio covers, landscaping, artificial turf, pavers, and fencing.
 * Version:           1.4.0
 * Author:            Novoslate
 * Author URI:        https://novoslate.com
 * Text Domain:       project-estimator
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/novoslate/project-estimator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NSE_VERSION', '1.4.0' );
define( 'NSE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NSE_URL', plugin_dir_url( __FILE__ ) );

/*
 * GitHub repo used for automatic updates. Change this if the org or repo name differs.
 * For a private repo, define NSE_GITHUB_TOKEN in wp-config.php with a read-only token.
 */
if ( ! defined( 'NSE_GITHUB_REPO' ) ) {
	define( 'NSE_GITHUB_REPO', 'https://github.com/novoslate/project-estimator/' );
}

require_once NSE_PATH . 'includes/class-nse-preview.php';
require_once NSE_PATH . 'includes/class-nse-templates.php';
require_once NSE_PATH . 'includes/class-nse-config.php';
require_once NSE_PATH . 'includes/class-nse-admin.php';
require_once NSE_PATH . 'includes/class-nse-frontend.php';
require_once NSE_PATH . 'includes/class-nse-leads.php';

// Updates from GitHub releases.
require_once NSE_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
$nse_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	NSE_GITHUB_REPO,
	__FILE__,
	'project-estimator'
);
$nse_updater->getVcsApi()->enableReleaseAssets( '/project-estimator\.zip$/' );
if ( defined( 'NSE_GITHUB_TOKEN' ) && NSE_GITHUB_TOKEN ) {
	$nse_updater->setAuthentication( NSE_GITHUB_TOKEN );
}

add_action( 'init', array( 'NSE_Admin', 'register_post_types' ) );

NSE_Admin::init();
NSE_Frontend::init();
NSE_Leads::init();

register_activation_hook(
	__FILE__,
	function () {
		NSE_Admin::register_post_types();
		flush_rewrite_rules();
	}
);

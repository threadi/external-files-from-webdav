<?php
/**
 * PHPUnit bootstrap file.
 *
 * This plugin is an extension for "External files in Media Library". The tests need the main plugin, which is
 * searched in the following order:
 * 1. in the directory set in the environment variable EFML_PLUGIN_DIR.
 * 2. in the directory "external-files-in-media-library" next to this plugin (like in wp-content/plugins/).
 *
 * @package external-files-from-webdav
 */

define( 'TESTS_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'UNIT_TESTS_DATA_PLUGIN_DIR', TESTS_PLUGIN_DIR . '/tests/Data/' );

// Define WP_CORE_DIR if not already defined.
if ( ! defined( 'WP_CORE_DIR' ) ) {
	$_wp_core_dir = getenv( 'WP_CORE_DIR' );
	if ( ! $_wp_core_dir ) {
		$_wp_core_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
	}
	define( 'WP_CORE_DIR', $_wp_core_dir );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// get the directory of the main plugin "External files in Media Library".
$_efml_plugin_dir = getenv( 'EFML_PLUGIN_DIR' );
if ( ! $_efml_plugin_dir ) {
	$_efml_plugin_dir = dirname( TESTS_PLUGIN_DIR ) . '/external-files-in-media-library';
}
$_efml_plugin_dir = rtrim( $_efml_plugin_dir, '/\\' );
define( 'TESTS_EFML_PLUGIN_FILE', $_efml_plugin_dir . '/external-files-in-media-library.php' );

if ( ! file_exists( TESTS_EFML_PLUGIN_FILE ) ) {
	echo 'Could not find the plugin "External files in Media Library" in ' . $_efml_plugin_dir . '. Set its path in the environment variable EFML_PLUGIN_DIR.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

if ( ! file_exists( $_efml_plugin_dir . '/vendor/autoload.php' ) ) {
	echo 'The plugin "External files in Media Library" in ' . $_efml_plugin_dir . ' has no vendor directory. Run "composer install" there.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Return the list of active plugins with "External files in Media Library" in it.
 *
 * This plugin is only initialized if the main plugin is active.
 *
 * @param mixed $plugins The list of active plugins.
 *
 * @return array<int,string>
 */
function _efmlwd_add_main_plugin_as_active( mixed $plugins ): array {
	$plugins   = is_array( $plugins ) ? $plugins : array();
	$plugins[] = 'external-files-in-media-library/external-files-in-media-library.php';
	return array_values( array_unique( $plugins ) );
}

/**
 * Manually load the main plugin and the plugin being tested.
 */
function _manually_load_plugin() {
	// load the main plugin "External files in Media Library".
	require TESTS_EFML_PLUGIN_FILE;

	// mark the main plugin as active, as this plugin checks this before it initializes itself.
	add_filter( 'option_active_plugins', '_efmlwd_add_main_plugin_as_active' );

	// load this plugin.
	require dirname( __FILE__, 2 ) . '/external-files-from-webdav.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

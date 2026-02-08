<?php
/**
 * This file contains the main initialization object for this plugin.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ExternalFilesInMediaLibrary\Plugin\Roles;
use ExternalFilesInMediaLibrary\Services\Service_Plugin_Base;
use ExternalFilesInMediaLibrary\Services\WebDav;

/**
 * Initialize the plugin, connect all together.
 */
class Init {

	/**
	 * Instance of actual object.
	 *
	 * @var ?Init
	 */
	private static ?Init $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Init
	 */
	public static function get_instance(): Init {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// init update handling.
		Updates::get_instance()->init();

		// plugin-action.
		register_activation_hook( EFMLWD_PLUGIN, array( $this, 'activation' ) );

		// add the service.
		add_filter( 'efml_services_support', array( $this, 'add_service' ) );
		add_filter( 'efml_service_plugins', array( $this, 'remove_service_plugin' ) );
		add_filter( 'efml_configurations', array( $this, 'add_configuration' ) );

		// misc.
		add_action( 'init', array( $this, 'init_languages' ) );
	}

	/**
	 * Add the support for languages.
	 *
	 * @return void
	 */
	public function init_languages(): void {
		// load language files for pro.
		load_plugin_textdomain( 'external-files-from-webdav', false, dirname( plugin_basename( EFMLWD_PLUGIN ) ) . '/languages' );
	}

	/**
	 * Add the service to the main plugin.
	 *
	 * @param array<int,string> $services The list of services.
	 *
	 * @return array<int,string>
	 */
	public function add_service( array $services ): array {
		$services[] = 'ExternalFilesFromWebDav\WebDav';
		return $services;
	}

	/**
	 * Run during plugin activation.
	 *
	 * @return void
	 */
	public function activation(): void {
		// set the capabilities for this new service.
		Roles::get_instance()->set( array( 'administrator', 'editor' ), 'efml_cap_' . WebDav::get_instance()->get_name() );
	}

	/**
	 * Remove the service plugin from the main plugin.
	 *
	 * @param array<string,Service_Plugin_Base> $plugins List of plugins.
	 * @return array<string,Service_Plugin_Base>
	 */
	public function remove_service_plugin( array $plugins ): array {
		unset( $plugins['external-files-from-webdav'] );
		return $plugins;
	}

	/**
	 * Check if External files in media library is active:
	 * 1. in the actual blog.
	 * 2. in the global network, if multisite is used.
	 *
	 * @return bool
	 */
	public function is_parent_plugin_active(): bool {
		// set the slug.
		$slug = 'external-files-in-media-library/external-files-in-media-library.php';

		// check the actual blog.
		$is_active = in_array( $slug, (array) get_option( 'active_plugins', array() ), true );

		// bail if result is true.
		if ( $is_active ) {
			return true;
		}

		// bail if we are not in multisite.
		if ( ! is_multisite() ) {
			return false;
		}

		// get sitewide plugins.
		$sitewide_plugins = get_site_option( 'active_sitewide_plugins' );

		// bail if not list could be loaded.
		if ( ! is_array( $sitewide_plugins ) ) {
			return false;
		}

		// return the result.
		return isset( $sitewide_plugins[ $slug ] );
	}

	/**
	 * Add our own custom configuration to the list.
	 *
	 * @param array<int,string> $configurations List of configurations.
	 *
	 * @return array<int,string>
	 */
	public function add_configuration( array $configurations ): array {
		// add our custom configuration.
		$configurations[] = '\ExternalFilesFromWebDav\Plugin\Configuration';

		// return the resulting configurations.
		return $configurations;
	}
}

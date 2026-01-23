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
}

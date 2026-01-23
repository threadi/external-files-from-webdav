<?php
/**
 * This file contains the uninstall-handling for this plugin.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Uninstall this plugin.
 */
class Uninstall {

	/**
	 * Instance of actual object.
	 *
	 * @var ?Uninstall
	 */
	private static ?Uninstall $instance = null;

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
	 * @return Uninstall
	 */
	public static function get_instance(): Uninstall {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Run uninstallation of this plugin.
	 *
	 * Hint:
	 * First set all settings as if the plugin is active.
	 * Then delete all these settings from DB and disable all.
	 *
	 * @return void
	 */
	public function run(): void {
		// TODO to add.
	}
}

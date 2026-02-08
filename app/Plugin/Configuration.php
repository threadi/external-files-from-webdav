<?php
/**
 * File to handle a configuration preset to use only AWS S3.
 *
 * @package external-files-in-media-library
 */

namespace ExternalFilesFromWebDav\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ExternalFilesFromAwsS3\AwsS3;
use ExternalFilesFromWebDav\WebDav;
use ExternalFilesInMediaLibrary\Plugin\Configuration_Base;
use ExternalFilesInMediaLibrary\Services\Services;

/**
 * Object for the standard mode.
 */
class Configuration extends Configuration_Base {

	/**
	 * Name of this object.
	 *
	 * @var string
	 */
	protected string $name = 'webdav';

	/**
	 * Initialize this object.
	 */
	public function __construct() {}

	/**
	 * Return the title of this object.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Use only WebDav', 'external-files-in-media-library' );
	}

	/**
	 * Return additional hints for the dialog to set this mode.
	 *
	 * @return array<int,string>
	 */
	public function get_dialog_hints(): array {
		return array(
			'<p>' . __( 'This will disable all other services except WebDav.', 'external-files-in-media-library' ) . '<br>' . __( 'After that, you will only be able to see and use WebDav for external sources.', 'external-files-in-media-library' ) . '</p>',
		);
	}

	/**
	 * Save the configuration this mode defines.
	 *
	 * @return void
	 */
	public function run(): void {
		// loop through all services and disable them - except our own.
		foreach ( Services::get_instance()->get_services_as_objects() as $service_obj ) {
			// bail if method for the name does not exist.
			if ( ! method_exists( $service_obj, 'get_name' ) ) {
				continue;
			}

			// bail if this is our service.
			if ( $service_obj->get_name() === WebDav::get_instance()->get_name() ) {
				update_option( 'eml_service_' . $service_obj->get_name() . '_allowed_roles', WebDav::get_instance()->get_default_roles() );
				continue;
			}

			// remove any capability to use this service to hide it.
			update_option( 'eml_service_' . $service_obj->get_name() . '_allowed_roles', array() );
		}

		// disable hints for other plugins.
		update_option( 'eml_disable_plugin_hints', 1 );
	}
}

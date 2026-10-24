<?php
/**
 * Tests for class ExternalFilesFromWebDav\Plugin\Configuration.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\Plugin;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\WebDav;
use ExternalFilesInMediaLibrary\Services\Services;

/**
 * Object to test functions in class ExternalFilesFromWebDav\Plugin\Configuration.
 */
class Configuration extends externalFilesFromWebDavTests {
	/**
	 * Test the name, title and hints of the configuration.
	 *
	 * @return void
	 */
	public function test_configuration(): void {
		$configuration = new \ExternalFilesFromWebDav\Plugin\Configuration();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Plugin\Configuration_Base::class, $configuration );
		$this->assertEquals( 'webdav', $configuration->get_name() );
		$this->assertEquals( 'Use only WebDav', $configuration->get_title() );
		$this->assertCount( 1, $configuration->get_dialog_hints() );
		$this->assertStringContainsString( 'WebDav', $configuration->get_dialog_hints()[0] );
	}

	/**
	 * Test if the configuration allows only WebDAV and disables all other services.
	 *
	 * @return void
	 */
	public function test_run(): void {
		update_option( 'eml_disable_plugin_hints', 0 );

		( new \ExternalFilesFromWebDav\Plugin\Configuration() )->run();

		// WebDAV is allowed for its default roles.
		$this->assertEquals( WebDav::get_instance()->get_default_roles(), get_option( 'eml_service_webdav_allowed_roles' ) );

		// all other services are disabled.
		foreach ( Services::get_instance()->get_services_as_objects() as $service_obj ) {
			if ( ! method_exists( $service_obj, 'get_name' ) || 'webdav' === $service_obj->get_name() ) {
				continue;
			}
			$this->assertEquals( array(), get_option( 'eml_service_' . $service_obj->get_name() . '_allowed_roles' ), 'Service ' . $service_obj->get_name() . ' is not disabled.' );
		}

		// the hints for other plugins are disabled.
		$this->assertEquals( 1, absint( get_option( 'eml_disable_plugin_hints' ) ) );
	}
}

<?php
/**
 * Tests for class ExternalFilesFromWebDav\Plugin\Init.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\Plugin;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\WebDav;

/**
 * Object to test functions in class ExternalFilesFromWebDav\Plugin\Init.
 */
class Init extends externalFilesFromWebDavTests {
	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = \ExternalFilesFromWebDav\Plugin\Init::get_instance();
		$this->assertInstanceOf( \ExternalFilesFromWebDav\Plugin\Init::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromWebDav\Plugin\Init::get_instance() );
	}

	/**
	 * Test if the hooks of this plugin are registered during the initialization.
	 *
	 * @return void
	 */
	public function test_init(): void {
		$init = \ExternalFilesFromWebDav\Plugin\Init::get_instance();
		$this->assertIsInt( has_filter( 'efml_services_support', array( $init, 'add_service' ) ) );
		$this->assertIsInt( has_filter( 'efml_service_plugins', array( $init, 'remove_service_plugin' ) ) );
		$this->assertIsInt( has_filter( 'efml_configurations', array( $init, 'add_configuration' ) ) );
		$this->assertIsInt( has_action( 'init', array( $init, 'init_languages' ) ) );
		$this->assertIsInt( has_filter( 'update_plugins_github.com', array( \ExternalFilesFromWebDav\Plugin\Updates::get_instance(), 'check' ) ) );
	}

	/**
	 * Test if the check for the main plugin is working.
	 *
	 * @return void
	 */
	public function test_is_parent_plugin_active(): void {
		$init = \ExternalFilesFromWebDav\Plugin\Init::get_instance();

		// test 1: the main plugin is active (the bootstrap marks it as active).
		$this->assertTrue( $init->is_parent_plugin_active() );

		// test 2: the main plugin is not active.
		remove_filter( 'option_active_plugins', '_efmlwd_add_main_plugin_as_active' );
		update_option( 'active_plugins', array( 'hello-dolly/hello.php' ) );
		$this->assertFalse( $init->is_parent_plugin_active() );

		// test 3: the main plugin is active.
		update_option( 'active_plugins', array( 'hello-dolly/hello.php', 'external-files-in-media-library/external-files-in-media-library.php' ) );
		$this->assertTrue( $init->is_parent_plugin_active() );

		// restore the filter of the bootstrap.
		add_filter( 'option_active_plugins', '_efmlwd_add_main_plugin_as_active' );
	}

	/**
	 * Test if the service is added to the list of services.
	 *
	 * @return void
	 */
	public function test_add_service(): void {
		$services = \ExternalFilesFromWebDav\Plugin\Init::get_instance()->add_service( array( 'Example\Service' ) );
		$this->assertEquals( array( 'Example\Service', 'ExternalFilesFromWebDav\WebDav' ), $services );
		$this->assertTrue( class_exists( $services[1] ) );
	}

	/**
	 * Test if the main plugin knows the service of this plugin.
	 *
	 * @return void
	 */
	public function test_services_in_main_plugin(): void {
		$services = \ExternalFilesInMediaLibrary\Services\Services::get_instance()->get_services_as_objects();
		$this->assertContains( WebDav::get_instance(), $services );
	}

	/**
	 * Test if this plugin is removed from the list of service plugins of the main plugin.
	 *
	 * @return void
	 */
	public function test_remove_service_plugin(): void {
		$plugins = array(
			'external-files-from-webdav' => 'webdav',
			'other-plugin'               => 'other',
		);
		$result  = \ExternalFilesFromWebDav\Plugin\Init::get_instance()->remove_service_plugin( $plugins ); // @phpstan-ignore argument.type
		$this->assertArrayNotHasKey( 'external-files-from-webdav', $result );
		$this->assertArrayHasKey( 'other-plugin', $result );
	}

	/**
	 * Test if the configuration is added.
	 *
	 * @return void
	 */
	public function test_add_configuration(): void {
		$configurations = \ExternalFilesFromWebDav\Plugin\Init::get_instance()->add_configuration( array( 'Example\Configuration' ) );
		$this->assertCount( 2, $configurations );
		$this->assertContains( '\ExternalFilesFromWebDav\Plugin\Configuration', $configurations );
		$this->assertTrue( class_exists( $configurations[1] ) );
	}

	/**
	 * Test if the activation adds the capability for WebDAV to the default roles.
	 *
	 * @return void
	 */
	public function test_activation(): void {
		get_role( 'administrator' )->remove_cap( 'efml_cap_webdav' );
		get_role( 'editor' )->remove_cap( 'efml_cap_webdav' );

		\ExternalFilesFromWebDav\Plugin\Init::get_instance()->activation();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'efml_cap_webdav' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'efml_cap_webdav' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'efml_cap_webdav' ) );
	}

	/**
	 * Test if the plugin is registered for the WP Consent API.
	 *
	 * @return void
	 */
	public function test_register_consent_api(): void {
		$this->assertTrue( \ExternalFilesFromWebDav\Plugin\Init::get_instance()->register_consent_api() );
	}
}

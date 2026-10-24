<?php
/**
 * Tests for class ExternalFilesFromWebDav\Plugin\Uninstall.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\Plugin;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;

/**
 * Object to test functions in class ExternalFilesFromWebDav\Plugin\Uninstall.
 */
class Uninstall extends externalFilesFromWebDavTests {
	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = \ExternalFilesFromWebDav\Plugin\Uninstall::get_instance();
		$this->assertInstanceOf( \ExternalFilesFromWebDav\Plugin\Uninstall::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromWebDav\Plugin\Uninstall::get_instance() );
	}

	/**
	 * Test if the uninstallation removes the cached release data.
	 *
	 * @return void
	 */
	public function test_run(): void {
		set_site_transient( 'efmlwd_latest_release', array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );

		\ExternalFilesFromWebDav\Plugin\Uninstall::get_instance()->run();

		$this->assertFalse( get_site_transient( 'efmlwd_latest_release' ) );
	}
}

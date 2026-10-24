<?php
/**
 * Tests for class ExternalFilesFromWebDav\Plugin\Updates.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\Plugin;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;

/**
 * Object to test functions in class ExternalFilesFromWebDav\Plugin\Updates.
 */
class Updates extends externalFilesFromWebDavTests {
	/**
	 * The URL of the GitHub API for the latest release.
	 *
	 * @var string
	 */
	private string $api_url = 'https://api.github.com/repos/threadi/external-files-from-webdav/releases/latest';

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		\ExternalFilesFromWebDav\Plugin\Updates::get_instance()->delete_cache();
	}

	/**
	 * Return the release data like the GitHub API returns them.
	 *
	 * @param string                          $tag_name The tag name.
	 * @param array<int,array<string,string>> $assets   The assets of the release.
	 *
	 * @return string
	 */
	private function get_release_json( string $tag_name, array $assets ): string {
		return (string) wp_json_encode(
			array(
				'tag_name' => $tag_name,
				'assets'   => $assets,
			)
		);
	}

	/**
	 * Return a ZIP asset like the GitHub API returns it.
	 *
	 * @param string $version The version.
	 *
	 * @return array<string,string>
	 */
	private function get_zip_asset( string $version ): array {
		return array(
			'content_type'         => 'application/zip',
			'browser_download_url' => 'https://github.com/threadi/external-files-from-webdav/releases/download/' . $version . '/external-files-from-webdav_' . $version . '.zip',
		);
	}

	/**
	 * Return the result of the update check for this plugin.
	 *
	 * @return array<string,mixed>|false
	 */
	private function check(): array|false {
		return \ExternalFilesFromWebDav\Plugin\Updates::get_instance()->check( false, array(), plugin_basename( EFMLWD_PLUGIN ) );
	}

	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = \ExternalFilesFromWebDav\Plugin\Updates::get_instance();
		$this->assertInstanceOf( \ExternalFilesFromWebDav\Plugin\Updates::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromWebDav\Plugin\Updates::get_instance() );
	}

	/**
	 * Test if the plugin header "Update URI" matches the used hook, so WordPress asks this plugin for updates.
	 *
	 * @return void
	 */
	public function test_update_uri(): void {
		$plugin_data = get_plugin_data( EFMLWD_PLUGIN, false, false );
		$this->assertEquals( 'https://github.com/threadi/external-files-from-webdav', $plugin_data['UpdateURI'] );
		$this->assertEquals( 'github.com', wp_parse_url( $plugin_data['UpdateURI'], PHP_URL_HOST ) );
		$this->assertIsInt( has_filter( 'update_plugins_github.com', array( \ExternalFilesFromWebDav\Plugin\Updates::get_instance(), 'check' ) ) );
	}

	/**
	 * Test the update data for a release on GitHub.
	 *
	 * @return void
	 */
	public function test_check(): void {
		$this->add_http_response( $this->api_url, 200, array( 'Content-Type' => 'application/json' ), $this->get_release_json( 'v99.0.0', array( array( 'content_type' => 'text/plain', 'browser_download_url' => 'https://example.com/readme.txt' ), $this->get_zip_asset( '99.0.0' ) ) ) );

		$update = $this->check();

		$this->assertIsArray( $update );
		$this->assertEquals( '99.0.0', $update['version'] );
		$this->assertEquals( 'external-files-from-webdav', $update['slug'] );
		$this->assertStringEndsWith( 'external-files-from-webdav_99.0.0.zip', $update['package'] );
		$this->assertEquals( '8.2', $update['requires_php'] );
		$this->assertStringEndsWith( '/gfx/logo.png', $update['icons']['default'] );
		$this->assertStringNotContainsString( '//gfx', $update['icons']['default'] );

		// the GitHub API is only requested once, as the result is cached.
		$this->assertEquals( $update, $this->check() );
		$this->assertCount( 1, $this->get_http_requests() );
		$this->assertEquals( 'application/vnd.github+json', $this->get_http_requests()[0]['args']['headers']['Accept'] );
	}

	/**
	 * Test if the data of other plugins are not changed.
	 *
	 * @return void
	 */
	public function test_check_for_other_plugin(): void {
		$other = array( 'version' => '1.0.0' );
		$this->assertSame( $other, \ExternalFilesFromWebDav\Plugin\Updates::get_instance()->check( $other, array(), 'other-plugin/other-plugin.php' ) );
		$this->assertFalse( \ExternalFilesFromWebDav\Plugin\Updates::get_instance()->check( false, array(), 'other-plugin/other-plugin.php' ) );
		$this->assertEmpty( $this->get_http_requests() );
	}

	/**
	 * Test if nothing is returned if GitHub does not answer with HTTP status 200, and if this is cached.
	 *
	 * @return void
	 */
	public function test_check_with_error_from_github(): void {
		$this->add_http_response( $this->api_url, 403 );

		$this->assertFalse( $this->check() );
		$this->assertFalse( $this->check() );
		$this->assertCount( 1, $this->get_http_requests() );
	}

	/**
	 * Test if nothing is returned if the release does not contain a ZIP file.
	 *
	 * @return void
	 */
	public function test_check_without_zip_asset(): void {
		// test 1: without assets.
		$this->add_http_response( $this->api_url, 200, array(), $this->get_release_json( 'v99.0.0', array() ) );
		$this->assertFalse( $this->check() );

		// test 2: without ZIP asset.
		\ExternalFilesFromWebDav\Plugin\Updates::get_instance()->delete_cache();
		$this->add_http_response( $this->api_url, 200, array(), $this->get_release_json( 'v99.0.0', array( array( 'content_type' => 'text/plain', 'browser_download_url' => 'https://example.com/readme.txt' ) ) ) );
		$this->assertFalse( $this->check() );
	}

	/**
	 * Test if nothing is returned if GitHub answers with invalid JSON.
	 *
	 * @return void
	 */
	public function test_check_with_invalid_json(): void {
		$this->add_http_response( $this->api_url, 200, array(), 'no json' );
		$this->assertFalse( $this->check() );
	}

	/**
	 * Test if WordPress uses the update data for this plugin.
	 *
	 * @return void
	 */
	public function test_update_in_wordpress(): void {
		$this->add_http_response( $this->api_url, 200, array(), $this->get_release_json( '2.0.0', array( $this->get_zip_asset( '2.0.0' ) ) ) );

		// run the hook like wp_update_plugins() does it.
		$plugin_file = plugin_basename( EFMLWD_PLUGIN );
		$update      = apply_filters( 'update_plugins_github.com', false, get_plugin_data( EFMLWD_PLUGIN, false, false ), $plugin_file, array() );

		$this->assertIsArray( $update );
		$this->assertEquals( '2.0.0', $update['version'] );
	}
}

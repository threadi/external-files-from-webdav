<?php
/**
 * Tests for the complete connection test of WebDAV.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\WebDav;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\WebDav;

/**
 * Object to test the connection test of WebDAV.
 */
class ConnectionTest extends externalFilesFromWebDavTests {
	/**
	 * The name of the test file.
	 *
	 * @var string
	 */
	private string $file_name = 'efml-connection-test.txt';

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'efml_connection_test_file_name', array( $this, 'get_file_name' ) );
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'efml_connection_test_file_name', array( $this, 'get_file_name' ) );
		WebDav::get_instance()->set_connection_test_directory( '' );
		parent::tear_down();
	}

	/**
	 * Return the name of the test file.
	 *
	 * @return string
	 */
	public function get_file_name(): string {
		return $this->file_name;
	}

	/**
	 * Run the connection test in the given directory and return the steps.
	 *
	 * @param string $directory The directory.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function run_test( string $directory ): array {
		$webdav = $this->get_webdav_with_fields();
		$webdav->set_connection_test_directory( $directory );
		return $webdav->run_connection_test();
	}

	/**
	 * Test the connection test in a directory: all requests use this directory and the credentials.
	 *
	 * @return void
	 */
	public function test_connection_test(): void {
		$directory = $this->domain . '/remote.php/dav/files/user/Photos/';
		$this->add_webdav_response( 'PROPFIND', $directory, 207, $this->get_propfind_body( array( '/remote.php/dav/files/user/Photos/' => array( 'dir' => true ) ) ) );
		$this->add_webdav_response( 'PUT', $directory . $this->file_name, 201 );
		$this->add_webdav_response( 'DELETE', $directory . $this->file_name, 204 );

		$steps = $this->run_test( $directory );

		$this->assertTrue( WebDav::get_instance()->supports_connection_test() );
		$this->assertTrue( $steps[0]['success'], $steps[0]['message'] );
		$this->assertTrue( $steps[1]['success'], $steps[1]['message'] );
		$this->assertTrue( $steps[2]['skipped'] );
		$this->assertTrue( $steps[3]['success'], $steps[3]['message'] );
		foreach ( $this->get_webdav_requests() as $request ) {
			$this->assertEquals( 'user:secret', $request['curl'][ CURLOPT_USERPWD ] );
		}
	}

	/**
	 * Test the connection test with a server with port.
	 *
	 * @return void
	 */
	public function test_connection_test_with_port(): void {
		$directory = 'https://webdav.example.com:8443/remote.php/dav/files/user/';
		$this->add_webdav_response( 'PROPFIND', $directory, 207, $this->get_propfind_body( array( '/remote.php/dav/files/user/' => array( 'dir' => true ) ) ) );
		$this->add_webdav_response( 'PUT', $directory . $this->file_name, 201 );
		$this->add_webdav_response( 'DELETE', $directory . $this->file_name, 204 );

		$steps = $this->run_test( $directory );

		$this->assertTrue( $steps[3]['success'], $steps[3]['message'] );
	}

	/**
	 * Test the connection test with errors.
	 *
	 * @return void
	 */
	public function test_connection_test_with_errors(): void {
		$directory = $this->domain . '/remote.php/dav/files/user/';

		// the directory could not be read.
		$this->add_webdav_response( 'PROPFIND', $directory, 401 );
		$steps = $this->run_test( $directory );
		$this->assertFalse( $steps[0]['success'] );
		$this->assertTrue( $steps[1]['skipped'] );

		// the file could not be uploaded.
		$this->add_webdav_response( 'PROPFIND', $directory, 207, $this->get_propfind_body( array( '/remote.php/dav/files/user/' => array( 'dir' => true ) ) ) );
		$this->add_webdav_response( 'PUT', $directory . $this->file_name, 403 );
		$steps = $this->run_test( $directory );
		$this->assertFalse( $steps[1]['success'] );
		$this->assertTrue( $steps[3]['skipped'] );

		// the file could not be deleted.
		$this->add_webdav_response( 'PUT', $directory . $this->file_name, 201 );
		$this->add_webdav_response( 'DELETE', $directory . $this->file_name, 403 );
		$steps = $this->run_test( $directory );
		$this->assertFalse( $steps[3]['success'] );
		$this->assertStringContainsString( 'manually', $steps[3]['message'] );
	}

	/**
	 * Test the global actions offer the connection test.
	 *
	 * @return void
	 */
	public function test_global_actions(): void {
		$actions = $this->call_method( $this->get_webdav_with_fields(), 'get_global_actions' );
		$this->assertContains( 'Run connection test', array_column( $actions, 'label' ) );
	}
}

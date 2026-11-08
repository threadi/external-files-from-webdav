<?php
/**
 * Tests for the usage of constants for the credentials of WebDAV.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\WebDav;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\WebDav;

/**
 * Object to test the usage of constants for the credentials of WebDAV.
 *
 * The constants of PHP could not be removed after they have been defined. Therefore, each test
 * uses its own prefix for the constant names via the hook "efml_service_field_constant_name".
 */
class Constants extends externalFilesFromWebDavTests {
	/**
	 * The prefix for the constants of the actual test.
	 *
	 * @var string
	 */
	private string $prefix = '';

	/**
	 * Counter for unique prefixes.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// bail if the main plugin does not support constants.
		if ( ! method_exists( WebDav::get_instance(), 'get_constant_values' ) ) {
			$this->markTestSkipped( 'Requires "External Files in Media Library" 5.7.0 or newer.' );
		}

		// use a unique prefix for the constants of this test.
		++self::$counter;
		$this->prefix = 'EFMLWDTEST' . self::$counter . '_';
		add_filter( 'efml_service_field_constant_name', array( $this, 'set_constant_name' ) );
	}

	/**
	 * Reset the environment after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'efml_service_field_constant_name', array( $this, 'set_constant_name' ) );
		parent::tear_down();
	}

	/**
	 * Use the prefix of the actual test for the constant names.
	 *
	 * @param string $constant_name The name of the constant.
	 *
	 * @return string
	 */
	public function set_constant_name( string $constant_name ): string {
		return $this->prefix . $constant_name;
	}

	/**
	 * Define the constants for the test server.
	 *
	 * @return void
	 */
	private function define_constants(): void {
		define( $this->prefix . 'EFML_WEBDAV_SERVER', $this->domain );
		define( $this->prefix . 'EFML_WEBDAV_LOGIN', 'constant-user' );
		define( $this->prefix . 'EFML_WEBDAV_PASSWORD', 'constant-secret' );
	}

	/**
	 * Test that the path is not required.
	 *
	 * @return void
	 */
	public function test_required_fields(): void {
		$this->assertEquals( array( 'server', 'login', 'password' ), WebDav::get_instance()->get_required_fields_for_constants() );
	}

	/**
	 * Test the fields and the directory with constants.
	 *
	 * @return void
	 */
	public function test_fields_with_constants(): void {
		$this->define_constants();

		$webdav = WebDav::get_instance();
		$webdav->set_fields( array() );
		$fields = $webdav->get_fields();
		$this->assertEquals( 'constant-user', $fields['login']['value'] );
		$this->assertEquals( 'constant-secret', $fields['password']['value'] );
		$this->assertEquals( $this->domain, $webdav->get_directory() );
	}

	/**
	 * Test the export to an external source without saved credentials: the constants are used.
	 *
	 * @return void
	 */
	public function test_export_with_constants(): void {
		$this->define_constants();

		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_webdav_response( 'PUT', $this->domain . '/remote.php/dav/files/constant-user/' . $file_name, 201 );

		$credentials = array(
			'fields' => array(
				'server'   => array( 'value' => '' ),
				'login'    => array( 'value' => '' ),
				'password' => array( 'value' => '' ),
				'path'     => array( 'value' => '/remote.php/dav/files/' ),
			),
		);
		$url         = WebDav\Export::get_instance()->export_file( $attachment_id, trailingslashit( $this->domain ) . $file_name, $credentials );
		$this->assertEquals( $this->domain . '/remote.php/dav/files/constant-user/' . $file_name, $url );

		$requests = $this->get_webdav_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( 'constant-user:constant-secret', $requests[0]['curl'][ CURLOPT_USERPWD ] );
	}

	/**
	 * Test that the constants are never used for other servers.
	 *
	 * @return void
	 */
	public function test_no_constants_for_other_server(): void {
		$this->define_constants();

		$fields = WebDav::get_instance()->get_fields_for_url(
			array(
				'login'    => array( 'value' => 'own-user' ),
				'password' => array( 'value' => 'own-secret' ),
			),
			'https://other.example.com/file.jpg'
		);
		$this->assertEquals( 'own-user', $fields['login']['value'] );
		$this->assertEquals( 'own-secret', $fields['password']['value'] );
		$this->assertTrue( WebDav::get_instance()->is_url_blocked_by_constants( 'https://other.example.com/' ) );
		$this->assertFalse( WebDav::get_instance()->is_url_blocked_by_constants( $this->domain . '/remote.php/' ) );
	}
}

<?php
/**
 * Tests for class ExternalFilesFromWebDav\WebDav\Protocol.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\WebDav;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesInMediaLibrary\ExternalFiles\Import;
use ExternalFilesInMediaLibrary\ExternalFiles\Protocols;
use ExternalFilesInMediaLibrary\ExternalFiles\Results;
use Sabre\HTTP\Response;

/**
 * Object to test functions in class ExternalFilesFromWebDav\WebDav\Protocol.
 */
class Protocol extends externalFilesFromWebDavTests {
	/**
	 * List of temporary files created during the actual test.
	 *
	 * @var array<int,string>
	 */
	private array $tmp_files = array();

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->tmp_files as $tmp_file ) {
			if ( is_string( $tmp_file ) && file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
		}
		$this->tmp_files = array();
		Import::get_instance()->set_fields( array() );
		parent::tear_down();
	}

	/**
	 * Return the protocol object for the given URL with the fields of the tests.
	 *
	 * @param string $url The URL.
	 *
	 * @return \ExternalFilesFromWebDav\WebDav\Protocol
	 */
	private function get_protocol( string $url ): \ExternalFilesFromWebDav\WebDav\Protocol {
		$protocol = new \ExternalFilesFromWebDav\WebDav\Protocol( $url );
		$protocol->set_fields( $this->get_webdav_with_fields()->get_fields() );
		return $protocol;
	}

	/**
	 * Return the content of the test file.
	 *
	 * @return string
	 */
	private function get_file_content(): string {
		return (string) file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Return the error texts of the results of the actual import.
	 *
	 * @return array<int,string>
	 */
	private function get_result_texts(): array {
		$texts = array();
		foreach ( Results::get_instance()->get_results() as $result ) {
			if ( method_exists( $result, 'get_result_text' ) ) {
				$texts[] = $result->get_result_text();
			}
		}
		return $texts;
	}

	/**
	 * Test the name and the title of the protocol.
	 *
	 * @return void
	 */
	public function test_get_name_and_title(): void {
		$protocol = $this->get_protocol( $this->domain . '/file.jpg' );
		$this->assertEquals( 'webdav', $protocol->get_name() );
		$this->assertEquals( 'WebDAV', $protocol->get_title() );
	}

	/**
	 * Test the general settings of this protocol.
	 *
	 * @return void
	 */
	public function test_settings(): void {
		$protocol = $this->get_protocol( $this->domain . '/file.jpg' );
		$this->assertTrue( $protocol->is_available() );
		$this->assertTrue( $protocol->should_be_saved_local() );
		$this->assertFalse( $protocol->can_change_hosting() );
		$this->assertFalse( $protocol->can_check_availability() );
		$this->assertTrue( $protocol->check_url( $this->domain . '/file.jpg' ) );
		$this->assertFalse( $protocol->check_url( '' ) );
	}

	/**
	 * Test which requests are compatible with this protocol.
	 *
	 * @return void
	 */
	public function test_is_url_compatible(): void {
		$protocol = $this->get_protocol( $this->domain . '/file.jpg' );

		// test 1: without request and import fields.
		$this->assertFalse( $protocol->is_url_compatible() );

		// test 2: an import with the fields of WebDAV.
		Import::get_instance()->set_fields( $this->get_webdav_with_fields()->get_fields() );
		$this->assertTrue( $protocol->is_url_compatible() );

		// test 3: an import with other fields.
		Import::get_instance()->set_fields( array( 'login' => array( 'value' => 'user' ) ) );
		$this->assertFalse( $protocol->is_url_compatible() );
	}

	/**
	 * Test if the main plugin uses this protocol for imports with WebDAV.
	 *
	 * @return void
	 */
	public function test_get_protocol_object_for_url(): void {
		// the protocol is only available for users with the capability for WebDAV.
		$this->do_login();
		\ExternalFilesFromWebDav\WebDav::get_instance()->init();
		$this->assertContains( 'ExternalFilesFromWebDav\WebDav\Protocol', Protocols::get_instance()->get_protocols() );

		// the protocol is used for an import with the fields of WebDAV.
		$fields = $this->get_webdav_with_fields()->get_fields();
		Import::get_instance()->set_fields( $fields );
		$this->assertInstanceOf( \ExternalFilesFromWebDav\WebDav\Protocol::class, Protocols::get_instance()->get_protocol_object_for_url( $this->domain . '/remote.php/dav/files/user/example.jpg', $fields ) );
	}

	/**
	 * Test the import of a single file.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_file(): void {
		$url = $this->domain . '/remote.php/dav/files/user/my%20example.jpg';
		$this->add_webdav_response( 'PROPFIND', $url, 207, $this->get_propfind_body( array( '/remote.php/dav/files/user/my%20example.jpg' => array( 'size' => 706, 'type' => 'image/jpeg' ) ) ) );
		$this->add_webdav_response( 'GET', $url, 200, $this->get_file_content() );

		$results = $this->get_protocol( $url )->get_url_infos();

		$this->assertCount( 1, $results );
		$file              = $results[0];
		$this->tmp_files[] = $file['tmp-file'];
		$this->assertEquals( 'my example.jpg', $file['title'] );
		$this->assertTrue( $file['local'] );
		$this->assertEquals( $url, $file['url'] );
		$this->assertEquals( 706, $file['filesize'] );
		$this->assertEquals( 'image/jpeg', $file['mime-type'] );
		$this->assertEquals( strtotime( 'Tue, 02 Jan 2024 03:04:05 GMT' ), $file['last-modified'] );
		$this->assertFileExists( $file['tmp-file'] );
		$this->assertEquals( $this->get_file_content(), file_get_contents( $file['tmp-file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// both requests use the credentials.
		foreach ( $this->get_webdav_requests() as $request ) {
			$this->assertEquals( 'user:secret', $request['curl'][ CURLOPT_USERPWD ] );
		}
	}

	/**
	 * Test the import of a directory.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_directory(): void {
		$url = $this->domain . '/remote.php/dav/files/user/Photos/';
		$this->add_webdav_response(
			'PROPFIND',
			$url,
			207,
			$this->get_propfind_body(
				array(
					'/remote.php/dav/files/user/Photos/'          => array( 'dir' => true ),
					'/remote.php/dav/files/user/Photos/a.jpg'     => array( 'size' => 706, 'type' => 'image/jpeg' ),
					'/remote.php/dav/files/user/Photos/b.jpg'     => array( 'size' => 706, 'type' => 'image/jpeg' ),
					'/remote.php/dav/files/user/Photos/Holidays/' => array( 'dir' => true ),
				)
			)
		);
		$this->add_webdav_response( 'GET', $url . 'a.jpg', 200, $this->get_file_content() );
		$this->add_webdav_response( 'GET', $url . 'b.jpg', 200, $this->get_file_content() );

		// collect the files, which are announced via hook.
		$announced = array();
		$callback  = static function ( string $directory, array $files ) use ( &$announced ): void {
			$announced = array_keys( $files );
		};
		add_action( 'efmlwd_directory_import_files', $callback, 10, 2 );
		$results = $this->get_protocol( $url )->get_url_infos();
		remove_action( 'efmlwd_directory_import_files', $callback );

		// only the files are imported, not the directories.
		$this->assertEquals( array( 'a.jpg', 'b.jpg' ), array_column( $results, 'title' ) );
		$this->assertEquals( array( $url . 'a.jpg', $url . 'b.jpg' ), array_column( $results, 'url' ) );
		$this->assertCount( 4, $announced );
		foreach ( $results as $file ) {
			$this->tmp_files[] = $file['tmp-file'];
			$this->assertFileExists( $file['tmp-file'] );
		}
	}

	/**
	 * Test if files, which already exist in the media library, are not imported again.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_duplicate(): void {
		$url           = $this->domain . '/remote.php/dav/files/user/example.jpg';
		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment_id, EFML_POST_META_URL, $url );
		$this->add_webdav_response( 'PROPFIND', $url, 207, $this->get_propfind_body( array( '/remote.php/dav/files/user/example.jpg' => array( 'size' => 706 ) ) ) );

		$this->assertEquals( array(), $this->get_protocol( $url )->get_url_infos() );

		// the file is not loaded.
		$this->assertCount( 1, $this->get_webdav_requests() );

		// the duplicate is logged.
		$log = $this->get_log_entries( $url );
		$this->assertNotEmpty( $log );
		$this->assertStringContainsString( 'already exist', $log[0]['log'] );
	}

	/**
	 * Test if files can be hidden during the import via hook.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_hidden_files(): void {
		$url = $this->domain . '/remote.php/dav/files/user/';
		$this->add_webdav_response(
			'PROPFIND',
			$url,
			207,
			$this->get_propfind_body(
				array(
					'/remote.php/dav/files/user/example.jpg' => array( 'size' => 706 ),
					'/remote.php/dav/files/user/example.exe' => array( 'size' => 706 ),
				)
			)
		);
		$this->add_webdav_response( 'GET', $url . 'example.jpg', 200, $this->get_file_content() );

		// hide not supported files.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 1 );
		add_filter( 'efmlwd_service_webdav_hide_file', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'prevent_not_allowed_files' ), 10, 3 );
		$results = $this->get_protocol( $url )->get_url_infos();

		$this->assertEquals( array( 'example.jpg' ), array_column( $results, 'title' ) );
		$this->tmp_files[] = $results[0]['tmp-file'];
	}

	/**
	 * Test the import with an error from the WebDAV server.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_error(): void {
		$url = $this->domain . '/remote.php/dav/files/user/example.jpg';
		$this->add_webdav_response( 'PROPFIND', $url, 401 );

		$this->assertEquals( array(), $this->get_protocol( $url )->get_url_infos() );
		$this->assertContains( 'Error occurred during requesting the directory listing.', $this->get_result_texts() );

		$log = $this->get_log_entries( $url );
		$this->assertNotEmpty( $log );
		$this->assertEquals( 'error', $log[0]['state'] );
	}

	/**
	 * Test the import of a file, which does not exist.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_missing_file(): void {
		$url  = $this->domain . '/remote.php/dav/files/user/missing.jpg';
		$body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/user/missing.jpg</d:href><d:propstat><d:prop><d:getcontentlength/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response></d:multistatus>';
		$this->add_webdav_response( 'PROPFIND', $url, 207, $body );

		$this->assertEquals( array(), $this->get_protocol( $url )->get_url_infos() );
		$this->assertContains( 'Got empty response from WebDAV for given file.', $this->get_result_texts() );
	}

	/**
	 * Test the import of files, which could not be loaded.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_error_for_single_file(): void {
		$url = $this->domain . '/remote.php/dav/files/user/';
		$this->add_webdav_response(
			'PROPFIND',
			$url,
			207,
			$this->get_propfind_body(
				array(
					'/remote.php/dav/files/user/a.jpg' => array( 'size' => 706 ),
					'/remote.php/dav/files/user/b.jpg' => array( 'size' => 706 ),
				)
			)
		);
		$this->add_webdav_response( 'GET', $url . 'a.jpg', 403 );
		$this->add_webdav_response( 'GET', $url . 'b.jpg', 200, $this->get_file_content() );

		$results = $this->get_protocol( $url )->get_url_infos();

		// only the loaded file is returned.
		$this->assertEquals( array( 'b.jpg' ), array_column( $results, 'title' ) );
		$this->tmp_files[] = $results[0]['tmp-file'];

		// the failed file is logged.
		$log = $this->get_log_entries( $url );
		$this->assertNotEmpty( $log );
		$this->assertStringContainsString( '1 file could not be loaded', $log[0]['log'] );
		$this->assertStringContainsString( 'a.jpg', $log[0]['log'] );
	}

	/**
	 * Test the import with a faulty URL.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_faulty_url(): void {
		$this->assertEquals( array(), $this->get_protocol( 'webdav.example.com/file.jpg' )->get_url_infos() );
		$this->assertContains( 'Got faulty URL.', $this->get_result_texts() );
		$this->assertEmpty( $this->get_webdav_requests() );
	}

	/**
	 * Test the infos about a single URL.
	 *
	 * @return void
	 */
	public function test_get_url_info(): void {
		$url = $this->domain . '/remote.php/dav/files/user/example.jpg';
		$this->add_webdav_response( 'PROPFIND', $url, 207, $this->get_propfind_body( array( '/remote.php/dav/files/user/example.jpg' => array( 'size' => 706, 'type' => 'image/jpeg' ) ) ) );
		$this->add_http_response( $url, 200, array( 'Content-Type' => 'image/jpeg' ), $this->get_file_content() );

		$info = $this->get_protocol( $url )->get_url_info( $url );

		$this->assertEquals( 'example.jpg', $info['title'] );
		$this->assertEquals( 706, $info['filesize'] );
		$this->assertEquals( 'image/jpeg', $info['mime-type'] );
		$this->assertEquals( $url, $info['url'] );
		$this->assertTrue( $info['local'] );
	}

	/**
	 * Test the infos about a single URL, if the WebDAV server returns an error.
	 *
	 * @return void
	 */
	public function test_get_url_info_with_error(): void {
		$url = $this->domain . '/remote.php/dav/files/user/example.jpg';
		$this->add_webdav_response( 'PROPFIND', $url, 401 );

		$this->assertEquals( array(), $this->get_protocol( $url )->get_url_info( $url ) );
	}

	/**
	 * Test the infos about a faulty URL.
	 *
	 * @return void
	 */
	public function test_get_url_info_with_faulty_url(): void {
		$this->assertEquals( array(), $this->get_protocol( 'file.jpg' )->get_url_info( 'file.jpg' ) );
		$this->assertContains( 'Got faulty URL.', $this->get_result_texts() );
	}
}

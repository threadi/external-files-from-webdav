<?php
/**
 * Tests for class ExternalFilesFromWebDav\WebDav\Export.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\WebDav;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;

/**
 * Object to test functions in class ExternalFilesFromWebDav\WebDav\Export.
 */
class Export extends externalFilesFromWebDavTests {
	/**
	 * Return the export object.
	 *
	 * @return \ExternalFilesFromWebDav\WebDav\Export
	 */
	private function get_export(): \ExternalFilesFromWebDav\WebDav\Export {
		return \ExternalFilesFromWebDav\WebDav\Export::get_instance();
	}

	/**
	 * Return the credentials like the main plugin uses them for the export.
	 *
	 * @return array<string,mixed>
	 */
	private function get_credentials(): array {
		return array( 'fields' => $this->get_webdav_with_fields()->get_fields() );
	}

	/**
	 * Return the export target like the main plugin creates it: the path of the external source and the file name.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $source        The path of the external source.
	 *
	 * @return string
	 */
	private function get_target( int $attachment_id, string $source ): string {
		return trailingslashit( $source ) . basename( (string) get_attached_file( $attachment_id, true ) );
	}

	/**
	 * Return the external sources: the main directory and a sub-directory as they are saved from the directory listing.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function sources_provider(): array {
		return array(
			'main directory' => array( 'https://webdav.example.com', '/remote.php/dav/files/user/' ),
			'sub-directory'  => array( 'https://webdav.example.com/remote.php/dav/files/user/Photos/', '/remote.php/dav/files/user/Photos/' ),
		);
	}

	/**
	 * Test if the returned object is always the same and used by the service.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$export = $this->get_export();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\Export_Base::class, $export );
		$this->assertSame( $export, \ExternalFilesFromWebDav\WebDav\Export::get_instance() );
		$this->assertSame( $export, \ExternalFilesFromWebDav\WebDav::get_instance()->get_export_object() );
	}

	/**
	 * Test the export of a file.
	 *
	 * @dataProvider sources_provider
	 *
	 * @param string $source The path of the external source.
	 * @param string $path   The expected path of the directory on the WebDAV server.
	 *
	 * @return void
	 */
	public function test_export_file( string $source, string $path ): void {
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_webdav_response( 'PUT', $this->domain . $path . $file_name, 201 );

		$url = $this->get_export()->export_file( $attachment_id, $this->get_target( $attachment_id, $source ), $this->get_credentials() );

		$this->assertEquals( $this->domain . $path . $file_name, $url );

		// check the upload.
		$requests = $this->get_webdav_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( 'user:secret', $requests[0]['curl'][ CURLOPT_USERPWD ] );
		$this->assertEquals( file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), $requests[0]['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the export of a file, if the WebDAV server returns an error.
	 *
	 * @return void
	 */
	public function test_export_file_with_error(): void {
		$attachment_id = $this->create_local_attachment();
		$target        = $this->get_target( $attachment_id, $this->domain );
		$this->add_webdav_response( 'PUT', $this->domain . '/remote.php/dav/files/user/' . basename( $target ), 403 );

		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $target, $this->get_credentials() ) );

		$log = $this->get_log_entries( $target );
		$this->assertNotEmpty( $log );
		$this->assertEquals( 'error', $log[0]['state'] );
	}

	/**
	 * Test the export of a file, if the WebDAV server returns an unexpected status.
	 *
	 * @return void
	 */
	public function test_export_file_with_unexpected_status(): void {
		$attachment_id = $this->create_local_attachment();
		$target        = $this->get_target( $attachment_id, $this->domain );
		$this->add_webdav_response( 'PUT', $this->domain . '/remote.php/dav/files/user/' . basename( $target ), 200 );

		// test 1: the status is not supported.
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $target, $this->get_credentials() ) );
		$this->assertStringContainsString( 'unsupported status code', $this->get_log_entries( $target )[0]['log'] );

		// test 2: the status is allowed via hook.
		$callback = static function ( array $status_codes ): array {
			$status_codes[] = 200;
			return $status_codes;
		};
		add_filter( 'efmlwd_service_webdav_status_codes', $callback );
		$this->assertIsString( $this->get_export()->export_file( $attachment_id, $target, $this->get_credentials() ) );
		remove_filter( 'efmlwd_service_webdav_status_codes', $callback );
	}

	/**
	 * Test the export with a target without path.
	 *
	 * @return void
	 */
	public function test_export_file_without_path(): void {
		$attachment_id = $this->create_local_attachment();
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->domain, $this->get_credentials() ) );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, 'example.jpg', $this->get_credentials() ) );
		$this->assertEmpty( $this->get_webdav_requests() );
	}

	/**
	 * Test the export of an attachment without file.
	 *
	 * @return void
	 */
	public function test_export_file_without_file(): void {
		// test 1: attachment without file.
		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->domain . '/example.jpg', $this->get_credentials() ) );

		// test 2: attachment with a not existing file.
		update_attached_file( $attachment_id, '/not/existing/example.jpg' );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->domain . '/example.jpg', $this->get_credentials() ) );
		$this->assertStringContainsString( 'does not exist', $this->get_log_entries( $this->domain . '/example.jpg' )[0]['log'] );

		$this->assertEmpty( $this->get_webdav_requests() );
	}

	/**
	 * Test the deletion of an exported file.
	 *
	 * @dataProvider sources_provider
	 *
	 * @param string $source The path of the external source.
	 * @param string $path   The expected path of the directory on the WebDAV server.
	 *
	 * @return void
	 */
	public function test_delete_exported_file( string $source, string $path ): void {
		$this->add_webdav_response( 'DELETE', $this->domain . $path . 'example.jpg', 204 );

		$this->assertTrue( $this->get_export()->delete_exported_file( trailingslashit( $source ) . 'example.jpg', $this->get_credentials(), 1 ) );
		$this->assertCount( 1, $this->get_webdav_requests() );
	}

	/**
	 * Test the deletion of a file, if the WebDAV server returns an error.
	 *
	 * @return void
	 */
	public function test_delete_exported_file_with_error(): void {
		$this->add_webdav_response( 'DELETE', $this->domain . '/remote.php/dav/files/user/example.jpg', 404 );

		$this->assertFalse( $this->get_export()->delete_exported_file( $this->domain . '/example.jpg', $this->get_credentials(), 1 ) );

		$log = $this->get_log_entries( '/remote.php/dav/files/user/example.jpg' );
		$this->assertNotEmpty( $log );
		$this->assertStringContainsString( 'Error occurred during request to delete a file', $log[0]['log'] );
	}

	/**
	 * Test the deletion of a file, if the WebDAV server returns an unexpected status.
	 *
	 * @return void
	 */
	public function test_delete_exported_file_with_unexpected_status(): void {
		$this->add_webdav_response( 'DELETE', $this->domain . '/remote.php/dav/files/user/example.jpg', 200 );

		$this->assertFalse( $this->get_export()->delete_exported_file( $this->domain . '/example.jpg', $this->get_credentials(), 1 ) );
		$this->assertStringContainsString( 'unsupported status code', $this->get_log_entries( '/remote.php/dav/files/user/example.jpg' )[0]['log'] );
	}

	/**
	 * Test the deletion with a URL without path.
	 *
	 * @return void
	 */
	public function test_delete_exported_file_without_path(): void {
		$this->assertFalse( $this->get_export()->delete_exported_file( $this->domain, $this->get_credentials(), 1 ) );
		$this->assertEmpty( $this->get_webdav_requests() );
	}
}

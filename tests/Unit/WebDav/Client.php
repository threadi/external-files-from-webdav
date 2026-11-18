<?php
/**
 * Tests for class ExternalFilesFromWebDav\WebDav\Client.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit\WebDav;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\Tests\WebDavClientMock;
use ExternalFilesFromWebDav\WebDav;
use Sabre\HTTP\ClientException;
use Sabre\HTTP\ClientHttpException;

/**
 * Object to test functions in class ExternalFilesFromWebDav\WebDav\Client.
 *
 * The client is tested against a local PHP web server, as the download and upload with streams are handled by curl.
 */
class Client extends externalFilesFromWebDavTests {
	/**
	 * The process of the local web server.
	 *
	 * @var resource|null
	 */
	private static $server = null;

	/**
	 * The URL of the local web server.
	 *
	 * @var string
	 */
	private static string $server_url = '';

	/**
	 * The file, which is delivered by the local web server.
	 *
	 * @var string
	 */
	private static string $source_file = '';

	/**
	 * The file, in which the local web server saves uploads.
	 *
	 * @var string
	 */
	private static string $upload_file = '';

	/**
	 * The size of the test file in bytes.
	 *
	 * @var int
	 */
	private const FILE_SIZE = 8 * 1024 * 1024;

	/**
	 * List of local files to delete after each test.
	 *
	 * @var array<int,string>
	 */
	private array $local_files = array();

	/**
	 * Start the local web server.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// create the test file with random content.
		self::$source_file = (string) tempnam( sys_get_temp_dir(), 'efmlwd-source' );
		self::$upload_file = (string) tempnam( sys_get_temp_dir(), 'efmlwd-upload' );
		$handle            = fopen( self::$source_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		for ( $i = 0; $i < self::FILE_SIZE / 1024; $i++ ) {
			fwrite( $handle, random_bytes( 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// get a free port.
		$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
		$name   = (string) stream_socket_get_name( $socket, false );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::$server_url = 'http://' . $name;

		// start the server.
		$env          = array_merge(
			getenv(),
			array(
				'EFMLWD_TEST_FILE'   => self::$source_file,
				'EFMLWD_TEST_UPLOAD' => self::$upload_file,
			)
		);
		$null         = array( 'file', '/dev/null', 'w' );
		self::$server = proc_open( array( PHP_BINARY, '-S', $name, UNIT_TESTS_DATA_PLUGIN_DIR . 'webdav-server.php' ), array( 1 => $null, 2 => $null ), $pipes, null, $env );

		// wait until the server answers.
		for ( $i = 0; $i < 50; $i++ ) {
			$connection = @fsockopen( '127.0.0.1', (int) substr( $name, strrpos( $name, ':' ) + 1 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_resource( $connection ) ) {
				fclose( $connection ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				break;
			}
			usleep( 100000 );
		}
	}

	/**
	 * Stop the local web server.
	 *
	 * @return void
	 */
	public static function tear_down_after_class(): void {
		if ( is_resource( self::$server ) ) {
			proc_terminate( self::$server );
			proc_close( self::$server );
		}
		wp_delete_file( self::$source_file );
		wp_delete_file( self::$upload_file );
		parent::tear_down_after_class();
	}

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// skip the tests if the local web server is not available.
		if ( ! is_resource( self::$server ) || ! proc_get_status( self::$server )['running'] ) {
			$this->markTestSkipped( 'The local web server could not be started.' );
		}
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->local_files as $file ) {
			wp_delete_file( $file );
		}
		parent::tear_down();
	}

	/**
	 * Return a client for the given path on the local web server.
	 *
	 * @param string $path The path.
	 *
	 * @return WebDav\Client
	 */
	private function get_client( string $path ): WebDav\Client {
		$client = new WebDav\Client(
			array(
				'baseUri'  => self::$server_url . $path,
				'userName' => 'user',
				'password' => 'secret',
			)
		);
		$client->setThrowExceptions( true );
		return $client;
	}

	/**
	 * Return a new local file.
	 *
	 * @return string
	 */
	private function get_local_file(): string {
		$file                = wp_tempnam();
		$this->local_files[] = $file;
		return $file;
	}

	/**
	 * Test the download of a large file into a local file: only the file is saved, not the body of the
	 * authentication challenge, and the file is not loaded into memory.
	 *
	 * @return void
	 */
	public function test_download_to_file(): void {
		$file = $this->get_local_file();

		memory_reset_peak_usage();
		$memory   = memory_get_usage();
		$response = $this->get_client( '/file.bin' )->download_to_file( '', $file );
		$peak     = memory_get_peak_usage() - $memory;

		$this->assertEquals( 200, $response->getStatus() );
		$this->assertEquals( 'file', $response->getHeader( 'X-Test' ) );
		$this->assertEquals( self::FILE_SIZE, filesize( $file ) );
		$this->assertEquals( md5_file( self::$source_file ), md5_file( $file ) );
		$this->assertLessThan( self::FILE_SIZE / 4, $peak );
	}

	/**
	 * Test the download after a redirect: the body of the redirect is not saved.
	 *
	 * @return void
	 */
	public function test_download_to_file_with_redirect(): void {
		$file = $this->get_local_file();

		$response = $this->get_client( '/redirect.bin' )->download_to_file( '', $file );

		$this->assertEquals( 200, $response->getStatus() );
		$this->assertEquals( md5_file( self::$source_file ), md5_file( $file ) );
	}

	/**
	 * Test the download of a missing file: the client throws the exception and the body of the error is not saved.
	 *
	 * @return void
	 */
	public function test_download_to_file_with_error(): void {
		$file = $this->get_local_file();

		try {
			$this->get_client( '/missing.bin' )->download_to_file( '', $file );
			$this->fail( 'No exception has been thrown.' );
		} catch ( ClientHttpException $e ) {
			$this->assertEquals( 404, $e->getHttpStatus() );
		}
		$this->assertEquals( 0, filesize( $file ) );
	}

	/**
	 * Test the download with wrong credentials.
	 *
	 * @return void
	 */
	public function test_download_to_file_with_wrong_credentials(): void {
		$file   = $this->get_local_file();
		$client = new WebDav\Client(
			array(
				'baseUri'  => self::$server_url . '/file.bin',
				'userName' => 'user',
				'password' => 'wrong',
			)
		);
		$client->setThrowExceptions( true );

		$this->expectException( ClientHttpException::class );
		$client->download_to_file( '', $file );
	}

	/**
	 * Test the download from a server, which could not be reached.
	 *
	 * @return void
	 */
	public function test_download_to_file_without_server(): void {
		$client = new WebDav\Client( array( 'baseUri' => 'http://127.0.0.1:1/file.bin' ) );

		$this->expectException( ClientException::class );
		$client->download_to_file( '', $this->get_local_file() );
	}

	/**
	 * Test that other requests are handled by the original client after a download (e.g. with their body).
	 *
	 * @return void
	 */
	public function test_other_requests_after_download(): void {
		$client = $this->get_client( '/file.bin' );
		$client->download_to_file( '', $this->get_local_file() );

		$client->setThrowExceptions( false );
		$result = $client->request( 'GET', self::$server_url . '/missing.bin' );

		$this->assertEquals( 404, $result['statusCode'] );
		$this->assertEquals( 'body of the error page', $result['body'] );
	}

	/**
	 * Test the upload of a large file as stream, like the export does it.
	 *
	 * Without negotiation of the authentication (here with Basic auth only, which curl sends directly) the
	 * file is not loaded into memory.
	 *
	 * @return void
	 */
	public function test_upload_as_stream(): void {
		$handle = fopen( self::$source_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$client = new WebDav\Client(
			array(
				'baseUri'  => self::$server_url . '/upload-auth.bin',
				'userName' => 'user',
				'password' => 'secret',
				'authType' => WebDav\Client::AUTH_BASIC,
			)
		);

		memory_reset_peak_usage();
		$memory = memory_get_usage();
		$result = $client->request( 'PUT', '', $handle );
		$peak   = memory_get_peak_usage() - $memory;
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertEquals( 201, $result['statusCode'] );
		$this->assertEquals( md5_file( self::$source_file ), md5_file( self::$upload_file ) );
		$this->assertLessThan( self::FILE_SIZE / 4, $peak );
	}

	/**
	 * Test the upload as stream with negotiation of the authentication (Basic and Digest auth, the default of Sabre)
	 * via HTTP: curl could not send a stream again after the challenge of the server, so the body is loaded into
	 * memory and the upload works.
	 *
	 * Via HTTPS the credentials are sent directly via Basic auth, which is tested in the integration tests.
	 *
	 * @return void
	 */
	public function test_upload_as_stream_with_negotiation_of_authentication(): void {
		$handle = fopen( self::$source_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = $this->get_client( '/upload-auth.bin' )->request( 'PUT', '', $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertEquals( 201, $result['statusCode'] );
		$this->assertEquals( md5_file( self::$source_file ), md5_file( self::$upload_file ) );
	}

	/**
	 * Test the upload as stream without credentials.
	 *
	 * @return void
	 */
	public function test_upload_as_stream_without_credentials(): void {
		$handle = fopen( self::$source_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$client = new WebDav\Client( array( 'baseUri' => self::$server_url . '/upload.bin' ) );

		memory_reset_peak_usage();
		$memory = memory_get_usage();
		$result = $client->request( 'PUT', '', $handle );
		$peak   = memory_get_peak_usage() - $memory;
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertEquals( 201, $result['statusCode'] );
		$this->assertEquals( md5_file( self::$source_file ), md5_file( self::$upload_file ) );
		$this->assertLessThan( self::FILE_SIZE / 4, $peak );
	}

	/**
	 * Test that the plugin uses its own client and the hook could still replace it.
	 *
	 * @return void
	 */
	public function test_get_client(): void {
		remove_all_filters( 'efmlwd_service_webdav_client' );
		$this->assertInstanceOf( WebDav\Client::class, WebDav::get_instance()->get_client( array( 'baseUri' => self::$server_url ), self::$server_url, self::$server_url ) );

		// the hook gets our client and could change its settings.
		add_filter(
			'efmlwd_service_webdav_client',
			static function ( \Sabre\DAV\Client $client ): \Sabre\DAV\Client {
				$client->addCurlSetting( CURLOPT_USERAGENT, 'efmlwd-test' );
				return $client;
			}
		);
		$client = WebDav::get_instance()->get_client( array( 'baseUri' => self::$server_url ), self::$server_url, self::$server_url );
		$this->assertInstanceOf( WebDav\Client::class, $client );
	}

	/**
	 * Test the download via a client, which has been replaced via hook (e.g. the mock of the tests).
	 *
	 * @return void
	 */
	public function test_download_file_with_other_client(): void {
		$url = 'https://webdav.example.com/example.jpg';
		$this->add_webdav_response( 'GET', $url, 200, 'content of the file' );
		$file   = $this->get_local_file();
		$client = WebDav::get_instance()->get_client( array( 'baseUri' => $url ), 'https://webdav.example.com', $url );
		$this->assertInstanceOf( WebDavClientMock::class, $client );

		WebDav::get_instance()->download_file( $client, $file );

		$this->assertEquals( 'content of the file', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the download via a client, which has been replaced via hook and returns an error without exception.
	 *
	 * @return void
	 */
	public function test_download_file_with_other_client_and_error(): void {
		$url = 'https://webdav.example.com/example.jpg';
		$this->add_webdav_response( 'GET', $url, 404, 'not found' );
		$client = WebDav::get_instance()->get_client( array( 'baseUri' => $url ), 'https://webdav.example.com', $url );
		$client->setThrowExceptions( false );

		$this->expectException( ClientException::class );
		WebDav::get_instance()->download_file( $client, $this->get_local_file() );
	}
}

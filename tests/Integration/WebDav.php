<?php
/**
 * Integration tests for WebDAV against a real WebDAV server of Sabre.
 *
 * The tests start the WebDAV server of Sabre (from the dependencies of this plugin) with the built-in web server
 * of PHP. As the plugin uses HTTPS for WebDAV servers, a small TLS proxy (tests/Data/tls-proxy.py) with a self-signed
 * certificate is started in front of it, so the tests need "python3". The self-signed certificate is accepted via
 * the setting "eml_webdav_ignore_ssl" of this plugin.
 *
 * Without "python3" the tests are skipped. Set the environment variable EFMLWD_INTEGRATION_REQUIRED=1 to let them
 * fail instead (e.g. in the GitHub workflow).
 *
 * Run them with: composer test-integration
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Integration;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\WebDav\Export;
use ExternalFilesFromWebDav\WebDav\Protocol;

/**
 * Object to test WebDAV against a real WebDAV server of Sabre.
 */
class WebDav extends externalFilesFromWebDavTests {
	/**
	 * The processes of the web server and the TLS proxy.
	 *
	 * @var array<int,resource>
	 */
	private static array $processes = array();

	/**
	 * The URL of the WebDAV server (via the TLS proxy).
	 *
	 * @var string
	 */
	private static string $server_url = '';

	/**
	 * The temporary directory with the root of the WebDAV server, the certificate and the key.
	 *
	 * @var string
	 */
	private static string $temp_dir = '';

	/**
	 * The path of the files of the user on the WebDAV server.
	 *
	 * @var string
	 */
	private const USER_PATH = '/remote.php/dav/files/user/';

	/**
	 * Start the WebDAV server and the TLS proxy.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// bail if python is not available.
		$python = self::get_python();
		if ( '' === $python ) {
			return;
		}

		// create the temporary directory with the root of the WebDAV server.
		self::$temp_dir = trailingslashit( get_temp_dir() ) . 'efmlwd-integration-' . wp_generate_password( 8, false );
		wp_mkdir_p( self::$temp_dir . '/root' . self::USER_PATH );

		// create the self-signed certificate for the TLS proxy.
		$key         = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$request     = openssl_csr_new( array( 'commonName' => '127.0.0.1' ), $key );
		$certificate = openssl_csr_sign( $request, null, $key, 1 );
		openssl_x509_export_to_file( $certificate, self::$temp_dir . '/certificate.pem' );
		openssl_pkey_export_to_file( $key, self::$temp_dir . '/key.pem' );

		// start the WebDAV server.
		$server_port = self::get_free_port();
		$env         = array_merge(
			getenv(),
			array(
				'EFMLWD_INTEGRATION_ROOT'     => self::$temp_dir . '/root',
				'EFMLWD_INTEGRATION_LOGIN'    => 'user',
				'EFMLWD_INTEGRATION_PASSWORD' => 'secret',
			)
		);
		self::start_process( array( PHP_BINARY, '-S', '127.0.0.1:' . $server_port, UNIT_TESTS_DATA_PLUGIN_DIR . 'sabre-server.php' ), $env );

		// start the TLS proxy in front of it.
		$proxy_port = self::get_free_port();
		self::start_process( array( $python, '-I', UNIT_TESTS_DATA_PLUGIN_DIR . 'tls-proxy.py', (string) $proxy_port, (string) $server_port, self::$temp_dir . '/certificate.pem', self::$temp_dir . '/key.pem' ), $env );

		// wait until both answer.
		foreach ( array( $server_port, $proxy_port ) as $port ) {
			for ( $i = 0; $i < 50; $i++ ) {
				$connection = @fsockopen( '127.0.0.1', $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( is_resource( $connection ) ) {
					fclose( $connection ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					break;
				}
				usleep( 100000 );
			}
		}

		// set the URL of the WebDAV server.
		self::$server_url = 'https://127.0.0.1:' . $proxy_port;
	}

	/**
	 * Stop the WebDAV server and the TLS proxy.
	 *
	 * @return void
	 */
	public static function tear_down_after_class(): void {
		foreach ( self::$processes as $process ) {
			proc_terminate( $process );
			proc_close( $process );
		}
		self::$processes = array();

		// remove the temporary directory.
		if ( '' !== self::$temp_dir ) {
			self::delete_directory( self::$temp_dir );
		}

		parent::tear_down_after_class();
	}

	/**
	 * Prepare each test: use the real WebDAV server with an empty directory of the user.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// skip the tests if the servers are not running (or let them fail, if they are required, e.g. in the GitHub workflow).
		if ( ! $this->servers_are_running() ) {
			if ( getenv( 'EFMLWD_INTEGRATION_REQUIRED' ) ) {
				$this->fail( 'The WebDAV server could not be started (it requires "python3" for its TLS proxy).' );
			}
			$this->markTestSkipped( 'The WebDAV server could not be started (it requires "python3" for its TLS proxy).' );
		}

		// empty the directory of the user.
		self::delete_directory( $this->get_local_path( '' ) );
		wp_mkdir_p( $this->get_local_path( '' ) );

		// use the real client instead of the mock of the unit tests.
		remove_filter( 'efmlwd_service_webdav_client', array( externalFilesFromWebDavTests::class, 'replace_webdav_client' ), PHP_INT_MAX );

		// use the hooks of this plugin like for a user with the capability for WebDAV and accept the self-signed certificate.
		update_option( 'eml_webdav_ignore_ssl', 1 );
		add_filter( 'efmlwd_service_webdav_client', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'ignore_self_signed_ssl' ) );
		add_filter( 'efmlwd_service_webdav_path', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'set_path' ), 10, 2 );

		// allow the HTTP requests of WordPress to the server.
		remove_filter( 'pre_http_request', array( externalFilesFromWebDavTests::class, 'filter_http_requests' ) );
		add_filter( 'https_ssl_verify', '__return_false' );
		add_filter( 'http_request_host_is_external', '__return_true' );

		// use the real server in the fields.
		$this->field_values['server'] = self::$server_url;
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'efmlwd_service_webdav_client', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'ignore_self_signed_ssl' ) );
		remove_filter( 'efmlwd_service_webdav_path', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'set_path' ) );
		remove_filter( 'https_ssl_verify', '__return_false' );
		remove_filter( 'http_request_host_is_external', '__return_true' );
		add_filter( 'pre_http_request', array( externalFilesFromWebDavTests::class, 'filter_http_requests' ), 10, 3 );
		delete_option( 'eml_webdav_ignore_ssl' );
		parent::tear_down();
	}

	/**
	 * Return the path to the python binary or an empty string, if it is not available.
	 *
	 * @return string
	 */
	private static function get_python(): string {
		foreach ( array( 'python3', 'python' ) as $binary ) {
			$path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
			if ( '' !== $path ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Return a free port on the local host.
	 *
	 * @return int
	 */
	private static function get_free_port(): int {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
		$name   = (string) stream_socket_get_name( $socket, false );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return (int) substr( $name, strrpos( $name, ':' ) + 1 );
	}

	/**
	 * Start the given command as process in the background.
	 *
	 * @param array<int,string>    $command The command with its arguments.
	 * @param array<string,string> $env     The environment variables.
	 *
	 * @return void
	 */
	private static function start_process( array $command, array $env ): void {
		$null        = array( 'file', '/dev/null', 'w' );
		$descriptors = array(
			1 => $null,
			2 => $null,
		);
		$process     = proc_open( $command, $descriptors, $pipes, null, $env );
		if ( is_resource( $process ) ) {
			self::$processes[] = $process;
		}
	}

	/**
	 * Return whether the web server and the TLS proxy are running.
	 *
	 * @return bool
	 */
	private function servers_are_running(): bool {
		if ( 2 !== count( self::$processes ) ) {
			return false;
		}
		foreach ( self::$processes as $process ) {
			if ( ! proc_get_status( $process )['running'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete the given local directory with its content.
	 *
	 * @param string $directory The directory.
	 *
	 * @return void
	 */
	private static function delete_directory( string $directory ): void {
		\ExternalFilesInMediaLibrary\Plugin\Helper::get_wp_filesystem()->delete( $directory, true );
	}

	/**
	 * Return the local path of the given file in the directory of the user on the WebDAV server.
	 *
	 * @param string $file The file, relative to the directory of the user.
	 *
	 * @return string
	 */
	private function get_local_path( string $file ): string {
		return self::$temp_dir . '/root' . self::USER_PATH . $file;
	}

	/**
	 * Save the example file in the directory of the user on the WebDAV server.
	 *
	 * @param string $file The file, relative to the directory of the user.
	 *
	 * @return void
	 */
	private function add_example_file( string $file ): void {
		wp_mkdir_p( dirname( $this->get_local_path( $file ) ) );
		copy( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg', $this->get_local_path( $file ) );
	}

	/**
	 * Return the credentials like the main plugin uses them for the export.
	 *
	 * @return array<string,mixed>
	 */
	private function get_credentials(): array {
		return array(
			'fields'    => $this->get_webdav_with_fields()->get_fields(),
			'directory' => self::$server_url,
		);
	}

	/**
	 * Test the directory listing.
	 *
	 * @return void
	 */
	public function test_directory_listing(): void {
		$this->add_example_file( 'example.jpg' );
		$this->add_example_file( 'My Photos/photo.jpg' );
		$webdav = $this->get_webdav_with_fields();

		$listing = $webdav->get_directory_listing( self::$server_url );

		$this->assertEmpty( $this->get_error_messages() );
		$this->assertCount( 1, $listing['files'] );
		$this->assertEquals( self::$server_url . self::USER_PATH . 'example.jpg', $listing['files'][0]['file'] );
		$this->assertEquals( filesize( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), $listing['files'][0]['filesize'] );
		$this->assertArrayHasKey( self::$server_url . self::USER_PATH . 'My%20Photos/', $listing['dirs'] );
	}

	/**
	 * Test the directory listing with wrong credentials.
	 *
	 * @return void
	 */
	public function test_directory_listing_with_wrong_credentials(): void {
		$webdav = $this->get_webdav_with_fields( array( 'password' => 'wrong' ) );

		$this->assertEmpty( $webdav->get_directory_listing( self::$server_url ) );
		$this->assertStringContainsString( 'Unauthorized', implode( ' ', $this->get_error_messages() ) );
	}

	/**
	 * Test the directory listing without accepting the self-signed certificate: the connection fails.
	 *
	 * @return void
	 */
	public function test_directory_listing_with_self_signed_certificate(): void {
		update_option( 'eml_webdav_ignore_ssl', 0 );
		$webdav = $this->get_webdav_with_fields();

		$this->assertEmpty( $webdav->get_directory_listing( self::$server_url ) );
		$this->assertNotEmpty( $this->get_error_messages() );
	}

	/**
	 * Test the complete connection test.
	 *
	 * @return void
	 */
	public function test_connection_test(): void {
		$webdav = $this->get_webdav_with_fields();

		foreach ( $webdav->run_connection_test() as $step ) {
			if ( ! $step['skipped'] ) {
				$this->assertTrue( $step['success'], $step['title'] . ': ' . $step['message'] );
			}
		}

		// the test file has been deleted.
		$this->assertEquals( array( '.', '..' ), scandir( $this->get_local_path( '' ) ) );
	}

	/**
	 * Test the export, the import and the deletion of a file.
	 *
	 * @return void
	 */
	public function test_export_import_and_delete(): void {
		$credentials   = $this->get_credentials();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$export        = Export::get_instance();

		// test 1: the export.
		$url = $export->export_file( $attachment_id, self::$server_url . '/' . $file_name, $credentials );
		$this->assertEquals( self::$server_url . self::USER_PATH . $file_name, $url );
		$this->assertFileEquals( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg', $this->get_local_path( $file_name ) );

		// test 2: the import of the exported file.
		$protocol = new Protocol( (string) $url );
		$protocol->set_fields( $credentials['fields'] );
		$results = $protocol->get_url_infos();
		$this->assertCount( 1, $results );
		$this->assertEquals( 'image/jpeg', $results[0]['mime-type'] );
		$this->assertFileEquals( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg', $results[0]['tmp-file'] );
		wp_delete_file( $results[0]['tmp-file'] );

		// test 3: the deletion.
		$this->assertTrue( $export->delete_exported_file( (string) $url, $credentials, $attachment_id ) );
		$this->assertFileDoesNotExist( $this->get_local_path( $file_name ) );
	}

	/**
	 * Test the export into folders, which do not exist on the server.
	 *
	 * @return void
	 */
	public function test_export_into_folder(): void {
		$credentials   = $this->get_credentials();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );

		$url = Export::get_instance()->export_file( $attachment_id, self::$server_url . '/2026/11/' . $file_name, $credentials );

		$this->assertEquals( self::$server_url . self::USER_PATH . '2026/11/' . $file_name, $url );
		$this->assertFileEquals( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg', $this->get_local_path( '2026/11/' . $file_name ) );
	}

	/**
	 * Test the export of a large file, which is uploaded as stream with the credentials sent directly via Basic auth,
	 * so it is not loaded completely into memory.
	 *
	 * @return void
	 */
	public function test_export_of_large_file(): void {
		$credentials = $this->get_credentials();

		// create an attachment with a large file.
		$file   = get_temp_dir() . 'efmlwd-large-' . wp_generate_password( 6, false ) . '.pdf';
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		for ( $i = 0; $i < 8 * 1024; $i++ ) {
			fwrite( $handle, random_bytes( 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$attachment_id = (int) self::factory()->attachment->create_upload_object( $file );
		wp_delete_file( $file );
		$local_file = (string) get_attached_file( $attachment_id, true );

		memory_reset_peak_usage();
		$memory = memory_get_usage();
		$url    = Export::get_instance()->export_file( $attachment_id, self::$server_url . '/' . basename( $local_file ), $credentials );
		$peak   = memory_get_peak_usage() - $memory;

		$this->assertEquals( self::$server_url . self::USER_PATH . basename( $local_file ), $url );
		$this->assertFileEquals( $local_file, $this->get_local_path( basename( $local_file ) ) );
		$this->assertLessThan( 2 * 1024 * 1024, $peak );
	}

	/**
	 * Test the import of a directory with many files via import run: all files are imported, also in the background,
	 * and a second import skips them.
	 *
	 * The count of files could be changed with the environment variable EFMLWD_INTEGRATION_IMPORT_COUNT.
	 *
	 * @return void
	 */
	public function test_import_of_directory_with_many_files(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'efml_import_runs' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'eml_queue' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// use the protocol, like for users with the capability.
		add_filter( 'efml_protocols', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'add_protocol' ) );

		// add the files.
		$count = getenv( 'EFMLWD_INTEGRATION_IMPORT_COUNT' ) ? absint( getenv( 'EFMLWD_INTEGRATION_IMPORT_COUNT' ) ) : 1100;
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->add_example_file( 'many/file-' . $i . '.jpg' );
		}
		$before = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `post_type` = %s', array( $wpdb->posts, 'attachment' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// import the directory and process the run in the background until it is finished.
		$url    = self::$server_url . self::USER_PATH . 'many/';
		$import = \ExternalFilesInMediaLibrary\ExternalFiles\Import::get_instance();
		$import->set_fields( $this->get_webdav_with_fields()->get_fields() );
		$this->assertTrue( $import->add_url( $url ) );
		$runs = \ExternalFilesInMediaLibrary\ExternalFiles\Import_Runs::get_instance();
		for ( $i = 0; $i < 100 && $runs->get_progress()['runs'] > 0; $i++ ) {
			$runs->process();
		}

		$this->assertEquals( 0, $runs->get_progress()['runs'] );
		$this->assertEquals( $before + $count, absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `post_type` = %s', array( $wpdb->posts, 'attachment' ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\File::class, \ExternalFilesInMediaLibrary\ExternalFiles\Files::get_instance()->get_file_by_url( $url . 'file-' . $count . '.jpg' ) );

		// a second import does not import them again.
		$this->assertFalse( $import->add_url( $url ) );
		$import->set_fields( array() );
		remove_filter( 'efml_protocols', array( \ExternalFilesFromWebDav\WebDav::get_instance(), 'add_protocol' ) );
	}

	/**
	 * Test the import of a directory with a large file, which is not loaded completely into memory.
	 *
	 * @return void
	 */
	public function test_import_of_directory_with_large_file(): void {
		$this->add_example_file( 'import/example.jpg' );
		$handle = fopen( $this->get_local_path( 'import/large.pdf' ), 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		for ( $i = 0; $i < 8 * 1024; $i++ ) {
			fwrite( $handle, random_bytes( 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$protocol = new Protocol( self::$server_url . self::USER_PATH . 'import/' );
		$protocol->set_fields( $this->get_webdav_with_fields()->get_fields() );
		memory_reset_peak_usage();
		$memory  = memory_get_usage();
		$results = $protocol->get_url_infos();
		$peak    = memory_get_peak_usage() - $memory;

		$this->assertCount( 2, $results );
		foreach ( $results as $result ) {
			$this->assertFileEquals( $this->get_local_path( 'import/' . $result['title'] ), $result['tmp-file'] );
			wp_delete_file( $result['tmp-file'] );
		}
		$this->assertLessThan( 4 * 1024 * 1024, $peak );
	}
}

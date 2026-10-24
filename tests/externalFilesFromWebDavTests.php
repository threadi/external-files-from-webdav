<?php
/**
 * File to handle the main object for each test class.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests;

use ExternalFilesFromWebDav\WebDav;
use ReflectionClass;
use Sabre\DAV\Client;
use Sabre\HTTP\Response;
use WP_Error;
use WP_HTTP_Requests_Response;
use WP_UnitTestCase;

/**
 * Object to handle the preparations for each test class.
 */
abstract class externalFilesFromWebDavTests extends WP_UnitTestCase {
	/**
	 * The domain of the WebDAV server in the tests.
	 *
	 * @var string
	 */
	protected string $domain = 'https://webdav.example.com';

	/**
	 * The values for the fields we use in the tests.
	 *
	 * @var array<string,string>
	 */
	protected array $field_values = array(
		'server'   => 'https://webdav.example.com',
		'login'    => 'user',
		'password' => 'secret',
		'path'     => '/remote.php/dav/files/',
	);

	/**
	 * List of HTTP responses the actual test expects, with the URL as index.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $http_responses = array();

	/**
	 * List of HTTP requests during the actual test.
	 *
	 * @var array<int,array<string,string>>
	 */
	private static array $http_requests = array();

	/**
	 * List of the WebDAV clients, which has been created during the actual test.
	 *
	 * @var array<int,WebDavClientMock>
	 */
	private static array $webdav_clients = array();

	/**
	 * Prepare the test environment for each test class.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// prepare to load just one time.
		if ( ! did_action( 'efmlwd_test_preparation_loaded' ) ) {
			// enable error reporting.
			error_reporting( E_ALL );

			// enable the extended log mode.
			update_option( 'eml_log_mode', 2 );

			// initialize the main plugin.
			\ExternalFilesInMediaLibrary\Plugin\Install::get_instance()->activation();

			// initialize this plugin (adds the capability for WebDAV).
			\ExternalFilesFromWebDav\Plugin\Init::get_instance()->activation();

			// start with an empty log, as its table is kept between test runs.
			\ExternalFilesInMediaLibrary\Plugin\Log::get_instance()->truncate_log();

			// run initialization.
			do_action( 'init' );

			// prevent any HTTP requests to external sources.
			add_filter( 'pre_http_request', array( self::class, 'filter_http_requests' ), 10, 3 );

			// prevent sending of emails.
			add_filter( 'wp_mail', array( self::class, 'filter_wp_mail' ) );

			// mark as loaded.
			do_action( 'efmlwd_test_preparation_loaded' );
		}
	}

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// the WebDav object is a singleton, so we reset its state before each test.
		$this->reset_webdav();

		// reset the lists for the mocks.
		self::$http_responses = array();
		self::$http_requests  = array();
		self::$webdav_clients = array();
		WebDavClientMock::reset();

		// answer all WebDAV requests locally (the hooks are restored after each test).
		add_filter( 'efmlwd_service_webdav_client', array( self::class, 'replace_webdav_client' ), PHP_INT_MAX );
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->do_logout();
		parent::tear_down();
	}

	/**
	 * Replace the WebDAV client with a client, which answers all requests locally.
	 *
	 * @param Client $client The original client.
	 *
	 * @return Client
	 */
	public static function replace_webdav_client( Client $client ): Client {
		$mock                   = new WebDavClientMock( $client );
		self::$webdav_clients[] = $mock;
		return $mock;
	}

	/**
	 * Return the WebDAV clients, which has been created during the actual test.
	 *
	 * @return array<int,WebDavClientMock>
	 */
	protected function get_webdav_clients(): array {
		return self::$webdav_clients;
	}

	/**
	 * Set the response for a WebDAV request.
	 *
	 * @param string                                $method   The HTTP method.
	 * @param string                                $url      The absolute URL.
	 * @param int|callable|\Throwable $response The HTTP status, a callable which returns a Sabre response, or an exception.
	 * @param string                                $body     The body of the response.
	 *
	 * @return void
	 */
	protected function add_webdav_response( string $method, string $url, int|callable|\Throwable $response, string $body = '' ): void {
		if ( is_int( $response ) ) {
			$response = new Response( $response, array(), $body );
		}
		WebDavClientMock::add_response( $method, $url, $response );
	}

	/**
	 * Return the WebDAV requests during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_webdav_requests(): array {
		return WebDavClientMock::get_requests();
	}

	/**
	 * Return the body of a PROPFIND response (multistatus) like a WebDAV server (e.g. Nextcloud) returns it.
	 *
	 * @param array<string,array<string,mixed>> $entries List of entries with the href as index. Each entry is a
	 *                                                    directory, if "dir" is true, otherwise a file with "size".
	 *
	 * @return string
	 */
	protected function get_propfind_body( array $entries ): string {
		$xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
		foreach ( $entries as $href => $entry ) {
			$xml .= '<d:response><d:href>' . esc_html( $href ) . '</d:href><d:propstat><d:prop>';
			$xml .= '<d:getlastmodified>' . ( $entry['modified'] ?? 'Tue, 02 Jan 2024 03:04:05 GMT' ) . '</d:getlastmodified>';
			if ( ! empty( $entry['dir'] ) ) {
				$xml .= '<d:resourcetype><d:collection/></d:resourcetype>';
			} else {
				$xml .= '<d:resourcetype/>';
				$xml .= '<d:getcontentlength>' . absint( $entry['size'] ?? 0 ) . '</d:getcontentlength>';
				$xml .= '<d:getcontenttype>' . ( $entry['type'] ?? 'application/octet-stream' ) . '</d:getcontenttype>';
			}
			$xml .= '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
		}
		return $xml . '</d:multistatus>';
	}

	/**
	 * Filter any HTTP request during the tests to prevent any external communication.
	 *
	 * Tests can set the response for a specific URL with add_http_response().
	 *
	 * @param false|array|WP_Error $response    The return value.
	 * @param array                $parsed_args The used parameters.
	 * @param string               $url         The requested URL.
	 *
	 * @return false|array|WP_Error
	 */
	public static function filter_http_requests( false|array|WP_Error $response, array $parsed_args, string $url ): false|array|WP_Error {
		// let requests through, which a test has already answered.
		if ( false !== $response ) {
			return $response;
		}

		// log the request.
		self::$http_requests[] = array(
			'url'    => $url,
			'method' => isset( $parsed_args['method'] ) ? (string) $parsed_args['method'] : 'GET',
			'args'   => $parsed_args,
		);

		// show error if no response is configured for this URL, as we do not want any external URL connection during PHP Unit Tests.
		if ( ! isset( self::$http_responses[ $url ] ) ) {
			self::fail( 'Trying an external URL connection, which should not happen during PHP Unit Tests. Used URL: ' . $url );
		}

		// return the configured response.
		$configured_response = self::$http_responses[ $url ];
		return self::get_http_response( $url, $configured_response['code'], $configured_response['headers'], $configured_response['body'] );
	}

	/**
	 * Filter any email communication from WordPress.
	 *
	 * @param array $args The used arguments.
	 *
	 * @return array
	 */
	public static function filter_wp_mail( array $args ): array {
		$args['to'] = 'info@example.com';
		return $args;
	}

	/**
	 * Set the response for an HTTP request on the given URL for the actual test.
	 *
	 * @param string               $url         The URL.
	 * @param int                  $status_code The HTTP status code.
	 * @param array<string,string> $headers     The response headers.
	 * @param string               $body        The response body.
	 *
	 * @return void
	 */
	protected function add_http_response( string $url, int $status_code, array $headers = array(), string $body = '' ): void {
		self::$http_responses[ $url ] = array(
			'code'    => $status_code,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Return the list of HTTP requests during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_http_requests(): array {
		return self::$http_requests;
	}

	/**
	 * Return a response array, like WordPress returns it for an HTTP request.
	 *
	 * @param string $url         The requested URL.
	 * @param int    $status_code The HTTP status code.
	 * @param array  $headers     The response headers.
	 * @param string $body        The response body.
	 *
	 * @return array
	 */
	protected static function get_http_response( string $url, int $status_code, array $headers = array(), string $body = '' ): array {
		$requests_response              = new \WpOrg\Requests\Response();
		$requests_response->status_code = $status_code;
		$requests_response->url         = $url;
		$requests_response->body        = $body;
		$requests_response->headers     = new \WpOrg\Requests\Response\Headers( $headers );
		$requests_response->success     = $status_code < 400;

		$http_response             = new WP_HTTP_Requests_Response( $requests_response, null );
		$response                  = $http_response->to_array();
		$response['http_response'] = $http_response;
		return $response;
	}

	/**
	 * Return the WebDav object with the field values of the tests.
	 *
	 * @param array<string,string> $values Additional values for the fields.
	 *
	 * @return WebDav
	 */
	protected function get_webdav_with_fields( array $values = array() ): WebDav {
		$webdav = WebDav::get_instance();
		$fields = $webdav->get_fields();
		foreach ( array_merge( $this->field_values, $values ) as $name => $value ) {
			$fields[ $name ]['value'] = $value;
		}
		$webdav->set_fields( $fields );
		return $webdav;
	}

	/**
	 * Reset the state of the WebDav object.
	 *
	 * @return void
	 */
	protected function reset_webdav(): void {
		$webdav = WebDav::get_instance();
		$webdav->set_fields( array() );
		$webdav->set_directory( '' );
		$this->set_property( $webdav, 'errors', array() );
	}

	/**
	 * Set the value of a (not public) property of the given object.
	 *
	 * @param object $obj      The object.
	 * @param string $property The name of the property.
	 * @param mixed  $value    The value.
	 *
	 * @return void
	 */
	protected function set_property( object $obj, string $property, mixed $value ): void {
		$reflection = new ReflectionClass( $obj );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$reflection->getProperty( $property )->setValue( $obj, $value );
	}

	/**
	 * Call a not public method of the given object.
	 *
	 * @param object           $obj    The object.
	 * @param string           $method The name of the method.
	 * @param array<int,mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	protected function call_method( object $obj, string $method, array $args = array() ): mixed {
		return ( new \ReflectionMethod( $obj, $method ) )->invokeArgs( $obj, $args );
	}

	/**
	 * Return the error messages of the WebDav object.
	 *
	 * @return array<int,string>
	 */
	protected function get_error_messages(): array {
		$messages = array();
		foreach ( WebDav::get_instance()->get_errors() as $error ) {
			$messages = array_merge( $messages, $error->get_error_messages() );
		}
		return $messages;
	}

	/**
	 * Return the log entries with the given URL, the newest first.
	 *
	 * @param string $url The URL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_log_entries( string $url = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eml_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT `url`, `log`, `state` FROM `$table` WHERE `url` = %s ORDER BY `id` DESC", $url ), ARRAY_A );
	}

	/**
	 * Login in this session as admin.
	 *
	 * @return void
	 */
	protected function do_login(): void {
		wp_set_current_user( 1, 'admin' );
	}

	/**
	 * Logout from this session.
	 *
	 * @return void
	 */
	protected function do_logout(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * Create an attachment with a local file and return its ID.
	 *
	 * @param string $file_name The name of the file in tests/Data/.
	 *
	 * @return int
	 */
	protected function create_local_attachment( string $file_name = 'example.jpg' ): int {
		return (int) self::factory()->attachment->create_upload_object( UNIT_TESTS_DATA_PLUGIN_DIR . $file_name );
	}
}

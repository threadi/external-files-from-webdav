<?php
/**
 * Tests for class ExternalFilesFromWebDav\WebDav.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests\Unit;

use ExternalFilesFromWebDav\Tests\externalFilesFromWebDavTests;
use ExternalFilesFromWebDav\WebDav\Export;
use ExternalFilesInMediaLibrary\ExternalFiles\Protocols\Http;
use ExternalFilesInMediaLibrary\Services\Service;
use Sabre\DAV\Client;

/**
 * Object to test functions in class ExternalFilesFromWebDav\WebDav.
 */
class WebDav extends externalFilesFromWebDavTests {
	/**
	 * Return the object to test.
	 *
	 * @return \ExternalFilesFromWebDav\WebDav
	 */
	private function get_webdav(): \ExternalFilesFromWebDav\WebDav {
		return \ExternalFilesFromWebDav\WebDav::get_instance();
	}

	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = $this->get_webdav();
		$this->assertInstanceOf( \ExternalFilesFromWebDav\WebDav::class, $instance );
		$this->assertInstanceOf( Service::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromWebDav\WebDav::get_instance() );
	}

	/**
	 * Test if the returning variables are the expected strings.
	 *
	 * @return void
	 */
	public function test_get_name_and_label(): void {
		$this->assertEquals( 'webdav', $this->get_webdav()->get_name() );
		$this->assertEquals( 'WebDAV', $this->get_webdav()->get_label() );
		$this->assertEquals( 'efml_cap_webdav', $this->get_webdav()->get_permission_name() );
	}

	/**
	 * Test the fields for the connection.
	 *
	 * @return void
	 */
	public function test_get_fields(): void {
		$fields = $this->get_webdav()->get_fields();
		$this->assertEquals( array( 'server', 'login', 'password', 'path' ), array_keys( $fields ) );
		$this->assertTrue( $fields['login']['credential'] );
		$this->assertTrue( $fields['password']['credential'] );
		$this->assertEquals( 'password', $fields['password']['type'] );
		$this->assertEquals( '', $fields['server']['value'] );
		$this->assertFalse( $fields['server']['readonly'] );

		// the path has a default value for Nextcloud.
		$this->assertEquals( '/remote.php/dav/files/', $fields['path']['value'] );
		$this->assertFalse( $fields['path']['readonly'] );
	}

	/**
	 * Test if the fields use the global settings if this mode is enabled.
	 *
	 * @return void
	 */
	public function test_get_fields_in_global_mode(): void {
		update_option( 'eml_webdav_credentials_vault', 'global' );
		update_option( 'eml_webdav_server', 'https://global.example.com' );
		update_option( 'eml_webdav_login', $this->get_webdav()->encrypt_value( 'global-user' ) );
		update_option( 'eml_webdav_password', $this->get_webdav()->encrypt_value( 'global-secret' ) );
		update_option( 'eml_webdav_path', '/dav/' );

		$fields = $this->get_webdav()->get_fields();
		$this->assertEquals( 'https://global.example.com', $fields['server']['value'] );
		$this->assertEquals( 'global-user', $fields['login']['value'] );
		$this->assertEquals( 'global-secret', $fields['password']['value'] );
		$this->assertEquals( '/dav/', $fields['path']['value'] );
		$this->assertTrue( $fields['server']['readonly'] );
		$this->assertTrue( $fields['login']['readonly'] );
		$this->assertTrue( $fields['password']['readonly'] );
		$this->assertTrue( $fields['path']['readonly'] );
		$this->assertEquals( 'https://global.example.com', $this->get_webdav()->get_directory() );
	}

	/**
	 * Test if the fields use the user settings if this mode is enabled.
	 *
	 * @return void
	 */
	public function test_get_fields_in_user_mode(): void {
		update_option( 'eml_webdav_credentials_vault', 'user' );
		$this->do_login();
		foreach ( array( 'server' => 'https://user.example.com', 'login' => 'user-login', 'password' => 'user-secret', 'path' => '/user-dav/' ) as $name => $value ) {
			update_user_meta( 1, 'efml_webdav_' . $name, $this->get_webdav()->encrypt_value( $value ) );
		}

		$fields = $this->get_webdav()->get_fields();
		$this->assertEquals( 'https://user.example.com', $fields['server']['value'] );
		$this->assertEquals( 'user-login', $fields['login']['value'] );
		$this->assertEquals( 'user-secret', $fields['password']['value'] );
		$this->assertEquals( '/user-dav/', $fields['path']['value'] );
	}

	/**
	 * Test the directory from the fields.
	 *
	 * @return void
	 */
	public function test_get_directory(): void {
		// test 1: without fields.
		$this->assertEquals( '', $this->get_webdav()->get_directory() );

		// test 2: with server.
		$this->get_webdav_with_fields();
		$this->assertEquals( 'https://webdav.example.com', $this->get_webdav()->get_directory() );
	}

	/**
	 * Test if the URL is completed with https.
	 *
	 * @return void
	 */
	public function test_get_url(): void {
		$this->assertEquals( 'https://webdav.example.com/file.jpg', $this->get_webdav()->get_url( 'webdav.example.com/file.jpg' ) );
		$this->assertEquals( 'https://webdav.example.com/file.jpg', $this->get_webdav()->get_url( 'https://webdav.example.com/file.jpg' ) );
		$this->assertEquals( 'http://webdav.example.com/file.jpg', $this->get_webdav()->get_url( 'http://webdav.example.com/file.jpg' ) );
	}

	/**
	 * Test the path on the WebDAV server in the different modes.
	 *
	 * @return void
	 */
	public function test_set_path(): void {
		$fields = $this->get_webdav_with_fields()->get_fields();

		// test 1: a given path is used (without double slashes).
		$this->assertEquals( '/remote.php/dav/files/user/dir/', $this->get_webdav()->set_path( '/remote.php/dav/files/user/dir/', $fields ) );
		$this->assertEquals( '/remote.php/dav/files/user/dir/', $this->get_webdav()->set_path( '//remote.php/dav/files/user/dir/', $fields ) );

		// test 2: without path the path from the fields is used with the login.
		$this->assertEquals( '/remote.php/dav/files/user/', $this->get_webdav()->set_path( '', $fields ) );
		$this->assertEquals( '/remote.php/dav/files/user/', $this->get_webdav()->set_path( '/', $fields ) );

		// test 3: in global mode the path from the settings is used.
		update_option( 'eml_webdav_credentials_vault', 'global' );
		update_option( 'eml_webdav_path', '/global-dav/' );
		$this->assertEquals( '/global-dav/user/', $this->get_webdav()->set_path( '', $fields ) );

		// test 4: in user mode the path from the user settings is used.
		update_option( 'eml_webdav_credentials_vault', 'user' );
		$this->do_login();
		update_user_meta( 1, 'efml_webdav_path', $this->get_webdav()->encrypt_value( '/user-dav/' ) );
		$this->assertEquals( '/user-dav/user/', $this->get_webdav()->set_path( '', $fields ) );

		// test 5: no path without mode and without path in fields.
		delete_option( 'eml_webdav_credentials_vault' );
		$fields['path']['value'] = '';
		$this->assertEquals( '', $this->get_webdav()->set_path( '', $fields ) );
	}

	/**
	 * Test the user settings for WebDAV.
	 *
	 * @return void
	 */
	public function test_get_user_settings(): void {
		$settings = $this->get_webdav()->get_user_settings();
		$this->assertEquals( array( 'webdav_server', 'webdav_login', 'webdav_password', 'webdav_path' ), array_keys( $settings ) );
		$this->assertEquals( 'password', $settings['webdav_password']['field'] );
		$this->assertEquals( '/remote.php/dav/files/', $settings['webdav_path']['default'] );

		// the list is filterable.
		$callback = static function ( array $list ): array {
			$list['custom'] = array( 'label' => 'Custom' );
			return $list;
		};
		add_filter( 'efmlwd_service_webdav_user_settings', $callback );
		$this->assertArrayHasKey( 'custom', $this->get_webdav()->get_user_settings() );
		remove_filter( 'efmlwd_service_webdav_user_settings', $callback );
	}

	/**
	 * Test the form title depending on the credentials.
	 *
	 * @return void
	 */
	public function test_get_form_title(): void {
		// test 1: without credentials.
		$this->assertEquals( 'Enter your credentials', $this->get_webdav()->get_form_title() );

		// test 2: with credentials.
		$this->get_webdav_with_fields();
		$this->assertEquals( 'Connect to your WebDAV', $this->get_webdav()->get_form_title() );
	}

	/**
	 * Test the form description in the different modes.
	 *
	 * @return void
	 */
	public function test_get_form_description(): void {
		// test 1: manual mode.
		update_option( 'eml_webdav_credentials_vault', 'manually' );
		$this->assertEquals( 'Enter your WebDAV credentials in this form.', $this->get_webdav()->get_form_description() );

		// test 2: user mode without credentials.
		update_option( 'eml_webdav_credentials_vault', 'user' );
		$this->assertStringContainsString( 'Set your credentials', $this->get_webdav()->get_form_description() );
		$this->assertStringContainsString( 'profile.php#efml-webdav', $this->get_webdav()->get_form_description() );

		// test 3: user mode with credentials.
		$this->get_webdav_with_fields();
		$this->assertStringContainsString( 'already set', $this->get_webdav()->get_form_description() );

		// test 4: global mode for users without the capability to manage options.
		$this->reset_webdav();
		update_option( 'eml_webdav_credentials_vault', 'global' );
		$this->assertStringContainsString( 'must be set by an administrator', $this->get_webdav()->get_form_description() );

		// test 5: global mode for administrators.
		$this->do_login();
		$this->assertStringContainsString( 'Set your credentials', $this->get_webdav()->get_form_description() );
		$this->get_webdav_with_fields();
		$this->assertStringContainsString( 'already set', $this->get_webdav()->get_form_description() );
	}

	/**
	 * Test the actions for files and directories.
	 *
	 * @return void
	 */
	public function test_get_actions(): void {
		$actions = $this->get_webdav()->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertEquals( 'Import', $actions[0]['label'] );
		$this->assertStringContainsString( '"service": "webdav"', $actions[0]['action'] );

		$global_actions = $this->call_method( $this->get_webdav(), 'get_global_actions' );
		$this->assertContains( 'Save active directory as your external source', array_column( $global_actions, 'label' ) );
	}

	/**
	 * Test if the protocol is added before the other protocols.
	 *
	 * @return void
	 */
	public function test_add_protocol(): void {
		$protocols = $this->get_webdav()->add_protocol( array( 'ExternalFilesInMediaLibrary\ExternalFiles\Protocols\Http' ) );
		$this->assertEquals( array( 'ExternalFilesFromWebDav\WebDav\Protocol', 'ExternalFilesInMediaLibrary\ExternalFiles\Protocols\Http' ), $protocols );
		$this->assertTrue( class_exists( $protocols[0] ) );
	}

	/**
	 * Test if the export object is returned.
	 *
	 * @return void
	 */
	public function test_get_export_object(): void {
		$this->assertInstanceOf( Export::class, $this->get_webdav()->get_export_object() );
	}

	/**
	 * Test if not supported files are hidden in the directory listing, if this is enabled.
	 *
	 * @return void
	 */
	public function test_prevent_not_allowed_files(): void {
		// test 1: setting is disabled.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 0 );
		$this->assertFalse( $this->get_webdav()->prevent_not_allowed_files( false, array(), '/dav/example.exe' ) );

		// test 2: setting is enabled.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 1 );
		$this->assertFalse( $this->get_webdav()->prevent_not_allowed_files( false, array(), '/dav/example.jpg' ) );
		$this->assertTrue( $this->get_webdav()->prevent_not_allowed_files( false, array(), '/dav/example.exe' ) );
	}

	/**
	 * Test the creation of the WebDAV client.
	 *
	 * @return void
	 */
	public function test_get_client(): void {
		$client = $this->get_webdav()->get_client(
			array(
				'baseUri'  => 'https://webdav.example.com',
				'userName' => 'user',
				'password' => 'secret',
			),
			'https://webdav.example.com',
			'https://webdav.example.com/dir/'
		);
		$this->assertInstanceOf( Client::class, $client );
		$this->assertEquals( 'https://webdav.example.com', $client->get_base_uri() );
		$this->assertEquals( 'user:secret', $client->get_curl_settings()[ CURLOPT_USERPWD ] );
	}

	/**
	 * Test if self-signed SSL certificates are only accepted if this is enabled.
	 *
	 * @return void
	 */
	public function test_ignore_self_signed_ssl(): void {
		// test 1: setting is disabled.
		update_option( 'eml_webdav_ignore_ssl', 0 );
		$client = $this->get_webdav()->ignore_self_signed_ssl( new \ExternalFilesFromWebDav\Tests\WebDavClientMock( new Client( array( 'baseUri' => 'https://webdav.example.com' ) ) ) );
		$this->assertArrayNotHasKey( CURLOPT_SSL_VERIFYPEER, $client->get_curl_settings() );

		// test 2: setting is enabled.
		update_option( 'eml_webdav_ignore_ssl', 1 );
		$client = $this->get_webdav()->ignore_self_signed_ssl( $client );
		$this->assertFalse( $client->get_curl_settings()[ CURLOPT_SSL_VERIFYPEER ] );
		$this->assertFalse( $client->get_curl_settings()[ CURLOPT_SSL_VERIFYHOST ] );
	}

	/**
	 * Test the directory listing of the main directory.
	 *
	 * @return void
	 */
	public function test_get_directory_listing(): void {
		$webdav = $this->get_webdav_with_fields();
		add_filter( 'efmlwd_service_webdav_path', array( $webdav, 'set_path' ), 10, 2 );

		$this->add_webdav_response(
			'PROPFIND',
			$this->domain . '/remote.php/dav/files/user/',
			207,
			$this->get_propfind_body(
				array(
					'/remote.php/dav/files/user/'               => array( 'dir' => true ),
					'/remote.php/dav/files/user/example.jpg'    => array( 'size' => 706, 'type' => 'image/jpeg' ),
					'/remote.php/dav/files/user/my%20image.png' => array( 'size' => 1234, 'type' => 'image/png' ),
					'/remote.php/dav/files/user/unknown.xyz'    => array( 'size' => 1 ),
					'/remote.php/dav/files/user/My%20Photos/'   => array( 'dir' => true ),
				)
			)
		);

		$listing = $webdav->get_directory_listing( $this->domain );

		// check the request.
		$requests = $this->get_webdav_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( 'PROPFIND', $requests[0]['method'] );
		$this->assertEquals( 'user:secret', $requests[0]['curl'][ CURLOPT_USERPWD ] );

		// check the files (the file with unknown file type is not listed).
		$this->assertCount( 2, $listing['files'] );
		$this->assertEquals( 'example.jpg', $listing['files'][0]['title'] );
		$this->assertEquals( $this->domain . '/remote.php/dav/files/user/example.jpg', $listing['files'][0]['file'] );
		$this->assertEquals( 706, $listing['files'][0]['filesize'] );
		$this->assertEquals( 'image/jpeg', $listing['files'][0]['mime-type'] );
		$this->assertNotEmpty( $listing['files'][0]['last-modified'] );
		$this->assertEquals( 'my image.png', $listing['files'][1]['title'] );

		// check the directories.
		$this->assertEquals( array( $this->domain . '/remote.php/dav/files/user/My%20Photos/' ), array_keys( $listing['dirs'] ) );
		$this->assertEquals( 'My Photos', $listing['dirs'][ $this->domain . '/remote.php/dav/files/user/My%20Photos/' ]['title'] );
		$this->assertEmpty( $webdav->get_errors() );
	}

	/**
	 * Test the directory listing of a sub-directory.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_for_sub_directory(): void {
		$webdav    = $this->get_webdav_with_fields();
		$directory = $this->domain . '/remote.php/dav/files/user/My%20Photos/';
		add_filter( 'efmlwd_service_webdav_path', array( $webdav, 'set_path' ), 10, 2 );

		$this->add_webdav_response(
			'PROPFIND',
			$directory,
			207,
			$this->get_propfind_body(
				array(
					'/remote.php/dav/files/user/My%20Photos/'            => array( 'dir' => true ),
					'/remote.php/dav/files/user/My%20Photos/holiday.jpg' => array( 'size' => 706, 'type' => 'image/jpeg' ),
				)
			)
		);

		$listing = $webdav->get_directory_listing( $directory );

		$this->assertCount( 1, $listing['files'] );
		$this->assertEquals( 'holiday.jpg', $listing['files'][0]['title'] );
		$this->assertEquals( $directory . 'holiday.jpg', $listing['files'][0]['file'] );
		$this->assertEmpty( $listing['dirs'] );
	}

	/**
	 * Test if files can be hidden in the directory listing via hook.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_with_hidden_files(): void {
		$webdav = $this->get_webdav_with_fields();
		add_filter( 'efmlwd_service_webdav_path', array( $webdav, 'set_path' ), 10, 2 );
		$this->add_webdav_response(
			'PROPFIND',
			$this->domain . '/remote.php/dav/files/user/',
			207,
			$this->get_propfind_body(
				array(
					'/remote.php/dav/files/user/example.jpg' => array( 'size' => 1 ),
					'/remote.php/dav/files/user/example.pdf' => array( 'size' => 1 ),
				)
			)
		);

		$callback = static function ( bool $hide, array $props, string $file_name ): bool {
			return str_ends_with( $file_name, '.pdf' );
		};
		add_filter( 'efmlwd_service_webdav_hide_file', $callback, 10, 3 );
		$listing = $webdav->get_directory_listing( $this->domain );
		remove_filter( 'efmlwd_service_webdav_hide_file', $callback );

		$this->assertEquals( array( 'example.jpg' ), array_column( $listing['files'], 'title' ) );
	}

	/**
	 * Test the directory listing with an error from the WebDAV server.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_with_error(): void {
		$webdav = $this->get_webdav_with_fields();
		add_filter( 'efmlwd_service_webdav_path', array( $webdav, 'set_path' ), 10, 2 );
		$this->add_webdav_response( 'PROPFIND', $this->domain . '/remote.php/dav/files/user/', 401 );

		$this->assertEquals( array(), $webdav->get_directory_listing( $this->domain ) );
		$this->assertStringContainsString( 'The following error occurred', $this->get_error_messages()[0] );

		// the error is logged without the password.
		$log = $this->get_log_entries( $this->domain );
		$this->assertNotEmpty( $log );
		$this->assertEquals( 'error', $log[0]['state'] );
		$this->assertStringNotContainsString( 'secret', $log[0]['log'] );
		$this->assertStringContainsString( '************', $log[0]['log'] );
	}

	/**
	 * Test the directory listing with a URL, which could not be used.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_with_wrong_url(): void {
		$webdav = $this->get_webdav_with_fields();
		$this->assertEquals( array(), $webdav->get_directory_listing( 'https://' ) );
		$this->assertNotEmpty( $webdav->get_errors() );
		$this->assertEmpty( $this->get_webdav_requests() );
	}

	/**
	 * Test the usage of unsafe URLs (e.g. local servers) only for URLs of the WebDAV server.
	 *
	 * @return void
	 */
	public function test_disable_check_for_unsafe_urls(): void {
		$args = array( 'reject_unsafe_urls' => true );

		// test 1: without fields.
		$this->assertEquals( $args, $this->get_webdav()->disable_check_for_unsafe_urls( $args, new Http( $this->domain . '/file.jpg' ) ) );

		// test 2: URL of the WebDAV server.
		$this->get_webdav_with_fields();
		$this->assertFalse( $this->get_webdav()->disable_check_for_unsafe_urls( $args, new Http( $this->domain . '/file.jpg' ) )['reject_unsafe_urls'] );

		// test 3: other URL.
		$this->assertTrue( $this->get_webdav()->disable_check_for_unsafe_urls( $args, new Http( 'https://example.com/file.jpg' ) )['reject_unsafe_urls'] );

		// test 4: without protocol object.
		$this->assertTrue( $this->get_webdav()->disable_check_for_unsafe_urls( $args, 'https://example.com/file.jpg' )['reject_unsafe_urls'] );
	}

	/**
	 * Test if unsafe URLs are only enabled for the export to WebDAV.
	 *
	 * @return void
	 */
	public function test_enable_unsafe_urls_for_export(): void {
		$webdav = $this->get_webdav();

		// test 1: other service.
		$webdav->enable_unsafe_urls_for_export( \ExternalFilesInMediaLibrary\Services\DropBox::get_instance() );
		$this->assertFalse( has_filter( 'efml_http_header_args', array( $webdav, 'disable_check_for_unsafe_urls' ) ) );

		// test 2: WebDAV.
		$webdav->enable_unsafe_urls_for_export( $webdav );
		$this->assertIsInt( has_filter( 'efml_http_header_args', array( $webdav, 'disable_check_for_unsafe_urls' ) ) );
	}

	/**
	 * Test if unsafe URLs are only enabled in the proxy for files from WebDAV.
	 *
	 * @return void
	 */
	public function test_enable_unsafe_urls_for_proxy(): void {
		$webdav        = $this->get_webdav();
		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment_id, EFML_POST_META_URL, $this->domain . '/remote.php/dav/files/user/example.jpg' );
		$file = \ExternalFilesInMediaLibrary\ExternalFiles\Files::get_instance()->get_file( $attachment_id );

		// test 1: file without fields.
		$webdav->enable_unsafe_urls_for_proxy( $file );
		$this->assertFalse( has_filter( 'http_request_args', array( $webdav, 'disable_check_for_unsafe_urls' ) ) );

		// test 2: file from WebDAV.
		$file->set_fields( $this->get_webdav_with_fields()->get_fields() );
		$webdav->enable_unsafe_urls_for_proxy( $file );
		$this->assertIsInt( has_filter( 'http_request_args', array( $webdav, 'disable_check_for_unsafe_urls' ) ) );
	}

	/**
	 * Test if the hooks are only registered for users with the capability for WebDAV.
	 *
	 * @return void
	 */
	public function test_init(): void {
		$webdav = $this->get_webdav();

		// test 1: without the capability.
		$webdav->init();
		$this->assertFalse( has_filter( 'efml_protocols', array( $webdav, 'add_protocol' ) ) );

		// test 2: with the capability.
		$this->do_login();
		$webdav->init();
		$this->assertIsInt( has_filter( 'efml_protocols', array( $webdav, 'add_protocol' ) ) );
		$this->assertIsInt( has_filter( 'efmlwd_service_webdav_hide_file', array( $webdav, 'prevent_not_allowed_files' ) ) );
		$this->assertIsInt( has_filter( 'efmlwd_service_webdav_client', array( $webdav, 'ignore_self_signed_ssl' ) ) );
		$this->assertIsInt( has_filter( 'efmlwd_service_webdav_path', array( $webdav, 'set_path' ) ) );
		$this->assertIsInt( has_action( 'efml_export_before_on_service', array( $webdav, 'enable_unsafe_urls_for_export' ) ) );
		$this->assertIsInt( has_action( 'efml_proxy_before', array( $webdav, 'enable_unsafe_urls_for_proxy' ) ) );
		$this->assertEquals( 'Choose file(s) from your WebDAV', $webdav->get_title() );
	}

	/**
	 * Test if the settings for WebDAV are registered.
	 *
	 * @return void
	 */
	public function test_init_webdav(): void {
		$settings_obj = \ExternalFilesInMediaLibrary\Plugin\Settings::get_instance()->get_settings_obj();
		update_option( 'eml_webdav_credentials_vault', 'global' );

		// test 1: without the capability.
		$this->get_webdav()->init_webdav();
		$this->assertFalse( $settings_obj->get_setting( 'eml_webdav_server' ) );

		// test 2: with the capability.
		$this->do_login();
		$this->get_webdav()->init_webdav();
		foreach ( array( 'eml_webdav_server', 'eml_webdav_login', 'eml_webdav_password', 'eml_webdav_path', 'eml_webdav_ignore_ssl' ) as $setting_name ) {
			$this->assertInstanceOf( \easySettingsForWordPress\Setting::class, $settings_obj->get_setting( $setting_name ), 'Setting ' . $setting_name . ' is missing.' );
		}
		$this->assertEquals( '/remote.php/dav/files/', $settings_obj->get_setting( 'eml_webdav_path' )->get_default() );
	}

	/**
	 * Test if the user settings are only shown in the user mode.
	 *
	 * @return void
	 */
	public function test_add_user_settings(): void {
		$this->do_login();
		update_option( 'eml_user_settings', 1 );
		update_option( 'eml_user_settings_allowed_roles', array( 'administrator' ) );

		// test 1: not in user mode.
		update_option( 'eml_webdav_credentials_vault', 'global' );
		ob_start();
		$this->get_webdav()->add_user_settings( wp_get_current_user() );
		$this->assertEmpty( ob_get_clean() );

		// test 2: in user mode.
		update_option( 'eml_webdav_credentials_vault', 'user' );
		ob_start();
		$this->get_webdav()->add_user_settings( wp_get_current_user() );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="efml-webdav"', $output );
		$this->assertStringContainsString( 'efml-user-settings', $output );
	}
}

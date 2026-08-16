<?php
/**
 * File which handles the WebDAV support as own protocol.
 *
 * @package external-files-in-media-library
 */

namespace ExternalFilesFromWebDav\WebDav;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ExternalFilesFromWebDav\WebDav;
use ExternalFilesInMediaLibrary\ExternalFiles\Import;
use ExternalFilesInMediaLibrary\ExternalFiles\Protocol_Base;
use ExternalFilesInMediaLibrary\ExternalFiles\Protocols\Http;
use ExternalFilesInMediaLibrary\ExternalFiles\Results;
use ExternalFilesInMediaLibrary\ExternalFiles\Results\Url_Result;
use ExternalFilesInMediaLibrary\Plugin\Helper;
use ExternalFilesInMediaLibrary\Plugin\Log;
use Sabre\HTTP\ClientHttpException;
use Error;
use WP_Filesystem_Base;

/**
 * Object to handle different protocols.
 */
class Protocol extends Protocol_Base {
	/**
	 * Internal protocol name.
	 *
	 * @var string
	 */
	protected string $name = 'webdav';

	/**
	 * List of supported tcp protocols with their ports.
	 *
	 * @var array<string,int>
	 */
	protected array $tcp_protocols = array(
		'http'  => 80,
		'https' => 443,
	);

	/**
	 * Return whether the file using this protocol is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Check if URL is compatible with this protocol.
	 *
	 * @return bool
	 */
	public function is_url_compatible(): bool {
		// get listing_base_object_name from request.
		$service_name = filter_input( INPUT_POST, 'listing_base_object_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// try to get service name from other request param, if it is not yes set.
		if ( is_null( $service_name ) ) {
			$service_name = filter_input( INPUT_POST, 'service', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		}

		// try to get service name from other request param, if it is not yes set.
		if ( is_null( $service_name ) ) {
			$service_name = filter_input( INPUT_POST, 'method', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		}

		// try to get the service name by comparing the fields of a running import with the fields for WebDav.
		if ( is_null( $service_name ) ) {
			$request_fields = Import::get_instance()->get_fields();
			$webdav_fields  = WebDav::get_instance()->get_fields();
			if ( ! empty( $request_fields ) && array_keys( $request_fields ) === array_keys( $webdav_fields ) ) {
				$service_name = WebDav::get_instance()->get_name();
			}
		}

		// return result of comparing the given service name with ours.
		return WebDav::get_instance()->get_name() === $service_name;
	}

	/**
	 * Check format of given URL.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return bool
	 */
	public function check_url( string $url ): bool {
		// bail if empty URL is given.
		if ( empty( $url ) ) {
			return false;
		}

		// return true as WebDav URLs are available.
		return true;
	}

	/**
	 * Return infos to each given URL.
	 *
	 * @return array<int|string,array<string,mixed>> List of files with its infos.
	 */
	public function get_url_infos(): array {
		$directory = $this->get_url();

		// get the staring directory.
		$parse_url = wp_parse_url( $this->get_url() );

		// bail if scheme or host is not found in directory URL.
		if ( ! isset( $parse_url['scheme'], $parse_url['host'] ) ) {
			// create the error entry.
			$error_obj = new Url_Result();
			$error_obj->set_result_text( __( 'Got faulty URL.', 'external-files-from-webdav' ) );
			$error_obj->set_url( $this->get_url() );
			$error_obj->set_error( true );

			// add the error object to the list of errors.
			Results::get_instance()->add( $error_obj );

			// do nothing more.
			return array();
		}

		// set the requested domain.
		$domain = $parse_url['scheme'] . '://' . $parse_url['host'];

		// get the path.
		$path = isset( $parse_url['path'] ) ? $parse_url['path'] : '';

		$fields = $this->get_fields();
		/**
		 * Filter the WebDAV path.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string $path The path to use after the given domain.
		 * @param array $fields The login to use.
		 * @param string $domain The domain to use.
		 * @param string $directory The requested URL.
		 */
		$path = apply_filters( 'efmlwd_service_webdav_path', $path, $fields, $domain, $directory );

		// create settings array for request.
		$settings = array(
			'baseUri'  => $domain . $path,
			'userName' => $fields['login']['value'],
			'password' => $fields['password']['value'],
		);

		/**
		 * Filter the WebDAV settings.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,string> $settings The settings to use.
		 * @param string $domain The domain to use.
		 * @param string $directory The requested URL.
		 */
		$settings = apply_filters( 'efmlwd_service_webdav_settings', $settings, $domain, $directory );

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// get a new client.
		$client = WebDav::get_instance()->get_client( $settings, $domain, $directory );

		// get the object by direct request.
		try {
			$directory_list = $client->propFind( '', array(), 1 );
		} catch ( \Sabre\HTTP\ClientHttpException | \Sabre\HTTP\ClientException | Error $e ) {
			$error_obj = new Url_Result();
			$error_obj->set_result_text( __( 'Error occurred during requesting the directory listing.', 'external-files-from-webdav' ) );
			$error_obj->set_url( $this->get_url() );
			$error_obj->set_error( true );
			Results::get_instance()->add( $error_obj );

			Log::get_instance()->create( __( 'The following error occurred:', 'external-files-from-webdav' ) . ' <code>' . $e->getMessage() . '</code>', $directory, 'error' );

			return array();
		}

		// bail if returned array contains only 1 entry and index for the path does not exist.
		if ( 1 === count( $directory_list ) && empty( $directory_list[ $path ] ) ) {
			// create the error entry.
			$error_obj = new Url_Result();
			$error_obj->set_result_text( __( 'Got empty response from WebDAV for given file.', 'external-files-from-webdav' ) );
			$error_obj->set_url( $this->get_url() );
			$error_obj->set_error( true );

			// add the error object to the list of errors.
			Results::get_instance()->add( $error_obj );

			// do nothing more.
			return array();
		}

		// collect the files.
		$listing = array();

		// set the used domain.
		$url = $domain . $path;

		/**
		 * Run action if we have files to check via WebDav-protocol.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string        $url            The URL to import.
		 * @param array<string> $directory_list List of matches (the URLs).
		 */
		do_action( 'efmlwd_directory_import_files', $url, $directory_list );

		// collect errors.
		$errors = array();

		// loop through the results and add each to the response.
		foreach ( $directory_list as $file_name => $setting ) {
			// get the file URL.
			$file_url = $domain . $file_name;

			$false = false;
			/**
			 * Filter whether given WebDAV file should be hidden.
			 *
			 * @since        1.0.0 Available since 1.0.0.
			 *
			 * @param bool                $false     True if it should be hidden.
			 * @param array<string,mixed> $file      The array with the file data.
			 * @param string              $file_name The requested file.
			 *
			 * @noinspection PhpConditionAlreadyCheckedInspection
			 */
			if ( apply_filters( 'efmlwd_service_webdav_hide_file', $false, $settings, $file_name ) ) {
				continue;
			}

			// check for duplicate.
			if ( $this->check_for_duplicate( $file_url ) || $this->check_for_duplicate( urldecode( $file_url ) ) ) {
				Log::get_instance()->create( __( 'The given file already exist in your media library.', 'external-files-in-media-library' ), esc_url( $file_url ), 'error', 0, Import::get_instance()->get_identifier() );

				// bail on a duplicate file.
				continue;
			}

			/**
			 * Run action just before the file check via WebDAV-protocol.
			 *
			 * @since 1.0.0 Available since 1.0.0.
			 *
			 * @param string $file_url The URL to import.
			 */
			do_action( 'efmlwd_directory_import_file_check', $file_url );

			// bail if resource type is not null.
			if ( ! is_null( $setting['{DAV:}resourcetype'] ) ) {
				continue;
			}

			// initialize basic array for file data.
			$results = array(
				'title'         => basename( urldecode( $file_name ) ),
				'local'         => true,
				'url'           => $file_url,
				'last-modified' => absint( strtotime( $setting['{DAV:}getlastmodified'] ) ),
			);

			// get mime type.
			$mime_type = wp_check_filetype( $results['title'] );

			// set the file size.
			$results['filesize'] = absint( $setting['{DAV:}getcontentlength'] );

			// set the mime type.
			$results['mime-type'] = $mime_type['type'];

			// set the file as tmp-file for import.
			$results['tmp-file'] = wp_tempnam();

			// set settings for new sabre-client object.
			try {
				$settings = array(
					'baseUri'  => $file_url,
					'userName' => $fields['login']['value'],
					'password' => $fields['password']['value'],
				);

				// get a new client.
				$client = WebDav::get_instance()->get_client( $settings, $domain, $directory );

				// get the file data.
				$file_data = $client->request( 'GET' );

				// save the content.
				$wp_filesystem->put_contents( $results['tmp-file'], $file_data['body'] );
			} catch ( \Sabre\HTTP\ClientHttpException | \Sabre\HTTP\ClientException | Error $e ) {
				// clean up the tmp file.
				$wp_filesystem->delete( $results['tmp-file'] );

				// collect the error.
				$errors[ $file_url ] = $e->getMessage();

				// get next file.
				continue;
			}

			// add the file to the list.
			$listing[] = $results;
		}

		// collect all errors in one log entry.
		if ( ! empty( $errors ) ) {
			$error_lines = array();
			foreach ( $errors as $failed_url => $message ) {
				$error_lines[] = '<code>' . esc_html( $failed_url ) . '</code>: <code>' . esc_html( $message ) . '</code>';
			}

			Log::get_instance()->create(
				sprintf(
				/* translators: %1$d will be replaced by the number of failed files, %2$s by the list of errors. */
					_n(
						'%1$d file could not be loaded during WebDAV import:<br><br>%2$s',
						'%1$d files could not be loaded during WebDAV import:<br><br>%2$s',
						count( $errors ),
						'external-files-from-webdav'
					),
					count( $errors ),
					implode( '<br>', $error_lines )
				),
				$directory,
				'error'
			);
		}

		// return the resulting array as list of files (although it is only one).
		return $listing;
	}

	/**
	 * Return infos about single given URL.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return array<string,mixed>
	 */
	public function get_url_info( string $url ): array {
		$directory = $url;

		// get the staring directory.
		$parse_url = wp_parse_url( $url );

		// bail if scheme or host is not found in directory URL.
		if ( ! isset( $parse_url['scheme'], $parse_url['host'] ) ) {
			// create the error entry.
			$error_obj = new Url_Result();
			$error_obj->set_result_text( __( 'Got faulty URL.', 'external-files-from-webdav' ) );
			$error_obj->set_url( $url );
			$error_obj->set_error( true );

			// add the error object to the list of errors.
			Results::get_instance()->add( $error_obj );

			// do nothing more.
			return array();
		}

		// set the requested domain.
		$domain = $parse_url['scheme'] . '://' . $parse_url['host'];

		// get the path.
		$path = isset( $parse_url['path'] ) ? $parse_url['path'] : '';

		$fields = $this->get_fields();
		/**
		 * Filter the WebDAV path.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string $path The path to use after the given domain.
		 * @param array $fields The login to use.
		 * @param string $domain The domain to use.
		 * @param string $directory The requested URL.
		 */
		$path = apply_filters( 'efmlwd_service_webdav_path', $path, $fields, $domain, $directory );

		// create settings array for request.
		$settings = array(
			'baseUri'  => $domain . $path,
			'userName' => $fields['login']['value'],
			'password' => $fields['password']['value'],
		);

		/**
		 * Filter the WebDAV settings.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,string> $settings The settings to use.
		 * @param string $domain The domain to use.
		 * @param string $directory The requested URL.
		 */
		$settings = apply_filters( 'efmlwd_service_webdav_settings', $settings, $domain, $directory );

		// get a new client.
		$client = WebDav::get_instance()->get_client( $settings, $domain, $directory );

		// get the object by direct request.
		$file_data = $client->propFind( $path, array() );

		// bail if we got an empty response.
		if ( empty( $file_data ) ) {
			// create the error entry.
			$error_obj = new Url_Result();
			$error_obj->set_result_text( __( 'Got empty response from WebDAV for given file.', 'external-files-from-webdav' ) );
			$error_obj->set_url( $this->get_url() );
			$error_obj->set_error( true );

			// add the error object to the list of errors.
			Results::get_instance()->add( $error_obj );

			// do nothing more.
			return array();
		}

		// initialize the file infos array.
		$results = array(
			'title'         => basename( $path ),
			'filesize'      => $file_data['{DAV:}getcontentlength'],
			'mime-type'     => $file_data['{DAV:}getcontenttype'],
			'local'         => true,
			'url'           => $url,
			'last-modified' => $file_data['{DAV:}getlastmodified'],
		);

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// get the tmp file.
		$tmp_file = $this->get_temp_file( $url, $wp_filesystem );
		if ( is_string( $tmp_file ) ) {
			$results['tmp-file'] = $tmp_file;
		}

		// return the resulting data for the file.
		return $results;
	}

	/**
	 * Return whether the file should be saved local (true) or not (false).
	 *
	 * @return bool
	 */
	public function should_be_saved_local(): bool {
		return true;
	}

	/**
	 * Return whether this URL could change its hosting.
	 *
	 * @return bool
	 */
	public function can_change_hosting(): bool {
		return false;
	}

	/**
	 * Return the title of this protocol object.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return WebDav::get_instance()->get_label(); // @phpstan-ignore method.notFound
	}

	/**
	 * Return whether this URL could be checked for availability.
	 *
	 * @return bool
	 */
	public function can_check_availability(): bool {
		return false;
	}

	/**
	 * Return temp file from given URL.
	 *
	 * @param string             $url The given URL.
	 * @param WP_Filesystem_Base $filesystem The file system handler.
	 *
	 * @return bool|string
	 */
	public function get_temp_file( string $url, WP_Filesystem_Base $filesystem ): bool|string {
		// get the HTTP protocol handler.
		$http_protocol_handler = new Http( $url );
		$http_protocol_handler->set_fields( $this->get_fields() );

		// return the results from the HTTP handler.
		return $http_protocol_handler->get_temp_file( $url, $filesystem );
	}
}

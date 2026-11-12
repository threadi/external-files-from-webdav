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
	 * Return the data for the requests to the WebDAV server for the URL of this object.
	 *
	 * Adds an error to the results, if the URL is faulty.
	 *
	 * @return array{domain: string, path: string, settings: array<string,string>, fields: array<string,array<string,mixed>>}|false
	 */
	private function get_request_data(): array|false {
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
			return false;
		}

		// set the requested domain.
		$domain = WebDav::get_instance()->get_domain_of_url( $parse_url );

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

		// return the data.
		return array(
			'domain'   => $domain,
			'path'     => $path,
			'settings' => $settings,
			'fields'   => $fields,
		);
	}

	/**
	 * Return whether this protocol could list the files of a directory page by page.
	 *
	 * @return bool
	 */
	public function supports_file_list(): bool {
		return true;
	}

	/**
	 * Return whether the URL of this object is a directory (a URL with a trailing slash).
	 *
	 * Directories without trailing slash are still imported with all files in one request.
	 *
	 * @return bool
	 */
	public function is_directory(): bool {
		// get the path.
		$path = wp_parse_url( $this->get_url(), PHP_URL_PATH );

		// return whether it is the root or ends with a slash.
		return ! is_string( $path ) || '' === $path || str_ends_with( $path, '/' );
	}

	/**
	 * Return one page of the files in the directory of the URL of this object.
	 *
	 * Like the import of a directory, only the files directly in the directory are used. WebDAV returns the
	 * complete list of a directory with one request, which is loaded only once per request.
	 *
	 * @param string $cursor The cursor for the requested page.
	 *
	 * @return array{files: array<int,array{url: string, media-url?: string, filesize?: int, last-modified?: int}>, cursor: string}|false
	 */
	public function get_file_list( string $cursor ): array|false {
		// get the complete list of the directory, loaded once per request.
		$files = $this->get_cached_file_list(
			function () {
				// get the data for the request.
				$request_data = $this->get_request_data();

				// bail if URL is faulty.
				if ( false === $request_data ) {
					return false;
				}

				// get the list of the directory.
				try {
					$client         = WebDav::get_instance()->get_client( $request_data['settings'], $request_data['domain'], $this->get_url() );
					$directory_list = $client->propFind( '', array( '{DAV:}resourcetype', '{DAV:}getcontentlength', '{DAV:}getlastmodified' ), 1 );
				} catch ( \Sabre\HTTP\ClientHttpException | \Sabre\HTTP\ClientException | Error $e ) {
					Log::get_instance()->create( __( 'The following error occurred:', 'external-files-from-webdav' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', $this->get_url(), 'error', 0, Import::get_instance()->get_identifier() );
					return false;
				}

				// collect the files.
				$files = array();
				foreach ( $directory_list as $file_name => $setting ) {
					// bail if this is not a file (e.g. the directory itself or a subdirectory).
					if ( ! is_null( $setting['{DAV:}resourcetype'] ?? null ) ) {
						continue;
					}

					$false = false;
					/** This filter is documented in app/WebDav/Protocol.php */
					if ( apply_filters( 'efmlwd_service_webdav_hide_file', $false, $request_data['settings'], $file_name ) ) {
						continue;
					}

					// add the file.
					$files[] = array(
						'url'           => $request_data['domain'] . $file_name,
						'filesize'      => absint( $setting['{DAV:}getcontentlength'] ?? 0 ),
						'last-modified' => absint( strtotime( (string) ( $setting['{DAV:}getlastmodified'] ?? '' ) ) ),
					);
				}

				// return the list.
				return $files;
			},
			'' === $cursor
		);

		// bail on errors.
		if ( false === $files ) {
			return false;
		}

		// return the requested page.
		return $this->get_file_list_page( $files, $cursor );
	}

	/**
	 * Return infos to each given URL.
	 *
	 * @return array<int|string,array<string,mixed>> List of files with its infos.
	 */
	public function get_url_infos(): array {
		$directory = $this->get_url();

		// get the data for the request.
		$request_data = $this->get_request_data();

		// bail if URL is faulty.
		if ( false === $request_data ) {
			return array();
		}

		// get the data.
		$domain   = $request_data['domain'];
		$path     = $request_data['path'];
		$settings = $request_data['settings'];
		$fields   = $request_data['fields'];

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

				// save the file directly into the tmp file.
				WebDav::get_instance()->download_file( $client, $results['tmp-file'] );
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
		$domain = WebDav::get_instance()->get_domain_of_url( $parse_url );

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
		try {
			$file_data = $client->propFind( $path, array() );
		} catch ( ClientHttpException | \Sabre\HTTP\ClientException | Error $e ) {
			// create the error entry.
			$error_obj = new Url_Result();
			$error_obj->set_result_text( __( 'Error occurred during requesting the directory listing.', 'external-files-from-webdav' ) );
			$error_obj->set_url( $url );
			$error_obj->set_error( true );

			// add the error object to the list of errors.
			Results::get_instance()->add( $error_obj );

			// log this event.
			Log::get_instance()->create( __( 'The following error occurred:', 'external-files-from-webdav' ) . ' <code>' . $e->getMessage() . '</code>', $url, 'error' );

			// do nothing more.
			return array();
		}

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
			'title'         => basename( urldecode( $path ) ),
			'filesize'      => absint( $file_data['{DAV:}getcontentlength'] ?? 0 ),
			'mime-type'     => $file_data['{DAV:}getcontenttype'] ?? '',
			'local'         => true,
			'url'           => $url,
			'last-modified' => $file_data['{DAV:}getlastmodified'] ?? '',
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
		// parse the URL.
		$parse_url = wp_parse_url( $url );

		// bail if scheme or host is not found in the URL.
		if ( ! isset( $parse_url['scheme'], $parse_url['host'] ) ) {
			return false;
		}

		// get the fields.
		$fields = $this->get_fields();

		// set the requested domain.
		$domain = WebDav::get_instance()->get_domain_of_url( $parse_url );

		// create the settings for the client, like for the import of single files.
		$settings = array(
			'baseUri'  => $url,
			'userName' => isset( $fields['login']['value'] ) ? (string) $fields['login']['value'] : '',
			'password' => isset( $fields['password']['value'] ) ? (string) $fields['password']['value'] : '',
		);

		// get the tmp file.
		$tmp_file = wp_tempnam();

		// download the file via the WebDAV client, so the settings via hooks and the authentication methods of the client are used.
		try {
			// get a new client.
			$client = WebDav::get_instance()->get_client( $settings, $domain, $url );

			// save the file directly into the tmp file.
			WebDav::get_instance()->download_file( $client, $tmp_file );
		} catch ( \Sabre\HTTP\ClientHttpException | \Sabre\HTTP\ClientException | Error $e ) {
			// clean up the tmp file.
			$filesystem->delete( $tmp_file );

			// log this event.
			Log::get_instance()->create( __( 'File could not be loaded from WebDAV:', 'external-files-from-webdav' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', $url, 'error' );

			// return false as we got no file.
			return false;
		}

		// return the tmp file.
		return $tmp_file;
	}
}

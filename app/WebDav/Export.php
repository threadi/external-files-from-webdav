<?php
/**
 * File to handle export tasks for WebDAV.
 *
 * @package external-files-in-media-library
 */

namespace ExternalFilesFromWebDav\WebDav;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Exception;
use ExternalFilesFromWebDav\WebDav;
use ExternalFilesInMediaLibrary\ExternalFiles\Export_Base;
use ExternalFilesInMediaLibrary\Plugin\Helper;
use ExternalFilesInMediaLibrary\Plugin\Log;
use Sabre\DAV\Client;
use Sabre\HTTP\ClientHttpException;

/**
 * Object for export files to AWS S3.
 */
class Export extends Export_Base {
	/**
	 * Instance of actual object.
	 *
	 * @var Export|null
	 */
	private static ?Export $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Export
	 */
	public static function get_instance(): Export {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Export a file to this service. Returns the external URL if it was successfully and false if not.
	 *
	 * @param int                 $attachment_id The attachment ID.
	 * @param string              $target The target.
	 * @param array<string,mixed> $credentials The credentials.
	 * @return string|bool
	 */
	public function export_file( int $attachment_id, string $target, array $credentials ): string|bool {
		// match the variables for hook documentation.
		$directory = $target;

		// get the main object.
		$webdav_object = WebDav::get_instance();

		// get the starting directory.
		$parse_url = wp_parse_url( $target );

		// bail if scheme or host is not found in directory URL.
		if ( ! isset( $parse_url['scheme'], $parse_url['host'], $parse_url['path'] ) ) {
			return false;
		}

		// get the fields.
		$fields = $webdav_object->get_fields_for_url( $credentials['fields'], $target );

		// set the requested domain.
		$domain = $parse_url['scheme'] . '://' . $parse_url['host'];

		// create settings array for request.
		$settings = array(
			'baseUri'  => $domain,
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
		$client = $webdav_object->get_client( $settings, $domain, $directory );

		// get the file path.
		$file_path = get_attached_file( $attachment_id, true );

		// bail if no file could be found.
		if ( ! is_string( $file_path ) ) {
			// log this event.
			Log::get_instance()->create( __( 'Could not load file path for given attachment ID.', 'external-files-from-webdav' ), $target, 'error' );

			// do nothing more.
			return false;
		}

		// get the local "WP_Filesystem".
		$wp_filesystem_local = Helper::get_wp_filesystem();

		// bail if source file does not exist.
		if ( ! $wp_filesystem_local->exists( $file_path ) ) {
			// log this event.
			Log::get_instance()->create( __( 'Given file does not exist:', 'external-files-from-webdav' ) . ' <code>' . wp_json_encode( $file_path ) . '</code>', $target, 'error' );

			// do nothing more.
			return false;
		}

		// set the upload path we will use for this file.
		$upload_path = $this->get_upload_path( $fields, $parse_url['path'] );

		// create the folders of the target below the directory, if they do not exist (e.g., for the folder structure of exports).
		$folders = $this->get_folders_from_target( $target, $credentials );
		if ( ! empty( $folders ) && ! $this->create_folders( $client, $upload_path, $folders, $target ) ) {
			return false;
		}

		// upload the file to the given path.
		try {
			$result = $client->request( 'PUT', $upload_path, (string) $wp_filesystem_local->get_contents( $file_path ) );
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->create( __( 'Error occurred during request to export a file to WebDav:', 'external-files-from-webdav' ) . ' <code>' . $e->getMessage() . '</code>', $target, 'error' );

			// do nothing more.
			return false;
		}

		// list of allowed status codes.
		$status_codes = array( 201, 204 );
		/**
		 * Filter the statusCode after exporting a file to WebDav.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,int> $status_codes List of allowed status codes.
		 */
		if ( ! in_array( absint( $result['statusCode'] ), apply_filters( 'efmlwd_service_webdav_status_codes', $status_codes ), true ) ) {
			// log this event.
			Log::get_instance()->create( __( 'Got unsupported status code from WebDav during export of a file:', 'external-files-from-webdav' ) . ' <code>' . wp_json_encode( $result ) . '</code>', $target, 'error' );

			// do nothing more.
			return false;
		}

		// return the given target URL.
		return $domain . $upload_path;
	}

	/**
	 * Delete an exported file.
	 *
	 * @param string              $url           The URL to delete.
	 * @param array<string,mixed> $credentials   The credentials to use.
	 * @param int                 $attachment_id The attachment ID.
	 *
	 * @return bool
	 */
	public function delete_exported_file( string $url, array $credentials, int $attachment_id ): bool {
		// map for hooks.
		$directory = $url;

		// get the main object.
		$webdav_object = WebDav::get_instance();

		// get the starting directory.
		$parse_url = wp_parse_url( $directory );

		// bail if scheme or host is not found in directory URL.
		if ( ! isset( $parse_url['scheme'], $parse_url['host'], $parse_url['path'] ) ) {
			return false;
		}

		// get the fields.
		$fields = $webdav_object->get_fields_for_url( $credentials['fields'], $directory );

		// set the requested domain.
		$domain = $parse_url['scheme'] . '://' . $parse_url['host'];

		// create settings array for request.
		$settings = array(
			'baseUri'  => $domain,
			'userName' => $fields['login']['value'],
			'password' => $fields['password']['value'],
		);

		/**
		 * Filter the WebDAV settings.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,string> $settings  The settings to use.
		 * @param string               $domain    The domain to use.
		 * @param string               $directory The requested URL.
		 */
		$settings = apply_filters( 'efmlwd_service_webdav_settings', $settings, $domain, $directory );

		// set the upload path we will use for this file.
		$upload_path = $this->get_upload_path( $fields, $parse_url['path'] );

		// get a new client.
		$client = $webdav_object->get_client( $settings, $domain, $directory );

		// delete the file.
		try {
			$result = $client->request( 'DELETE', $upload_path );
		} catch ( Exception $e ) {
			// log this event.
			Log::get_instance()->create( __( 'Error occurred during request to delete a file on WebDav:', 'external-files-from-webdav' ) . ' <code>' . $e->getMessage() . '</code>', $upload_path, 'error' );

			// return empty array to not load anything more.
			return false;
		}

		// list of allowed status codes.
		$status_codes = array( 201, 204 );
		/**
		 * Filter the statusCode after exporting a file to WebDav.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,int> $status_codes List of allowed status codes.
		 */
		if ( ! in_array( absint( $result['statusCode'] ), apply_filters( 'efmlwd_service_webdav_status_codes', $status_codes ), true ) ) {
			// log this event.
			Log::get_instance()->create( __( 'Got unsupported status code from WebDav during deletion of a file:', 'external-files-from-webdav' ) . ' <code>' . wp_json_encode( $result ) . '</code>', $upload_path, 'error' );

			// do nothing more.
			return false;
		}

		// return true as the file has been deleted.
		return true;
	}

	/**
	 * Return whether this service supports folders for exported files.
	 *
	 * @return bool
	 */
	public function supports_folders(): bool {
		return true;
	}

	/**
	 * Return the folders of the given target below the directory of the external source.
	 *
	 * Returns an empty list if the target is located directly in the directory of the external source
	 * or not located in it at all.
	 *
	 * @param string              $target The target path of the file.
	 * @param array<string,mixed> $credentials The credentials of the external source.
	 *
	 * @return array<int,string>
	 */
	private function get_folders_from_target( string $target, array $credentials ): array {
		// get the directory from the credentials.
		$directory = isset( $credentials['directory'] ) && is_string( $credentials['directory'] ) ? trailingslashit( $credentials['directory'] ) : '';

		// bail if no directory is set or the target is not located in it.
		if ( '' === $directory || ! str_starts_with( $target, $directory ) ) {
			return array();
		}

		// get the folders of the target below the directory.
		$folders = array();
		foreach ( explode( '/', dirname( substr( $target, strlen( $directory ) ) ) ) as $folder ) {
			// bail on relative folders.
			if ( '..' === $folder ) {
				return array();
			}

			// skip empty folders.
			if ( '' === $folder || '.' === $folder ) {
				continue;
			}

			// add the folder.
			$folders[] = $folder;
		}

		// return the list of folders.
		return $folders;
	}

	/**
	 * Create the given folders of the upload path on the WebDAV server, if they do not exist.
	 *
	 * @param Client            $client The WebDAV client.
	 * @param string            $upload_path The path of the file to upload.
	 * @param array<int,string> $folders The folders at the end of the upload path, which should exist.
	 * @param string            $target The target for logging.
	 *
	 * @return bool
	 */
	private function create_folders( Client $client, string $upload_path, array $folders, string $target ): bool {
		// get the path of the folder, which contains the folders.
		$folder_path = dirname( $upload_path );
		$path        = substr( $folder_path, 0, strlen( $folder_path ) - strlen( implode( '/', $folders ) ) );

		// create each folder.
		foreach ( $folders as $folder ) {
			$path = trailingslashit( $path ) . $folder;

			// create the folder.
			try {
				$result = $client->request( 'MKCOL', trailingslashit( $path ) );
			} catch ( ClientHttpException $e ) {
				// the folder does already exist.
				if ( 405 === $e->getHttpStatus() ) {
					continue;
				}

				// log this event.
				Log::get_instance()->create( __( 'Error occurred during request to create a folder on WebDav:', 'external-files-from-webdav' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', $target, 'error' );

				// do nothing more.
				return false;
			} catch ( Exception $e ) {
				// log this event.
				Log::get_instance()->create( __( 'Error occurred during request to create a folder on WebDav:', 'external-files-from-webdav' ) . ' <code>' . esc_html( $e->getMessage() ) . '</code>', $target, 'error' );

				// do nothing more.
				return false;
			}

			// bail if the folder could not be created and does not exist (405 = folder already exists).
			if ( ! in_array( absint( $result['statusCode'] ), array( 201, 405 ), true ) ) {
				// log this event.
				Log::get_instance()->create( __( 'Got unsupported status code from WebDav during creation of a folder:', 'external-files-from-webdav' ) . ' <code>' . wp_json_encode( $result ) . '</code>', $target, 'error' );

				// do nothing more.
				return false;
			}
		}

		// return true as all folders exist.
		return true;
	}

	/**
	 * Return the path of a file on the WebDAV server for the given path from the URL.
	 *
	 * The URL of an external source is the domain (main directory) or the complete URL of a directory
	 * on the WebDAV server (sub-directory). The configured WebDAV path (e.g. "/remote.php/dav/files/") and the
	 * login are only added if they are not already part of the path.
	 *
	 * @param array<string,mixed> $fields The fields of the connection.
	 * @param string              $path   The path from the URL.
	 *
	 * @return string
	 */
	private function get_upload_path( array $fields, string $path ): string {
		// get the base path on the WebDAV server.
		$base_path = $fields['path']['value'] . $fields['login']['value'];

		// return the path as it is, if it already contains the base path.
		if ( str_starts_with( $path, trailingslashit( $base_path ) ) ) {
			return $path;
		}

		// return the path with the base path.
		return $base_path . $path;
	}
}

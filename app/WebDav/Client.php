<?php
/**
 * File for the WebDAV client of this plugin.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\WebDav;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Sabre\HTTP\ClientException;
use Sabre\HTTP\Request;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;

/**
 * Object for the WebDAV client, which could save downloaded files directly into a local file.
 *
 * The original client of Sabre holds the complete response in memory. For large files this could fail at the
 * memory limit. This client writes the body of a download directly into the given file. All other requests
 * are handled by the original client, also all settings (e.g. the credentials or settings via the hook
 * "efmlwd_service_webdav_client") are used for downloads.
 */
class Client extends \Sabre\DAV\Client {
	/**
	 * The handle of the file for the actual download.
	 *
	 * @var resource|null
	 */
	private $download_handle = null;

	/**
	 * The headers of the actual download.
	 *
	 * @var string
	 */
	private string $download_headers = '';

	/**
	 * Download the given URL into the given local file.
	 *
	 * Only the body of a successful response (status 2xx) is written into the file, not the bodies of
	 * authentication challenges or redirects. The file is not deleted if the download fails, this is
	 * the task of the caller.
	 *
	 * @since 1.2.0 Available since 1.2.0.
	 *
	 * @param string $url  The URL, relative to the base URI of this client.
	 * @param string $file The absolute path to the local file.
	 *
	 * @return ResponseInterface The response without its body.
	 * @throws ClientException If the file could not be opened or the request failed. If the response has an error status and exceptions are enabled, a ClientHttpException is thrown.
	 */
	public function download_to_file( string $url, string $file ): ResponseInterface {
		// open the file.
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// bail if the file could not be opened.
		if ( false === $handle ) {
			throw new ClientException( 'Local file could not be opened for the download.' );
		}

		// use this file for the next request.
		$this->download_handle  = $handle;
		$this->download_headers = '';

		try {
			// send the request.
			return $this->send( new Request( 'GET', $this->getAbsoluteUrl( $url ) ) );
		} finally {
			// close the file and reset the settings for further requests.
			$this->download_handle = null;
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Return the curl settings for the given request.
	 *
	 * During a download the headers and the body are received separately, so the body could be written
	 * directly into the file.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @return array<int,mixed>
	 */
	protected function createCurlSettingsArray( RequestInterface $request ): array {
		// get the settings of the original client.
		$settings = parent::createCurlSettingsArray( $request );

		// bail if this is not a download.
		if ( ! is_resource( $this->download_handle ) ) {
			return $settings;
		}

		// receive the headers separately.
		$settings[ CURLOPT_HEADER ]         = false;
		$settings[ CURLOPT_HEADERFUNCTION ] = function ( $curl_handle, string $header_line ): int {
			$this->download_headers .= $header_line;
			return strlen( $header_line );
		};

		// write the body directly into the file.
		$settings[ CURLOPT_WRITEFUNCTION ] = function ( $curl_handle, string $data ): int {
			// ignore the bodies of authentication challenges, redirects and errors.
			$status = absint( curl_getinfo( $curl_handle, CURLINFO_RESPONSE_CODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $status < 200 || $status >= 300 ) {
				return strlen( $data );
			}

			// bail if the file is not open anymore.
			if ( ! is_resource( $this->download_handle ) ) {
				return 0;
			}

			// write the data - a smaller result than the length of the data stops the transfer with an error.
			$written = fwrite( $this->download_handle, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false === $written ? 0 : $written;
		};

		// return the resulting settings.
		return $settings;
	}

	/**
	 * Run the request.
	 *
	 * During a download the body is written into the file, so the response is created only from the
	 * status and the headers. All other requests are run by the original client.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @return ResponseInterface
	 * @throws ClientException If the request failed (e.g. if the server could not be reached or the file could not be written).
	 */
	protected function doRequest( RequestInterface $request ): ResponseInterface {
		// use the original handling, if this is not a download.
		if ( ! is_resource( $this->download_handle ) ) {
			return parent::doRequest( $request );
		}

		// prepare the request.
		$curl_handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		curl_setopt_array( $curl_handle, $this->createCurlSettingsArray( $request ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// run the request.
		$this->download_headers = '';
		$result                 = curl_exec( $curl_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// bail if the request failed.
		if ( false === $result ) {
			throw new ClientException( esc_html( curl_error( $curl_handle ) ), absint( curl_errno( $curl_handle ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		// get the status of the last response.
		$status = absint( curl_getinfo( $curl_handle, CURLINFO_RESPONSE_CODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// return the response with the headers of the last response (e.g. after an authentication challenge).
		return new Response( $status, $this->get_download_headers() );
	}

	/**
	 * Return the headers of the last response of the actual download.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function get_download_headers(): array {
		// get the header block of the last response.
		$blocks = explode( "\r\n\r\n", trim( $this->download_headers, "\r\n" ) );
		$lines  = explode( "\r\n", (string) end( $blocks ) );

		// remove the status line.
		array_shift( $lines );

		// collect the headers.
		$headers = array();
		foreach ( $lines as $line ) {
			$parts = explode( ':', $line, 2 );

			// bail if this is not a header.
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$headers[ trim( $parts[0] ) ][] = trim( $parts[1] );
		}

		// return the list of headers.
		return $headers;
	}
}

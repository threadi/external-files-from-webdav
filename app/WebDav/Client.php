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
	 * Whether the actual request should send the credentials directly via Basic auth.
	 *
	 * @var bool
	 */
	private bool $preemptive_basic_auth = false;

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

		// send the credentials directly via Basic auth, if requested for an upload as stream.
		if ( $this->preemptive_basic_auth ) {
			$settings[ CURLOPT_HTTPAUTH ] = CURLAUTH_BASIC;
		}

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
		// use the handling for uploads with a stream as body.
		if ( is_resource( $request->getBody() ) ) {
			return $this->do_stream_upload( $request );
		}

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
	 * Run a request with a stream as body (e.g. the upload of a file during the export).
	 *
	 * If the client negotiates the authentication with the server (e.g. for Basic and Digest auth, which is the
	 * default of Sabre), curl first sends the request without credentials and must send the body again after the
	 * challenge of the server. This is not possible with a stream in PHP and fails with "necessary data rewind
	 * wasn't possible". Therefore, the credentials are sent directly via Basic auth for HTTPS connections, so the
	 * stream is sent only once. If the server does not accept this (e.g. as it only supports Digest auth) or the
	 * connection does not use HTTPS, the body is loaded into memory and sent with the negotiation, like before.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @return ResponseInterface
	 * @throws ClientException If the request failed.
	 */
	private function do_stream_upload( RequestInterface $request ): ResponseInterface {
		// get the stream.
		$body = $request->getBody();

		// get the settings of the client (the property is named by Sabre).
		$curl_settings = $this->curlSettings; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// get the authentication methods.
		$auth_methods = isset( $curl_settings[ CURLOPT_HTTPAUTH ] ) ? absint( $curl_settings[ CURLOPT_HTTPAUTH ] ) : CURLAUTH_BASIC;

		// use the original handling, if no negotiation of the authentication is necessary.
		if ( ! is_resource( $body ) || empty( $curl_settings[ CURLOPT_USERPWD ] ) || CURLAUTH_BASIC === $auth_methods ) {
			return parent::doRequest( $request );
		}

		// send the credentials directly via Basic auth, if this is allowed and the connection uses HTTPS.
		$position = ftell( $body );
		if ( ( $auth_methods & CURLAUTH_BASIC ) && str_starts_with( strtolower( $request->getUrl() ), 'https://' ) ) {
			$this->preemptive_basic_auth = true;
			try {
				$response = parent::doRequest( $request );
			} finally {
				$this->preemptive_basic_auth = false;
			}

			// return the response, if the server accepted the credentials.
			if ( 401 !== $response->getStatus() ) {
				return $response;
			}

			// return the response, if the stream could not be used again.
			if ( false === $position || 0 !== fseek( $body, $position ) ) {
				return $response;
			}
		}

		// load the body into memory, so curl could send it again during the negotiation of the authentication.
		$request->setBody( (string) stream_get_contents( $body ) );

		// run the request with the original handling.
		return parent::doRequest( $request );
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

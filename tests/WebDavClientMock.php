<?php
/**
 * File for a WebDAV client, which answers all requests locally.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Tests;

use PHPUnit\Framework\Assert;
use Sabre\DAV\Client;
use Sabre\HTTP\ClientHttpException;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;

/**
 * Object for a WebDAV client without any external communication.
 *
 * It replaces only the sending of the requests, so the requests and the parsing of the responses
 * (e.g. of PROPFIND) are done by the original Sabre client.
 */
class WebDavClientMock extends Client {
	/**
	 * List of responses with "METHOD URL" as index.
	 *
	 * @var array<string,Response|callable|\Throwable>
	 */
	private static array $responses = array();

	/**
	 * List of requests during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $requests = array();

	/**
	 * Create the mock with the settings of the given client.
	 *
	 * @param Client $client The original client.
	 */
	public function __construct( Client $client ) {
		parent::__construct( array( 'baseUri' => $client->baseUri ) );

		// use the same curl settings (e.g. the credentials or the SSL settings).
		$this->curlSettings = $client->curlSettings;

		// use the same handling of errors.
		$this->throwExceptions = $client->throwExceptions;
	}

	/**
	 * Set the response for a request.
	 *
	 * @param string                                $method   The HTTP method.
	 * @param string                                $url      The absolute URL.
	 * @param Response|callable|\Throwable $response The response, a callable which gets the request and returns the response, or an exception.
	 *
	 * @return void
	 */
	public static function add_response( string $method, string $url, Response|callable|\Throwable $response ): void {
		self::$responses[ strtoupper( $method ) . ' ' . $url ] = $response;
	}

	/**
	 * Return the list of requests during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_requests(): array {
		return self::$requests;
	}

	/**
	 * Reset all responses and requests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$responses = array();
		self::$requests  = array();
	}

	/**
	 * Return the base URI of this client.
	 *
	 * @return string
	 */
	public function get_base_uri(): string {
		return (string) $this->baseUri;
	}

	/**
	 * Return the curl settings of this client.
	 *
	 * @return array<int,mixed>
	 */
	public function get_curl_settings(): array {
		return $this->curlSettings;
	}

	/**
	 * Answer the request locally.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @return ResponseInterface
	 * @throws ClientHttpException If the response has an error status and exceptions are enabled.
	 */
	public function send( RequestInterface $request ): ResponseInterface {
		// save the request.
		self::$requests[] = array(
			'method'   => $request->getMethod(),
			'url'      => $request->getUrl(),
			'body'     => $request->getBodyAsString(),
			'base_uri' => $this->baseUri,
			'curl'     => $this->curlSettings,
		);

		// fail for requests without a configured response.
		$index = $request->getMethod() . ' ' . $request->getUrl();
		if ( ! isset( self::$responses[ $index ] ) ) {
			Assert::fail( 'Unexpected WebDAV request during PHP Unit Tests: ' . $index );
		}

		// get the response.
		$response = self::$responses[ $index ];
		if ( $response instanceof \Throwable ) {
			throw $response;
		}
		if ( is_callable( $response ) ) {
			$response = $response( $request );
		}

		// throw an exception for errors like the original client does it.
		if ( $this->throwExceptions && $response->getStatus() >= 400 ) {
			throw new ClientHttpException( $response );
		}

		return $response;
	}
}

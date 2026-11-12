<?php
/**
 * Router for the PHP built-in web server, which answers like a small WebDAV server in the tests of the client.
 *
 * Paths:
 * - /file.bin: returns the file given in the environment variable EFMLWD_TEST_FILE, requires Basic auth.
 * - /redirect.bin: redirects to /file.bin.
 * - /missing.bin: returns 404 with a body.
 * - /upload.bin: saves the body of a PUT request in the file given in EFMLWD_TEST_UPLOAD.
 * - /upload-auth.bin: like /upload.bin, but requires Basic auth.
 *
 * @package external-files-from-webdav
 */

// phpcs:ignoreFile

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

// require Basic auth for the file, so the client first gets a challenge with a body.
if ( '/file.bin' === $path ) {
	if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) || 'user' !== $_SERVER['PHP_AUTH_USER'] || 'secret' !== $_SERVER['PHP_AUTH_PW'] ) {
		http_response_code( 401 );
		header( 'WWW-Authenticate: Basic realm="test"' );
		echo 'body of the authentication challenge';
		return true;
	}
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: ' . filesize( getenv( 'EFMLWD_TEST_FILE' ) ) );
	header( 'X-Test: file' );
	readfile( getenv( 'EFMLWD_TEST_FILE' ) );
	return true;
}

if ( '/redirect.bin' === $path ) {
	http_response_code( 302 );
	header( 'Location: /file.bin' );
	echo 'body of the redirect';
	return true;
}

// require Basic auth for the upload, so the client first gets a challenge, if it negotiates the authentication.
if ( '/upload-auth.bin' === $path && ( ! isset( $_SERVER['PHP_AUTH_USER'] ) || 'user' !== $_SERVER['PHP_AUTH_USER'] || 'secret' !== $_SERVER['PHP_AUTH_PW'] ) ) {
	http_response_code( 401 );
	header( 'WWW-Authenticate: Basic realm="test"' );
	echo 'body of the authentication challenge';
	return true;
}

if ( in_array( $path, array( '/upload.bin', '/upload-auth.bin' ), true ) && 'PUT' === $_SERVER['REQUEST_METHOD'] ) {
	$in  = fopen( 'php://input', 'rb' );
	$out = fopen( getenv( 'EFMLWD_TEST_UPLOAD' ), 'wb' );
	stream_copy_to_stream( $in, $out );
	fclose( $in );
	fclose( $out );
	http_response_code( 201 );
	return true;
}

http_response_code( 404 );
echo 'body of the error page';
return true;

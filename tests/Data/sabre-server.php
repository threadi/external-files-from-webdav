<?php
/**
 * Router for the PHP built-in web server, which runs a real WebDAV server of Sabre for the integration tests.
 *
 * The server uses the directory given in the environment variable EFMLWD_INTEGRATION_ROOT as root and requires
 * Basic auth with the login and password given in EFMLWD_INTEGRATION_LOGIN and EFMLWD_INTEGRATION_PASSWORD.
 *
 * @package external-files-from-webdav
 */

// phpcs:ignoreFile

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// create the server with the root directory.
$server = new Sabre\DAV\Server( new Sabre\DAV\FS\Directory( (string) getenv( 'EFMLWD_INTEGRATION_ROOT' ) ) );
$server->setBaseUri( '/' );

// require Basic auth.
$auth_backend = new Sabre\DAV\Auth\Backend\BasicCallBack(
	static function ( string $login, string $password ): bool {
		return getenv( 'EFMLWD_INTEGRATION_LOGIN' ) === $login && getenv( 'EFMLWD_INTEGRATION_PASSWORD' ) === $password;
	}
);
$server->addPlugin( new Sabre\DAV\Auth\Plugin( $auth_backend ) );

// run the request.
$server->start();
return true;

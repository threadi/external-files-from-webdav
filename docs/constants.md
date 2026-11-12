# Define credentials via constants

You can define the credentials of WebDAV as constants, e.g. in your `wp-config.php`. This keeps them out of the database and out of database backups, and allows different values per environment.

Requires _External Files in Media Library_ 6.0.0 or newer. See also the [documentation of the main plugin](https://github.com/threadi/external-files-in-media-library/blob/master/docs/constants.md).

## How it works

* **All required fields:** The constants are only used if a constant is defined for all required fields (below without "optional"). If one is missing, no constant is used and the settings page shows which are missing.
* A constant replaces the value from the plugin settings, the user profile or a saved external source.
* The location of the credentials could not be changed then. The settings show hints instead of input fields.
* Values of credential fields are never sent to the browser.
* The credentials are only used for connections to the server defined via `EFML_WEBDAV_SERVER`. Saved external sources on other servers are not usable as long as the constants are defined (no opening, import, synchronization or export), files imported before are still delivered. Manual imports from other servers are not possible either.

The name of each constant is `EFML_` + the name of the service + `_` + the name of the field, all in uppercase.

## Constants

```php
define( 'EFML_WEBDAV_SERVER', 'https://nextcloud.example.com' );
define( 'EFML_WEBDAV_LOGIN', 'your-login' );
define( 'EFML_WEBDAV_PASSWORD', 'your-password' );
define( 'EFML_WEBDAV_PATH', '/remote.php/dav/files/' ); // optional
```

If your WebDAV server does not use the default port, add it to the server, e.g. `https://nextcloud.example.com:8443`. The comparison with the server of a URL only uses the host name.

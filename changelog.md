# Changelog

## [Unreleased]

### Added

- Added possibility to define the credentials via constants, e.g. in wp-config.php (requires External Files in Media Library 6.0.0)
- Credentials from constants are only used for the server defined via constant, saved external sources on other servers are not usable then
- Added possibility to define several connections via constants, e.g. for two servers (requires External Files in Media Library 6.0.0)
- Added documentation for the constants in docs/constants.md
- Added support for the folder structure of exported files, missing folders are created (requires External Files in Media Library 6.0.0)
- Added support for private files (requires External Files in Media Library 6.0.0): files are delivered via the cache of the proxy, which loads them with the credentials of the external source
- Added support for the connection test in the directory listing (requires External Files in Media Library 6.0.0)
- Added integration tests against a real WebDAV server of Sabre and a GitHub workflow for them
- Directories are imported file by file via the import of External Files in Media Library 6.0.0: also directories with tens of thousands of files, in the background if necessary
- Added integration test for the import of a directory with 1,100 files
- Added search terms for the command palette of WordPress, e.g. other names of the service (requires External Files in Media Library 6.0.0)

### Changed

- Updated German translations

### Fixed

- Fixed high memory usage during the export of large files, they are now uploaded as stream (for this the credentials are sent directly via Basic auth for HTTPS connections, otherwise or if the server requires another authentication method, files are loaded into memory as before)
- Fixed high memory usage during the import of large files and their loading into the cache of the proxy, they are now saved directly into a local file
- The cache of the proxy now loads files via the WebDAV client, so the settings via the hook "efmlwd_service_webdav_client" and the authentication methods of WebDAV (e.g. Digest) are used for it too
- Fixed WebDAV servers with a port in their URL (e.g. https://cloud.example.com:8443), the port was ignored for all requests

## [1.1.0] - 24.10.2026

### Added

- Added PHP Unit tests incl. GitHub action to run them
- Added support for auto-updates via "Update URI"

### Changed

- Updated translations

### Fixed

- Fixed export to external sources in sub-directories of WebDAV
- Fixed missing error handling if WebDAV returns an error during export of a file
- Fixed missing error handling if WebDAV returns an error while requesting a single file
- Fixed detection of set credentials for the connection form
- Fixed double slash in file URLs and encoded directory names in directory listing

## [1.0.3] - 16.08.2026

### Changed

- Now requires PHP 8.2 or newer
- Compatibility with WordPress 7.1
- Updates translations
- Anonymize the password for logging
- Optimized error handling for WebDav connections

### Fixed

- Fixed import of files with special characters from WebDav

## [1.0.2] - 01.05.2026

### Changed

- Optimized export handling regarding file URLs
- Optimized handling of files with special characters in their name
- Updated dependencies

### Fixed

- Fixed missing run of automatic synchronization for WebDav files
- Fixed import of file with not allowed file types during synchronization or mass-import

## [1.0.1] - 29.03.2026

### Changed

- Updated the settings library

## [1.0.0] - 14.01.2026

- Initial release

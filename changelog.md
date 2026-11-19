# Changelog

## [Unreleased]

### Added

- Added support for the new features of External Files in Media Library 6.0.0 (required for the following):
- Added export of image sizes and original images
- Added setup help "Where do I find these details?" for the form and the quickstart
- Added credentials via constants, also for several connections
- Added folder structure for exported files
- Added private files
- Added connection test in the directory listing
- Added import of large directories file by file, also in the background
- Added search terms for the command palette
- Added integration tests with a real WebDAV server

### Changed

- Encrypted values are bound to their purpose
- Updated German translations

### Fixed

- Fixed directory listing with credentials via constants without path
- Fixed delivery of private files to visitors and users without WebDAV capability
- Fixed high memory usage with large files during export, import and proxy caching
- Fixed proxy cache not using the WebDAV client and its authentication methods
- Fixed ports in the URL of WebDAV servers being ignored

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

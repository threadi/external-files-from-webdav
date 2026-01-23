# External files from WebDav in Media Library

## About

This repository provides the features of the WordPress plugin _External Files from WebDav in Media Library_. The repository is used as a basis for deploying the plugin to the WordPress repository. It is not intended to run as a plugin as it is, even if that is possible for development.

## Hint

You will need the plugin _External Files in Media Library_ to run this plugin.

## Usage

After checkout go through the following steps:

### Using ant

1. copy _build/build.properties.dist_ to _build/build.properties_.
2. modify the build/build.properties file - note the comments in the file.
3. after that the plugin can be activated in WordPress.

## Release

### From local environment with ant

1. increase the version number in _build/build.properties_.
2. execute the following command in _build/_: `ant build`
3. after that you will find a zip file in the release directory, which could be used in WordPress to install it.

### On GitHub

1. Create a new tag with the new version number.
2. The release zip will be created by GitHub action.

## Translations

I recommend to use [PoEdit](https://poedit.net/) to translate texts for this plugin.

### generate pot-file

Run in the main directory:

`wp i18n make-pot . languages/external-files-from-webdav.pot`

### update translation-file

1. Open .po-file of the language in PoEdit.
2. Go to "Translate" > "Update from POT-file".
3. After this the new entries are added to the language-file.

### export translation-file

1. Open .po-file of the language in PoEdit.
2. Go to "File" > "Save".
3. Upload the generated .mo-file and the .po-file to the plugin-folder languages/

### generate optimized PHP-file

`wp i18n make-php languages`

## Check for WordPress Coding Standards

### Initialize

`composer install`

### Run

`vendor/bin/phpcs --standard=ruleset.xml .`

### Repair

`vendor/bin/phpcbf --standard=ruleset.xml .`

## Generate documentation

`vendor/bin/wp-documentor parse app --format=markdown --output=docs/hooks.md --prefix=emlwd_`

## Check for WordPress VIP Coding Standards

Hint: this check runs against the VIP-GO-platform which is not our target for this plugin. Many warnings can be ignored.

### Run

`vendor/bin/phpcs --extensions=php --ignore=*/vendor/* --standard=WordPress-VIP-Go .`

## Check PHP compatibility

`vendor/bin/phpcs -p app --standard=PHPCompatibilityWP`

## Analyse with PHPStan

`vendor/bin/phpstan analyse`

## Check with the plugin "Plugin Check"

`wp plugin check --error-severity=7 --warning-severity=6 --include-low-severity-errors --categories=plugin_repo --format=json --slug=external-files-in-media-library .`

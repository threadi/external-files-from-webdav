<?php
/**
 * File, which handles the updates from GitHub repository for this plugin.
 *
 * @package external-files-from-webdav
 */

namespace ExternalFilesFromWebDav\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object to handle updates from GitHub repository for this plugin.
 *
 * The plugin uses the header "Update URI", so WordPress asks this object for updates (and not WordPress.org).
 * This enables the updates and the auto-updates for this plugin.
 */
class Updates {
	/**
	 * The GitHub user.
	 *
	 * @var string
	 */
	private string $github_user = 'threadi';

	/**
	 * The GitHub repository.
	 *
	 * @var string
	 */
	private string $github_repository = 'external-files-from-webdav';

	/**
	 * Name of the transient, which caches the data of the latest release.
	 *
	 * @var string
	 */
	private string $transient_name = 'efmlwd_latest_release';

	/**
	 * Instance of actual object.
	 *
	 * @var ?Updates
	 */
	private static ?Updates $instance = null;

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
	 * @return Updates
	 */
	public static function get_instance(): Updates {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
	}

	/**
	 * Return the update data for this plugin from its latest release on GitHub.
	 *
	 * WordPress decides itself, whether this is an update (newer version) or not.
	 *
	 * @param array<string,mixed>|false $update      The update data.
	 * @param array<string,mixed>       $plugin_data The plugin headers.
	 * @param string                    $plugin_file The plugin file.
	 *
	 * @return array<string,mixed>|false
	 */
	public function check( array|false $update, array $plugin_data, string $plugin_file ): array|false {
		// bail if this is not our plugin, as the hook is used for all plugins with a GitHub Update URI.
		if ( plugin_basename( EFMLWD_PLUGIN ) !== $plugin_file ) {
			return $update;
		}

		// bail if we are in development mode.
		if ( wp_is_development_mode( 'plugin' ) ) {
			return $update;
		}

		// get the latest release.
		$release = $this->get_latest_release();

		// bail if no release is available.
		if ( empty( $release ) ) {
			return $update;
		}

		// return the update data.
		return array(
			'slug'         => $this->github_repository,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . $this->github_user . '/' . $this->github_repository,
			'package'      => $release['package'],
			'tested'       => '7.1',
			'requires_php' => '8.2',
			'icons'        => array( 'default' => trailingslashit( plugin_dir_url( EFMLWD_PLUGIN ) ) . 'gfx/logo.png' ),
		);
	}

	/**
	 * Return the version and the ZIP file of the latest release on GitHub.
	 *
	 * The result is cached to respect the rate limit of the GitHub API.
	 *
	 * @return array<string,string>
	 */
	public function get_latest_release(): array {
		// use the cached release, if available.
		$release = get_site_transient( $this->transient_name );
		if ( is_array( $release ) ) {
			return $release;
		}

		// request the latest release from GitHub.
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->github_user . '/' . $this->github_repository . '/releases/latest',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
				),
			)
		);

		// remember errors for one hour, so a failing API is not requested on each page load.
		if ( is_wp_error( $response ) || 200 !== absint( wp_remote_retrieve_response_code( $response ) ) ) {
			set_site_transient( $this->transient_name, array(), HOUR_IN_SECONDS );
			return array();
		}

		// get the release data.
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// get the ZIP file of the release.
		$release = array();
		if ( is_array( $data ) && ! empty( $data['tag_name'] ) && ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				// bail if this is not a ZIP file.
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) || 'application/zip' !== ( $asset['content_type'] ?? '' ) ) {
					continue;
				}

				// use this ZIP file.
				$release = array(
					'version' => (string) preg_replace( '/[^0-9.]/', '', (string) $data['tag_name'] ),
					'package' => (string) $asset['browser_download_url'],
				);
				break;
			}
		}

		// cache the result.
		set_site_transient( $this->transient_name, $release, 6 * HOUR_IN_SECONDS );

		// return the result.
		return $release;
	}

	/**
	 * Remove the cached release data.
	 *
	 * @return void
	 */
	public function delete_cache(): void {
		delete_site_transient( $this->transient_name );
	}
}

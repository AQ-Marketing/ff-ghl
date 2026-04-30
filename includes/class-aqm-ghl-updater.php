<?php
/**
 * AQM GHL Connector GitHub Updater
 * 
 * Simple GitHub updater for the AQM GHL Formidable Connector plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AQM_GHL_Updater {
	
	/**
	 * Plugin file path
	 *
	 * @var string
	 */
	private $file;

	/**
	 * GitHub username
	 *
	 * @var string
	 */
	private $username;

	/**
	 * GitHub repository name
	 *
	 * @var string
	 */
	private $repository;

	/**
	 * GitHub access token (optional)
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Plugin data
	 *
	 * @var array
	 */
	private $plugin_data;

	/**
	 * Plugin basename
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (directory name)
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Initialize the updater
	 *
	 * @param string $file Plugin file path
	 * @param string $username GitHub username
	 * @param string $repository GitHub repository name
	 * @param string $access_token GitHub access token (optional)
	 */
	public function __construct( $file, $username, $repository, $access_token = '' ) {
		// Set class properties
		$this->file = $file;
		$this->username = $username;
		$this->repository = $repository;
		$this->access_token = $access_token;

		// Get plugin data
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		$this->plugin_data = get_plugin_data( $this->file );
		$this->plugin_basename = plugin_basename( $this->file );
		$this->plugin_slug = dirname( $this->plugin_basename );

		// Add filters and actions
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_install', array( $this, 'pre_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'post_install' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
		add_action( 'admin_init', array( $this, 'maybe_reactivate_plugin' ) );
		add_action( 'admin_init', array( $this, 'force_refresh_on_plugins_page' ) );
		add_filter( 'http_request_args', array( $this, 'add_auth_to_download' ), 10, 2 );
		
	}


	/**
	 * Clear update cache (public method)
	 */
	public static function clear_cache() {
		$cache_key = 'aqm_ghl_github_data_' . md5( 'AQ-Marketing' . 'ff-ghl' );
		delete_transient( $cache_key );
		delete_option( '_transient_' . $cache_key );
		delete_option( '_transient_timeout_' . $cache_key );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Check for updates
	 *
	 * @param object $transient Update transient
	 * @return object Modified update transient
	 */
	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		
		// Check if user is on plugins page - if so, force check to ensure latest version is shown
		$screen = get_current_screen();
		$is_plugins_page = ( $screen && $screen->id === 'plugins' ) || 
		                   ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'plugins.php' );
		
		// If on plugins page, force check (bypass cache) to ensure latest version is shown
		$force_check = $is_plugins_page;
		
		// Log for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AQM GHL Updater] Checking for updates. Plugin: ' . $this->plugin_basename . ', Current version: ' . $this->plugin_data['Version'] );
		}
		
		// Get update data from GitHub
		$update_data = $this->get_github_update_data( $force_check );

		if ( ! $update_data ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AQM GHL Updater] No update data received from GitHub' );
			}
			return $transient;
		}
		
		// Clean the tag name by removing the 'v' prefix if it exists
		$latest_version = ltrim( $update_data->tag_name, 'v' );
		$current_version = $this->plugin_data['Version'];
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AQM GHL Updater] Latest version from GitHub: ' . $latest_version . ', Current: ' . $current_version );
		}
		
		// Enhanced version comparison
		$comparison_result = version_compare( $current_version, $latest_version, '<' );
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AQM GHL Updater] Update needed: ' . ( $comparison_result ? 'YES' : 'NO' ) );
		}
		
		// If update data is available and version is newer, add to transient
		if ( $comparison_result ) {
			// Create the plugin info object
			$plugin_info = new stdClass();
			$plugin_info->slug = $this->repository;
			$plugin_info->plugin = $this->plugin_basename;
			$plugin_info->new_version = ltrim( $update_data->tag_name, 'v' );
			$plugin_info->url = $update_data->html_url;
			$plugin_info->package = $update_data->zipball_url;

			// Add to transient
			$transient->response[ $this->plugin_basename ] = $plugin_info;
			
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AQM GHL Updater] Added update to transient. New version: ' . $plugin_info->new_version );
			}
		}
		
		return $transient;
	}
	
	/**
	 * Get GitHub update data
	 *
	 * @param bool $force_check Force check instead of using cached data
	 * @return object|bool GitHub release data or false on failure
	 */
	private function get_github_update_data( $force_check = false ) {
		// Check cache first
		$cache_key = 'aqm_ghl_github_data_' . md5( $this->username . $this->repository );
		
		if ( $force_check ) {
			// Clear cache completely when force check is requested
			delete_transient( $cache_key );
			delete_option( '_transient_' . $cache_key );
			delete_option( '_transient_timeout_' . $cache_key );
		}
		
		$cache = get_transient( $cache_key );

		if ( $cache !== false && ! $force_check ) {
			return $cache;
		}

		// Try releases API first (preferred - gets actual release asset ZIP)
		$api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases";
		
		// Build headers with Authorization if token provided
		$headers = array(
			'Accept' => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' )
		);
		
		// Use Authorization header instead of query parameter (preferred method)
		if ( ! empty( $this->access_token ) ) {
			$headers['Authorization'] = 'token ' . $this->access_token;
		}

		$response = wp_remote_get( $api_url, array(
			'headers' => $headers,
			'timeout' => 30
		) );

		$data = false;

		// Log response for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( is_wp_error( $response ) ) {
				error_log( '[AQM GHL Updater] GitHub API error: ' . $response->get_error_message() );
			} else {
				$response_code = wp_remote_retrieve_response_code( $response );
				error_log( '[AQM GHL Updater] GitHub API response code: ' . $response_code );
				if ( $response_code !== 200 ) {
					error_log( '[AQM GHL Updater] GitHub API response body: ' . wp_remote_retrieve_body( $response ) );
				}
			}
		}

		// If releases API works, use it
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$releases = json_decode( wp_remote_retrieve_body( $response ) );
			
			if ( ! empty( $releases ) && is_array( $releases ) ) {
				// Sort releases by version (most recent first)
				usort( $releases, function( $a, $b ) {
					$version_a = ltrim( $a->tag_name, 'v' );
					$version_b = ltrim( $b->tag_name, 'v' );
					return version_compare( $version_b, $version_a );
				} );
				
				$latest_release = $releases[0];
				
				// Look for the plugin ZIP asset
				$zip_url = false;
				if ( ! empty( $latest_release->assets ) && is_array( $latest_release->assets ) ) {
					foreach ( $latest_release->assets as $asset ) {
						if ( isset( $asset->name ) && strpos( $asset->name, '.zip' ) !== false ) {
							// Use browser_download_url for all cases (works for both public and private repos)
							// The http_request_args filter will add authentication if needed
							$zip_url = $asset->browser_download_url;
							break;
						}
					}
				}
				
				// If we found a ZIP asset, use it; otherwise fall back to zipball
				$data = new stdClass();
				$data->tag_name = $latest_release->tag_name;
				$data->html_url = $latest_release->html_url;
				$data->zipball_url = $zip_url ? $zip_url : "https://github.com/{$this->username}/{$this->repository}/archive/refs/tags/{$latest_release->tag_name}.zip";
			}
		}

		// Fallback to tags API if releases API failed or no release found
		if ( ! $data ) {
			$api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/tags";
			
			// Build headers with Authorization if token provided
			$headers = array(
				'Accept' => 'application/json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' )
			);
			
			// Use Authorization header instead of query parameter
			if ( ! empty( $this->access_token ) ) {
				$headers['Authorization'] = 'token ' . $this->access_token;
			}

			$response = wp_remote_get( $api_url, array(
				'headers' => $headers,
				'timeout' => 30
			) );

			// Check for errors
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			
			// Decode response - tags endpoint returns an array
			$tags = json_decode( wp_remote_retrieve_body( $response ) );
			
			// Check if we have any tags
			if ( empty( $tags ) || ! is_array( $tags ) ) {
				return false;
			}
			
			// Sort tags by version (most recent first)
			usort( $tags, function( $a, $b ) {
				// Remove 'v' prefix for comparison
				$version_a = ltrim( $a->name, 'v' );
				$version_b = ltrim( $b->name, 'v' );
				return version_compare( $version_b, $version_a );
			} );
			
			// Get the first tag (most recent after sorting)
			$latest_tag = $tags[0];
			
			// Create a response object similar to the releases endpoint
			$data = new stdClass();
			$data->tag_name = $latest_tag->name;
			$data->html_url = "https://github.com/{$this->username}/{$this->repository}/releases/tag/{$latest_tag->name}";
			$data->zipball_url = "https://github.com/{$this->username}/{$this->repository}/archive/refs/tags/{$latest_tag->name}.zip";
		}
		
		// Cache for 1 hour
		if ( $data ) {
			set_transient( $cache_key, $data, 1 * HOUR_IN_SECONDS );
		}
		
		return $data;
	}
	
	/**
	 * Get plugin info for the WordPress updates screen
	 *
	 * @param object $result Plugin info result
	 * @param string $action Action being performed
	 * @param object $args Plugin arguments
	 * @return object Modified plugin info result
	 */
	public function plugin_info( $result, $action, $args ) {
		// Check if this is the right plugin
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->repository ) {
			return $result;
		}

		// Get update data from GitHub
		$update_data = $this->get_github_update_data();

		if ( ! $update_data ) {
			return $result;
		}

		// Create the plugin info object
		$plugin_info = new stdClass();
		$plugin_info->name = $this->plugin_data['Name'];
		$plugin_info->slug = $this->repository;
		$plugin_info->version = ltrim( $update_data->tag_name, 'v' );
		$plugin_info->author = $this->plugin_data['Author'];
		$plugin_info->homepage = $this->plugin_data['PluginURI'];
		$plugin_info->requires = '5.0';
		$plugin_info->tested = get_bloginfo( 'version' );
		$plugin_info->downloaded = 0;
		$plugin_info->last_updated = '';
		$plugin_info->sections = array(
			'description' => $this->plugin_data['Description'],
			'changelog' => 'No changelog provided.'
		);
		$plugin_info->download_link = $update_data->zipball_url;

		return $plugin_info;
	}

	/**
	 * Before installation, check if the plugin is active and set a transient
	 *
	 * @param bool $return Whether to proceed with installation
	 * @param array $hook_extra Extra data about the plugin being updated
	 * @return bool Whether to proceed with installation
	 */
	public function pre_install( $return, $hook_extra ) {
		// Check if this is our plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $return;
		}
		
		// Check if the plugin is active
		if ( is_plugin_active( $this->plugin_basename ) ) {
			// Set a transient to reactivate the plugin after update
			set_transient( 'aqm_ghl_was_active', true, 5 * MINUTE_IN_SECONDS );
		}
		
		return $return;
	}

	/**
	 * After installation, check if we need to reactivate the plugin
	 *
	 * @param WP_Upgrader $upgrader_object WP_Upgrader instance
	 * @param array $options Array of bulk item update data
	 */
	public function post_install( $upgrader_object, $options ) {
		// Check if this is a plugin update
		if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
			return;
		}
		
		// Check if our plugin was updated
		if ( ! isset( $options['plugins'] ) || ! in_array( $this->plugin_basename, $options['plugins'] ) ) {
			return;
		}
		
		// Set a transient to reactivate on next admin page load
		set_transient( 'aqm_ghl_reactivate', true, 5 * MINUTE_IN_SECONDS );
		
		// Clear update transients to force WordPress to recheck version
		delete_site_transient( 'update_plugins' );
		
		// Clear our GitHub data cache to force fresh check
		$cache_key = 'aqm_ghl_github_data_' . md5( $this->username . $this->repository );
		delete_transient( $cache_key );
		
		// Refresh plugin data to get new version
		$this->plugin_data = get_plugin_data( $this->file );
		
		// Try to reactivate the plugin now
		if ( get_transient( 'aqm_ghl_was_active' ) ) {
			// Delete the transient
			delete_transient( 'aqm_ghl_was_active' );
			
			// Make sure plugin functions are loaded
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			}
			
			// Reactivate the plugin
			$result = activate_plugin( $this->plugin_basename );
			
			if ( ! is_wp_error( $result ) ) {
				// Clear the reactivation transient since we successfully reactivated
				delete_transient( 'aqm_ghl_reactivate' );
			}
			
			// Clear plugin cache
			wp_clean_plugins_cache( true );
		}
	}
	
	/**
	 * Fix the directory name after extracting the ZIP file
	 *
	 * @param string $source Source directory
	 * @param string $remote_source Remote source directory
	 * @param WP_Upgrader $upgrader WP_Upgrader instance
	 * @param array $hook_extra Extra data about the upgrade
	 * @return string Modified source directory
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		// Check if this is our plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		// Get the expected directory name
		$expected_directory = dirname( $this->plugin_basename );
		
		// Get the current directory name
		$current_directory = basename( $source );
		
		// If the directory names don't match, rename it
		if ( $current_directory !== $expected_directory ) {
			// Build the new path
			$new_source = trailingslashit( dirname( $source ) ) . trailingslashit( $expected_directory );
			
			// If the destination directory already exists, remove it
			if ( is_dir( $new_source ) ) {
				$wp_filesystem = $this->get_filesystem();
				$wp_filesystem->delete( $new_source, true );
			}
			
			// Rename the directory
			if ( rename( $source, $new_source ) ) {
				return $new_source;
			} else {
				// Try an alternative method using the filesystem API
				$wp_filesystem = $this->get_filesystem();
				if ( $wp_filesystem->move( $source, $new_source, true ) ) {
					return $new_source;
				}
			}
		}
		
		return $source;
	}

	/**
	 * Force refresh update check when on plugins page
	 * This ensures new releases are detected immediately when visiting the plugins page
	 */
	public function force_refresh_on_plugins_page() {
		// Check if we're on the plugins page
		$screen = get_current_screen();
		$is_plugins_page = ( $screen && $screen->id === 'plugins' ) || 
		                   ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'plugins.php' );
		
		if ( $is_plugins_page ) {
			// Clear the GitHub data cache to force fresh check
			$cache_key = 'aqm_ghl_github_data_' . md5( $this->username . $this->repository );
			$cached_data = get_transient( $cache_key );
			
			// Only clear if cache exists and might be stale
			if ( $cached_data !== false ) {
				// Get current plugin version
				$current_version = $this->plugin_data['Version'];
				$cached_version = isset( $cached_data->tag_name ) ? ltrim( $cached_data->tag_name, 'v' ) : '';
				
				// If cached version is same or older than current, clear cache to force fresh check
				if ( empty( $cached_version ) || version_compare( $current_version, $cached_version, '>=' ) ) {
					delete_transient( $cache_key );
					// Also delete the timeout option
					delete_option( '_transient_' . $cache_key );
					delete_option( '_transient_timeout_' . $cache_key );
					
					// Also clear WordPress update transients to force recheck
					delete_site_transient( 'update_plugins' );
				}
			}
		}
	}
	
	/**
	 * Check if we need to reactivate the plugin on admin page load
	 */
	public function maybe_reactivate_plugin() {
		// Check if the reactivation transient exists
		if ( get_transient( 'aqm_ghl_reactivate' ) ) {
			// Delete the transient
			delete_transient( 'aqm_ghl_reactivate' );
			
			// Make sure plugin functions are loaded
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			}
			
			// Reactivate the plugin
			$result = activate_plugin( $this->plugin_basename );
			
			if ( ! is_wp_error( $result ) ) {
				// Set a transient to show a notice
				set_transient( 'aqm_ghl_reactivated', true, 30 );
			}
			
			// Clear plugin cache
			wp_clean_plugins_cache( true );
		}
	}
	
	/**
	 * Get the WordPress filesystem
	 *
	 * @return WP_Filesystem_Base WordPress filesystem
	 */
	private function get_filesystem() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
			WP_Filesystem();
		}

		return $wp_filesystem;
	}
	
	/**
	 * Add authentication headers to GitHub download requests
	 *
	 * @param array $args HTTP request arguments
	 * @param string $url Request URL
	 * @return array Modified HTTP request arguments
	 */
	public function add_auth_to_download( $args, $url ) {
		// Only process if URL is provided and is a string
		if ( empty( $url ) || ! is_string( $url ) ) {
			return $args;
		}
		
		// Check if this is a GitHub download URL for our repository
		$github_pattern = 'api.github.com/repos/' . $this->username . '/' . $this->repository;
		$is_github_api = strpos( $url, $github_pattern ) !== false;
		$is_github_release = strpos( $url, 'github.com/' . $this->username . '/' . $this->repository . '/releases/download' ) !== false;
		$is_github_asset = strpos( $url, $github_pattern . '/releases/assets/' ) !== false;
		$is_github_archive = strpos( $url, 'github.com/' . $this->username . '/' . $this->repository . '/archive/refs/tags/' ) !== false;
		
		// Only modify requests for our GitHub repository
		if ( ( $is_github_api || $is_github_release || $is_github_asset || $is_github_archive ) && ! empty( $this->access_token ) ) {
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			
			// Add Authorization header
			$args['headers']['Authorization'] = 'token ' . $this->access_token;
			
			// For API asset downloads, we need Accept: application/octet-stream
			// This tells GitHub to return the actual file content instead of JSON metadata
			if ( $is_github_asset ) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}
		}
		
		return $args;
	}
}


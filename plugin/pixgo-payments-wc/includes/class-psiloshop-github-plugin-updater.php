<?php
/**
 * GitHub manifest based updater for private plugins.
 *
 * @package Psiloshop_Plugin_Updater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Psiloshop_GitHub_Plugin_Updater' ) ) {
	/**
	 * Registers WordPress update checks for a plugin using a JSON manifest.
	 */
	class Psiloshop_GitHub_Plugin_Updater {
		/**
		 * Absolute plugin file path.
		 *
		 * @var string
		 */
		private $plugin_file;

		/**
		 * Plugin basename used by WordPress.
		 *
		 * @var string
		 */
		private $plugin_basename;

		/**
		 * Current plugin version.
		 *
		 * @var string
		 */
		private $version;

		/**
		 * Remote JSON manifest URL.
		 *
		 * @var string
		 */
		private $manifest_url;

		/**
		 * Manifest cache key.
		 *
		 * @var string
		 */
		private $cache_key;

		/**
		 * Creates the updater instance.
		 *
		 * @param string $plugin_file  Absolute plugin file path.
		 * @param string $version      Current plugin version.
		 * @param string $manifest_url Remote JSON manifest URL.
		 */
		public function __construct( $plugin_file, $version, $manifest_url ) {
			$this->plugin_file     = $plugin_file;
			$this->plugin_basename = plugin_basename( $plugin_file );
			$this->version         = $version;
			$this->manifest_url    = $manifest_url;
			$this->cache_key       = 'psiloshop_plugin_update_' . md5( $this->plugin_basename );

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
			add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
		}

		/**
		 * Adds update data to the WordPress plugin update transient.
		 *
		 * @param object $transient WordPress update transient.
		 * @return object
		 */
		public function check_for_update( $transient ) {
			if ( empty( $transient->checked ) || ! isset( $transient->checked[ $this->plugin_basename ] ) ) {
				return $transient;
			}

			$manifest = $this->get_manifest();
			if ( ! $manifest || empty( $manifest['version'] ) || empty( $manifest['download_url'] ) ) {
				return $transient;
			}

			if ( version_compare( $this->version, $manifest['version'], '<' ) ) {
				$transient->response[ $this->plugin_basename ] = $this->build_update_response( $manifest );
			}

			return $transient;
		}

		/**
		 * Shows plugin details in the WordPress update modal.
		 *
		 * @param false|object|array $result Current API result.
		 * @param string             $action API action.
		 * @param object             $args   API args.
		 * @return false|object|array
		 */
		public function plugin_info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
				return $result;
			}

			$manifest = $this->get_manifest();
			if ( ! $manifest || empty( $manifest['slug'] ) || $manifest['slug'] !== $args->slug ) {
				return $result;
			}

			return (object) array(
				'name'          => $manifest['name'] ?? $manifest['slug'],
				'slug'          => $manifest['slug'],
				'version'       => $manifest['version'] ?? $this->version,
				'author'        => $manifest['author'] ?? '',
				'homepage'      => $manifest['homepage'] ?? '',
				'requires'      => $manifest['requires'] ?? '',
				'tested'        => $manifest['tested'] ?? '',
				'requires_php'  => $manifest['requires_php'] ?? '',
				'download_link' => $manifest['download_url'] ?? '',
				'sections'      => $manifest['sections'] ?? array(),
			);
		}

		/**
		 * Clears cached update data after plugin updates.
		 *
		 * @param WP_Upgrader $upgrader Upgrader instance.
		 * @param array       $options  Upgrade options.
		 * @return void
		 */
		public function clear_cache( $upgrader, $options ) {
			if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
				delete_site_transient( $this->cache_key );
			}
		}

		/**
		 * Fetches and caches the remote manifest.
		 *
		 * @return array|null
		 */
		private function get_manifest() {
			$cached = get_site_transient( $this->cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : null;
			}

			$response = wp_remote_get(
				$this->manifest_url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				set_site_transient( $this->cache_key, null, 15 * MINUTE_IN_SECONDS );
				return null;
			}

			$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $manifest ) ) {
				set_site_transient( $this->cache_key, null, 15 * MINUTE_IN_SECONDS );
				return null;
			}

			set_site_transient( $this->cache_key, $manifest, 6 * HOUR_IN_SECONDS );
			return $manifest;
		}

		/**
		 * Builds the update payload expected by WordPress.
		 *
		 * @param array $manifest Remote manifest.
		 * @return object
		 */
		private function build_update_response( array $manifest ) {
			return (object) array(
				'id'            => $this->manifest_url,
				'slug'          => $manifest['slug'] ?? dirname( $this->plugin_basename ),
				'plugin'        => $this->plugin_basename,
				'new_version'   => $manifest['version'],
				'url'           => $manifest['homepage'] ?? '',
				'package'       => $manifest['download_url'],
				'requires'      => $manifest['requires'] ?? '',
				'tested'        => $manifest['tested'] ?? '',
				'requires_php'  => $manifest['requires_php'] ?? '',
				'icons'         => $manifest['icons'] ?? array(),
			);
		}
	}
}

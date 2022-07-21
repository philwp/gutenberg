<?php
/**
 * WP_Plugin_Theme_Data_Registry_6_1 class
 *
 * @package gutenberg
 */

/**
 * Class that acts as a registry for plugin theme data.
 *
 * This class is for internal core usage and is not supposed to be used by extenders (plugins and/or themes).
 * This is a low-level API that may need to do breaking changes.
 *
 * @access private
 */
class WP_Plugin_Theme_Data_Registry_6_1 {

	/**
	 * Container for the main instance of the class. Will be an instance of
	 * WP_Plugin_Theme_Data_Registry from WP core if it exists.
	 *
	 * @var WP_Plugin_Theme_Data_Registry_6_1|WP_Plugin_Theme_Data_Registry|null
	 */
	protected $instance = null;

	protected $plugins_data = array();


	protected function init() {
		// do initial plugin caching if it doesn't exist (cache from db).
		$this->initialize_plugins_theme_json();
		// hook in for cache registration on plugin activation/updates.
		// hook in for cache removal on plugin deactivations.
	}

	protected function initialize_plugins_theme_json() {
		// if WP is installing let's bail.
		if ( wp_is_installing() ) {
			return;
		}
		// check cache from db.
		$plugin_data_from_db = (array) get_option( 'active_plugins_theme_json_data', array() );
		if ( empty( $plugin_data_from_db ) ) {
			$active_plugin_paths = wp_get_active_and_valid_plugins();
			foreach ( $active_plugin_paths as $path ) {
				$this->maybe_register_json_for_plugin_path( $path );
			}
			// if we still don't have any plugin_data, then let's save a special
			// initialized value to prevent recurring initialization.
			if ( empty( $this->plugins_data ) ) {
				$this->plugins_data = array( 'initialized' => '' );
			}
			// after all registrations, update the cache (via the option).
			update_option( 'active_plugins_theme_json_data', $this->plugins_data );
		}
	}

	protected function maybe_register_json_for_plugin_path( $path ) {

	}

	protected function register_theme_json_for_plugin( $slug, $json ) {}
	protected function deregister_theme_json_for_plugin( $slug, $json ) {}

	public function get_registered_theme_data() {
		if ( empty( $this->plugins_data ) ) {
			// need to re-initialize.
		}
		return array_key_exists( 'initialized', $this->plugins_data ) ? array() : $this->plugins_data;
	}

	/**
	 * Utility method to retrieve the main instance of the class.
	 *
	 * The instance will be created if it does not exist yet. Note, an instance of
	 * WP_Plugin_Theme_Data_Registry from WP core will be used if it exists since
	 * it will be the same as the contents of this class.
	 */
	public function get_instance() {
		if ( null === self::instance ) {
			self::$instance = class_exists( 'WP_Plugin_Theme_Data_Registry' )
				? new WP_Plugin_Theme_Data_Registry()
				: new self();
			// initialize hooks.
			self::$instance->init();
		}
		return self::$instance;
	}

	public function clear_cache() {
		update_option( 'active_plugins_theme_json_data', array() );
		$this->plugins_data = array();
	}

}

<?php
/*
Plugin Name: WP TUS Protocol
Description: Add a TUS REST API endpoint for file uploads using the TUS protocol. By default files are added to the WP Media Library but can be customizes with actions/filters. Based on TUS PHP by https://github.com/ankitpokhrel/tus-php
Author: Benjamin Moody
Version: 1.0
*/

/**
 * Current version of plugin
 */
define( 'WP_TUS_PROTOCOL_PLUGIN_VERSION', '1.0' );

/**
 * Filesystem path to plugin
 */
define( 'WP_TUS_PROTOCOL_PLUGIN_BASE_DIR', dirname( __FILE__ ) );
define( 'WP_TUS_PROTOCOL_PLUGIN_BASE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Define min WordPress Version
 */
define( 'WP_TUS_PROTOCOL_PLUGIN__MINIMUM_WP_VERSION', '5.0.0' );

/**
 * Define plugin text domain
 */
define( 'WP_TUS_PROTOCOL_TEXT_DOMAIN', 'wp_tus_protocol' );

/**
 * wp_tus_protocol_boot_plugin
 *
 * CALLED ON ACTION 'after_setup_theme'
 *
 * Includes all class files for plugin, runs on 'after_theme_setup' to allows
 * themes to override some classes/functions
 *
 * @access public
 * @author Ben Moody
 */
add_action( 'plugins_loaded', 'wp_tus_protocol_boot_plugin' ); //Allows themes to override classes, functions
function wp_tus_protocol_boot_plugin() {

	//vars
	$includes_path = WP_TUS_PROTOCOL_PLUGIN_BASE_DIR . '/includes';
	$vendors_path  = WP_TUS_PROTOCOL_PLUGIN_BASE_DIR . '/vendor';

	//Include tus-php
	wp_tus_protocol_include_file( "{$vendors_path}/autoload.php" );

	//Include plugin core file
	wp_tus_protocol_include_file( "{$includes_path}/class-wp-tus-core.php" );

	//Include rest-api files
	wp_tus_protocol_include_file( "{$includes_path}/rest-api/class-wp-tus-endpoint.php" );

	define( 'WP_TUS_PROTOCOL_PLUGIN_LOADED', true );

}

/**
 * wp_tus_protocol_include_file
 *
 * Helper to test file include validation and include_once if safe
 *
 * @param    string    Path to include
 *
 * @return    mixed    Bool/WP_Error
 * @access    public
 * @author    Ben Moody
 */
function wp_tus_protocol_include_file( $path, $require = false ) {

	//Check if a valid path for include
	if ( validate_file( $path ) > 0 ) {

		//Failed path validation
		return new WP_Error(
			'wp_tus_protocol_include_file',
			'File include path failed path validation',
			$path
		);

	}

	if ( true === $require ) {

		require_once( $path );

	} else {

		include_once( $path );

	}

	return true;
}

/**
 * wp_tus_protocol_get_template_path
 *
 * Helper to return path to plugin template part file
 *
 * NOTE you can override any template file by adding a copy of the file from
 * the plugin 'template-parts' dir into your theme under the
 * 'wp-headless' subdir
 *
 * @param string $slug - template part slug name
 * @param string $template_name - template part filename NO .php
 *
 * @return string $path
 * @access public
 * @author Ben Moody
 */
function wp_tus_protocol_get_template_path( $slug, $template_name ) {

	//vars
	$path = WP_TUS_PROTOCOL_PLUGIN_BASE_DIR . '/template-parts';

	$slug          = $slug;
	$template_name = $template_name;

	//Setup template filenames/paths
	$plugin_template_file_path = "{$path}/{$slug}-{$template_name}.php";
	$theme_template_filename   = "/wp-headless/{$slug}-{$template_name}.php";

	//First try and get theme override template
	$theme_template_file_path = locate_template( array( $theme_template_filename ) );

	if ( '' !== $theme_template_file_path ) {

		$path = $theme_template_file_path;

	} else { //Fallback to plugin's version of template

		$path = $plugin_template_file_path;

	}

	return $path;
}

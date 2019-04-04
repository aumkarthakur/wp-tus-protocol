<?php
/**
 * WP_Tus_Protocol_Core
 *
 * Handles all logic for core elements of plugin
 *
 * @author Ben Moody
 */

class WP_Tus_Protocol_Core {

	protected static $plugin_prefix;

	public function __construct() {

		//Set plugin prefix
		self::$plugin_prefix = 'wp_tus_protocol_';

		//Set textdomain
		add_action( 'after_setup_theme', array( $this, 'plugin_textdomain' ) );

	}

	/**
	 * Setup plugin textdomain folder
	 *
	 * @public
	 */
	public function plugin_textdomain() {

		load_plugin_textdomain( WP_TUS_PROTOCOL_TEXT_DOMAIN, false, '/wp-tus-protocol/languages/' );

		return;
	}

}

new WP_Tus_Protocol_Core();

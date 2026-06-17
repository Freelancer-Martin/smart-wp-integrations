<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://freelancermartin.com
 * @since      1.0.0
 *
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/includes
 * @author     Freelancer Martin <freelancermartin1@gmail.com>
 */
class Smart_Wp_Integrations_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'smart-wp-integrations',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}

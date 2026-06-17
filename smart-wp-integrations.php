<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://freelancermartin.com
 * @since             1.0.0
 * @package           Smart_Wp_Integrations
 *
 * @wordpress-plugin
 * Plugin Name:       Smart WP Intergrations
 * Plugin URI:        https://wp-liides.freelancermartin.ee
 * Description:       Smart WP Intergrations on loodud selleks, et muuta sinu veebipood sujuvaks, kiireks ja automaatseks. See WordPressi pistikprogramm ühendab WooCommerce’i juhtivate tarneteenuste ja makselahendustega, võimaldades sul hallata kogu tellimustsüklit ühest kohast. Paigaldus on lihtne ja ei vaja tehnilisi oskusi – kõik vajalik töötab mõne minutiga. Kui klient teeb tellimuse, loob Muufi automaatselt vastava saadetise valitud teenusepakkuja süsteemis ning salvestab jälgimiskoodi tellimuse juurde. Samal ajal sünkroniseerib plugin makseinfo ja vajadusel ka arved raamatupidamistarkvaraga, muutes kogu protsessi kiiremaks ja usaldusväärsemaks.
 * Version:           1.0.0
 * Author:            Freelancer Martin
 * Author URI:        https://freelancermartin.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       smart-wp-integrations
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'SMART_WP_INTEGRATIONS_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-smart-wp-integrations-activator.php
 */
function activate_smart_wp_integrations() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-smart-wp-integrations-activator.php';
	Smart_Wp_Integrations_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-smart-wp-integrations-deactivator.php
 */
function deactivate_smart_wp_integrations() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-smart-wp-integrations-deactivator.php';
	Smart_Wp_Integrations_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_smart_wp_integrations' );
register_deactivation_hook( __FILE__, 'deactivate_smart_wp_integrations' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-smart-wp-integrations.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_smart_wp_integrations() {

	$plugin = new Smart_Wp_Integrations();
	$plugin->run();
	

}
run_smart_wp_integrations();

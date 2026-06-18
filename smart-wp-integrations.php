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

// Feature 3: Automaatne uuesti saatmine — cron registreerimine
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'swi_retry_failed_orders' ) ) {
		wp_schedule_event( time(), 'hourly', 'swi_retry_failed_orders' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'swi_retry_failed_orders' );
} );

add_action( 'swi_retry_failed_orders', 'swi_do_retry_failed_orders' );

// Ühekorraline HPOS migratsioon: kopeeri _swi_* meta wp_postmeta → wp_wc_orders_meta
add_action( 'admin_init', function () {
	if ( get_option( 'swi_hpos_meta_migrated' ) ) {
		return;
	}
	global $wpdb;
	$wpdb->query( "
		INSERT INTO {$wpdb->prefix}wc_orders_meta (order_id, meta_key, meta_value)
		SELECT p.post_id, p.meta_key, p.meta_value
		FROM {$wpdb->postmeta} p
		INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = p.post_id
		WHERE p.meta_key IN ('_swi_sent_merit','_swi_merit_retry','_swi_merit_retry_count')
		ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
	" );
	update_option( 'swi_hpos_meta_migrated', '1' );
} );

/**
 * Cron callback: saada uuesti ebaõnnestunud orderid (max 3 katset).
 */
function swi_do_retry_failed_orders(): void {
	if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
		return;
	}

	$orders = wc_get_orders( [
		'limit'      => 20,
		'meta_key'   => '_swi_merit_retry',
		'meta_value' => '1',
	] );

	if ( empty( $orders ) ) {
		return;
	}

	foreach ( $orders as $order ) {
		$order_id = $order->get_id();

		// Ära proobi kui juba 3 korda ebaõnnestunud — eemalda retry flag
		$count = (int) $order->get_meta( '_swi_merit_retry_count' );
		if ( $count >= 3 ) {
			$order->delete_meta_data( '_swi_merit_retry' );
			$order->delete_meta_data( '_swi_merit_retry_count' );
			$order->save();
			continue;
		}

		// Ära saada kui juba saadetud
		if ( $order->get_meta( '_swi_sent_merit' ) ) {
			$order->delete_meta_data( '_swi_merit_retry' );
			$order->delete_meta_data( '_swi_merit_retry_count' );
			$order->save();
			continue;
		}

		// Vaja include et klassid oleksid saadaval (cron töötab ilma admin kontekstita)
		if ( ! class_exists( 'My_Simple_Ajax_Plugin' ) || ! class_exists( 'LocalApiClient' ) ) {
			continue;
		}

		$plugin_instance = new My_Simple_Ajax_Plugin();
		$payload         = $plugin_instance->build_payload_for_order( $order );
		if ( ! $payload ) {
			continue;
		}

		$res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );

		if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
			$order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
			$order->delete_meta_data( '_swi_merit_retry' );
			$order->delete_meta_data( '_swi_merit_retry_count' );
			$order->save();
			$order->add_order_note( 'Merit Aktiva: arve edastatud automaatse uuesti saatmisega (katse ' . ( $count + 1 ) . ').' );
			if ( function_exists( 'swi_log_send_history' ) ) {
				swi_log_send_history( $order_id, 'ok', 'Automaatne uuesti saatmine õnnestus (katse ' . ( $count + 1 ) . ')' );
			}
		} else {
			$msg = function_exists( 'swi_humanize_merit_error' ) ? swi_humanize_merit_error( $res ) : ( $res['message'] ?? wp_json_encode( $res ) );

			// "Korduv arve number" — arve on Meriti juba olemas, märgi saadetuna
			$merit_body = $res['response']['result']['merit_message'] ?? $res['response']['result']['body'] ?? '';
			if ( str_contains( $merit_body, 'Korduv arve number' ) || str_contains( $msg, 'Korduv arve number' ) || str_contains( $msg, 'juba olemas' ) ) {
				$order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
				$order->delete_meta_data( '_swi_merit_retry' );
				$order->delete_meta_data( '_swi_merit_retry_count' );
				$order->save();
				$order->add_order_note( 'Merit Aktiva: arve on Meriti juba olemas — märgitud saadetuna.' );
				continue;
			}

			$order->update_meta_data( '_swi_merit_retry_count', $count + 1 );
			$order->save();
			$order->add_order_note( 'Merit Aktiva: automaatne uuesti saatmine ebaõnnestus (katse ' . ( $count + 1 ) . ') — ' . $msg );
			if ( function_exists( 'swi_log_send_history' ) ) {
				swi_log_send_history( $order_id, 'error', 'Automaatne uuesti saatmine ebaõnnestus katse ' . ( $count + 1 ) . ': ' . $msg );
			}
		}
	}
}

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

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
 * Description:       Smart WP Intergrations on loodud selleks, et muuta sinu veebipood sujuvaks, kiireks ja automaatseks. See WordPressi pistikprogramm ühendab WooCommerce'i juhtivate tarneteenuste ja makselahendustega, võimaldades sul hallata kogu tellimustsüklit ühest kohast. Paigaldus on lihtne ja ei vaja tehnilisi oskusi – kõik vajalik töötab mõne minutiga. Kui klient teeb tellimuse, loob Muufi automaatselt vastava saadetise valitud teenusepakkuja süsteemis ning salvestab jälgimiskoodi tellimuse juurde. Samal ajal sünkroniseerib plugin makseinfo ja vajadusel ka arved raamatupidamistarkvaraga, muutes kogu protsessi kiiremaks ja usaldusväärsemaks.
 * Version:           1.0.0
 * Author:            Freelancer Martin
 * Author URI:        https://freelancermartin.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       smart-wp-integrations
 * Domain Path:       /languages
 */

// Takistab faili otsest avamist veebibrauserist — WPINC on defineeritud ainult WordPressi laadimise käigus.
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
 * Lisa kohandatud 10-minutiline cron intervall WordPress'i cron-süsteemi.
 *
 * WordPress ei tunne vaikimisi 10-minutist intervalli — see filter lisab selle,
 * et swi_retry_failed_orders cron saaks iga 10 min tagant käivituda.
 * isset-kontroll takistab topelt lisamist, kui teised pluginad sama nime kasutavad.
 */
add_filter( 'cron_schedules', function ( array $schedules ): array {
	if ( ! isset( $schedules['swi_every_10min'] ) ) {
		$schedules['swi_every_10min'] = [
			'interval' => 600,
			'display'  => 'Iga 10 minuti järel (Smart WP Integrations)',
		];
	}
	if ( ! isset( $schedules['swi_10min'] ) ) {
		$schedules['swi_10min'] = [ 'interval' => 600, 'display' => 'Iga 10 minuti järel' ];
	}
	return $schedules;
} );

/**
 * Plugina taustatööd: hook => [intervall sekundites, WP-Cron'i ajakava nimi].
 *
 * Ainult lubatud moodulite tööd — keelatud mooduli töö eemaldatakse ajastusest.
 */
function swi_background_jobs(): array {
	$jobs = [ 'swi_retry_failed_orders' => [ 600, 'swi_every_10min' ] ];
	if ( get_option( 'smart_wp_integtaion_enable' ) === 'yes' ) {
		$jobs['swi_merit_cron_send'] = [ 600, 'swi_10min' ];
		if ( get_option( 'swi_merit_stock_sync' ) === 'yes' ) {
			$jobs['swi_merit_stock_cron'] = [ 3600, 'hourly' ];
		}
	}
	foreach ( [ 'swi_simplebooks_enable' => 'swi_sb_cron_send', 'swi_erply_enable' => 'swi_erply_cron_send', 'swi_stdb_enable' => 'swi_stdb_cron_send' ] as $opt => $hook ) {
		if ( get_option( $opt ) === 'yes' ) {
			$jobs[ $hook ] = [ 600, 'swi_10min' ];
		}
	}
	return $jobs;
}

/** Kõik taustatööde hookid (ka keelatud moodulite omad) — ajastuse koristamiseks. */
function swi_all_background_hooks(): array {
	return [ 'swi_retry_failed_orders', 'swi_merit_cron_send', 'swi_merit_stock_cron', 'swi_sb_cron_send', 'swi_erply_cron_send', 'swi_stdb_cron_send' ];
}

/**
 * Taustatööd: WP-Cron on põhiline, WooCommerce'i Action Scheduler varu.
 *
 * WP-Cron ei käivitu, kui saidil on DISABLE_WP_CRON ilma süsteemse cron'ita, või kui ajastus
 * nihkus (nt serveri kell oli ees). Action Scheduler (WooCommerce'iga kaasas) töötab ka siis —
 * see käivitab tööd admin-lehtede laadimisel taustapäringuga. Kumbki käivitaja märgib töö
 * käivitusaja; varu käivitab töö ainult siis, kui WP-Cron pole seda 1,5 intervalli jooksul teinud.
 */
add_action( 'init', 'swi_ensure_background_jobs', 20 );
function swi_ensure_background_jobs(): void {
	// Kontroll ei pea igal lehelaadimisel jooksma
	if ( get_transient( 'swi_jobs_ensured' ) ) {
		return;
	}
	set_transient( 'swi_jobs_ensured', 1, 5 * MINUTE_IN_SECONDS );

	$jobs = swi_background_jobs();
	$as   = function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' );

	foreach ( swi_all_background_hooks() as $hook ) {
		if ( ! isset( $jobs[ $hook ] ) ) {
			// Moodul keelatud — eemalda nii WP-Cron'ist kui varust
			wp_clear_scheduled_hook( $hook );
			if ( $as ) {
				as_unschedule_all_actions( 'swi_job_backup', [ $hook ], 'smart-wp-integrations' );
			}
			continue;
		}
		[ $interval, $schedule ] = $jobs[ $hook ];

		// WP-Cron: puuduv ajastus → lisa; liiga kaugel tulevikus (kella nihe) → ajasta ümber
		$next = wp_next_scheduled( $hook );
		if ( $next && $next > time() + 2 * $interval ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 60, $schedule, $hook );
		}

		// Action Scheduler varu
		if ( $as && ! as_has_scheduled_action( 'swi_job_backup', [ $hook ], 'smart-wp-integrations' ) ) {
			as_schedule_recurring_action( time() + $interval, $interval, 'swi_job_backup', [ $hook ], 'smart-wp-integrations' );
		}
	}
}

// Iga töö märgib oma käivitusaja (prioriteet 0 — enne töö enda callback'e)
foreach ( swi_all_background_hooks() as $swi_job_hook ) {
	add_action( $swi_job_hook, function () use ( $swi_job_hook ) {
		update_option( 'swi_job_last_' . $swi_job_hook, time(), false );
	}, 0 );
}
unset( $swi_job_hook );

// Action Scheduler'i varu: käivita töö ainult siis, kui WP-Cron pole seda õigel ajal teinud
add_action( 'swi_job_backup', function ( $hook ) {
	$jobs = swi_background_jobs();
	if ( ! is_string( $hook ) || ! isset( $jobs[ $hook ] ) ) {
		return;
	}
	$last = (int) get_option( 'swi_job_last_' . $hook, 0 );
	if ( time() - $last < 1.5 * $jobs[ $hook ][0] ) {
		return; // WP-Cron töötab, varu pole vaja
	}
	error_log( "SWI: WP-Cron ei käivitanud tööd {$hook} õigel ajal — käivitan Action Scheduleriga." );
	do_action( $hook );
} );

/**
 * Registreeri retry cron-ülesanne plugina aktiveerimisel.
 *
 * wp_next_scheduled kontroll väldib duplikaatide tekkimist, kui plugin
 * deaktiveeritakse ja uuesti aktiveeritakse ilma WP cron-tabeli puhastamiseta.
 */
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'swi_retry_failed_orders' ) ) {
		wp_schedule_event( time(), 'swi_every_10min', 'swi_retry_failed_orders' );
	}
} );

/**
 * Tühjenda cron-ülesanne plugina desaktiveerimisel.
 *
 * Ilma selleta jääks cron aktiivseks ka pärast plugina keelustamist,
 * mis põhjustaks PHP-vigu puuduvate klasside tõttu.
 */
register_deactivation_hook( __FILE__, function () {
	foreach ( swi_all_background_hooks() as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'swi_job_backup' );
	}
	delete_transient( 'swi_jobs_ensured' );
} );

// Seo cron-sündmus konkreetse callback-funktsiooniga
add_action( 'swi_retry_failed_orders', 'swi_do_retry_failed_orders' );

/**
 * Ühekorraline HPOS migratsioon: kopeeri _swi_* meta wp_postmeta → wp_wc_orders_meta.
 *
 * WooCommerce HPOS (High Performance Order Storage) viis order-meta vana wp_postmeta
 * tabelist uude wp_wc_orders_meta tabelisse. Ilma selle migratsioonita kaoks kõik
 * varem saadetud orderite '_swi_sent_merit' lipud ja plugin saadaks need uuesti.
 * ON DUPLICATE KEY UPDATE tagab, et olemasolevaid kirjeid ei kirjutata üle.
 * swi_hpos_meta_migrated flag väldib, et migratsioon töötaks igal lehelaadimisел.
 */
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
 * Cron callback: proovi ebaõnnestunud ordereid automaatselt uuesti saata.
 *
 * Käivitub iga 10 minuti järel. Otsib ordereid, millel on '_swi_merit_retry' = '1'
 * ja proovib neid kuni 3 korda uuesti saata. Pärast 3 ebaõnnestumist eemaldatakse
 * retry-lipud ja admin peab probleemi käsitsi lahendama.
 *
 * Cron töötab ilma admin-kontekstita (eraldi PHP protsess), seega kontrollitakse
 * klasside olemasolu enne kasutamist.
 */
function swi_do_retry_failed_orders(): void {
	// Ära tee midagi kui integratsioon on administraatori poolt keelatud
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
			// Loobume — jäta põhjus tellimusele, et admin-teade seda näitaks
			if ( ! $order->get_meta( '_swi_merit_send_error' ) ) {
				$order->update_meta_data( '_swi_merit_send_error', 'Automaatne uuesti saatmine ebaõnnestus 3 korda — vaata tellimuse märkusi.' );
			}
			$order->save();
			continue;
		}

		// Ära saada kui order on vahepeal (nt käsitsi) edukalt saadetud
		if ( $order->get_meta( '_swi_sent_merit' ) ) {
			$order->delete_meta_data( '_swi_merit_retry' );
			$order->delete_meta_data( '_swi_merit_retry_count' );
			$order->save();
			continue;
		}

		// Cron töötab eraldi protsessis ilma admin-laadimiseta, seega klassid ei pruugi saadaval olla
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
			// Edukas saatmine — märgi saadetuna ja puhasta retry-lipud
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
			$order->update_meta_data( '_swi_merit_send_error', $msg );

			// Erijuhtum: Merit ütleb "Korduv arve number" — tähendab arve on seal juba olemas.
			// Sel juhul pole uuesti saatmine mõttekas, märgime orderit saadetuna.
			$merit_body = $res['response']['result']['merit_message'] ?? $res['response']['result']['body'] ?? '';
			if ( str_contains( $merit_body, 'Korduv arve number' ) || str_contains( $msg, 'Korduv arve number' ) || str_contains( $msg, 'juba olemas' ) ) {
				$order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
				$order->delete_meta_data( '_swi_merit_retry' );
				$order->delete_meta_data( '_swi_merit_retry_count' );
				$order->save();
				$order->add_order_note( 'Merit Aktiva: arve on Meriti juba olemas — märgitud saadetuna.' );
				continue;
			}

			// Ebaõnnestunud katse — suurenda loendajat ja proovi järgmisel cron-käivitusel uuesti
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
 * Cron callback: proovi ebaõnnestunud Simplebooks ordereid automaatselt uuesti saata.
 *
 * Töötab samas cron-sündmuses Merit retry-ga ('swi_retry_failed_orders').
 * Paralleelsed retry-tsüklid oleks keerulisemad hallata ja ühe 10-minutilise
 * tsükli koormus on piisavalt väike mõlema süsteemi jaoks.
 */
function swi_do_simplebooks_retry(): void {
	if ( get_option( 'swi_simplebooks_enable' ) !== 'yes' ) {
		return;
	}

	if ( ! class_exists( 'LocalApiClient' ) || ! class_exists( 'SWI_Simplebooks_Create_Invoices' ) ) {
		return;
	}

	$orders = wc_get_orders( [
		'limit'      => 20,
		'meta_key'   => '_swi_simplebooks_retry',
		'meta_value' => '1',
	] );

	if ( empty( $orders ) ) {
		return;
	}

	$handler = new SWI_Simplebooks_Create_Invoices();

	foreach ( $orders as $order ) {
		$order_id = $order->get_id();
		$count    = (int) $order->get_meta( '_swi_simplebooks_retry_count' );

		if ( $count >= 3 ) {
			$order->delete_meta_data( '_swi_simplebooks_retry' );
			$order->delete_meta_data( '_swi_simplebooks_retry_count' );
			$order->save();
			continue;
		}

		if ( $order->get_meta( '_swi_sent_simplebooks' ) ) {
			$order->delete_meta_data( '_swi_simplebooks_retry' );
			$order->delete_meta_data( '_swi_simplebooks_retry_count' );
			$order->save();
			continue;
		}

		$payload = $handler->build_payload( $order );
		if ( ! $payload ) {
			continue;
		}

		$res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

		if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
			$order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
			$order->delete_meta_data( '_swi_simplebooks_retry' );
			$order->delete_meta_data( '_swi_simplebooks_retry_count' );
			$order->save();
			$order->add_order_note( 'Simplebooks: arve edastatud automaatse uuesti saatmisega (katse ' . ( $count + 1 ) . ').' );
			if ( function_exists( 'swi_sb_log_send_history' ) ) {
				swi_sb_log_send_history( $order_id, 'ok', 'Automaatne uuesti saatmine õnnestus (katse ' . ( $count + 1 ) . ')' );
			}
		} else {
			$msg = $res['message'] ?? wp_json_encode( $res );
			$order->update_meta_data( '_swi_simplebooks_retry_count', $count + 1 );
			$order->save();
			$order->add_order_note( 'Simplebooks: automaatne uuesti saatmine ebaõnnestus (katse ' . ( $count + 1 ) . ') — ' . $msg );
			if ( function_exists( 'swi_sb_log_send_history' ) ) {
				swi_sb_log_send_history( $order_id, 'error', 'Automaatne uuesti saatmine ebaõnnestus katse ' . ( $count + 1 ) . ': ' . $msg );
			}
		}
	}
}
add_action( 'swi_retry_failed_orders', 'swi_do_simplebooks_retry' );

/**
 * Smart Accounts automaatne uuesti saatmine.
 */
function swi_do_smartaccounts_retry(): void {
	if ( get_option( 'swi_smartaccounts_enable' ) !== 'yes' ) {
		return;
	}

	$orders = wc_get_orders( [
		'limit'      => 20,
		'meta_key'   => '_swi_smartaccounts_retry',
		'meta_value' => '1',
	] );

	if ( empty( $orders ) ) {
		return;
	}

	foreach ( $orders as $order ) {
		$order_id = $order->get_id();
		$count    = (int) $order->get_meta( '_swi_smartaccounts_retry_count' );

		if ( $count >= 3 ) {
			$order->delete_meta_data( '_swi_smartaccounts_retry' );
			$order->delete_meta_data( '_swi_smartaccounts_retry_count' );
			$order->save();
			continue;
		}

		if ( $order->get_meta( '_swi_sent_smartaccounts' ) ) {
			$order->delete_meta_data( '_swi_smartaccounts_retry' );
			$order->delete_meta_data( '_swi_smartaccounts_retry_count' );
			$order->save();
			continue;
		}

		if ( ! class_exists( 'SWI_SmartAccounts_Create_Invoices' ) || ! class_exists( 'LocalApiClient' ) ) {
			continue;
		}

		$instance = new SWI_SmartAccounts_Create_Invoices();
		$payload  = $instance->build_payload( $order );
		if ( ! $payload ) {
			continue;
		}

		$res = LocalApiClient::sendEncryptedOrder( $payload, 'smartaccounts' );

		if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
			$order->update_meta_data( '_swi_sent_smartaccounts', current_time( 'mysql' ) );
			$order->delete_meta_data( '_swi_smartaccounts_retry' );
			$order->delete_meta_data( '_swi_smartaccounts_retry_count' );
			$order->save();
			$order->add_order_note( 'Smart Accounts: arve edastatud automaatse uuesti saatmisega (katse ' . ( $count + 1 ) . ').' );
			if ( function_exists( 'swi_sa_log_send_history' ) ) {
				swi_sa_log_send_history( $order_id, 'ok', 'Automaatne uuesti saatmine õnnestus (katse ' . ( $count + 1 ) . ')' );
			}
		} else {
			$order->update_meta_data( '_swi_smartaccounts_retry_count', $count + 1 );
			$order->save();
			if ( function_exists( 'swi_sa_log_send_history' ) ) {
				swi_sa_log_send_history( $order_id, 'error', 'Automaatne uuesti saatmine ebaõnnestus (katse ' . ( $count + 1 ) . ')' );
			}
		}
	}
}
add_action( 'swi_retry_failed_orders', 'swi_do_smartaccounts_retry' );

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

/**
 * Lisab vaheserverisse minevatele päringutele saidi aadressi (X-Site-Url).
 *
 * Vaheserver seob litsentsi domeeniga: litsentsivõtmega päring peab tulema mõnelt litsentsi
 * domeenilt. Üks filter katab kõik moodulid (Merit, Simplebooks, SA, Erply, kullerid jne).
 */
add_filter( 'http_request_args', function ( $args, $url ) {
	$base = rtrim( (string) get_option( 'smart_wp_integration_server_url', '' ), '/' );
	if ( $base === '' && class_exists( 'LocalApiClient' ) ) {
		$base = rtrim( LocalApiClient::get_base_url_public(), '/' );
	}
	if ( $base !== '' && strpos( (string) $url, $base . '/api/' ) === 0 && is_array( $args['headers'] ?? [] ) ) {
		$args['headers']               = (array) ( $args['headers'] ?? [] );
		$args['headers']['X-Site-Url'] = home_url();
	}
	return $args;
}, 10, 2 );

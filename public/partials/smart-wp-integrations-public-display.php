<?php

/**
 * Provide a public-facing view for the plugin.
 *
 * @link       https://freelancermartin.com
 * @since      1.0.0
 *
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/public/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Set $server_error = true when a remote API call fails, then include this
 * partial to surface the professional error notice to the user.
 *
 * Example usage in your controller:
 *
 *   $response = wp_remote_get( $endpoint );
 *   if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
 *       $server_error = true;
 *   }
 *   include plugin_dir_path( __FILE__ ) . 'partials/smart-wp-integrations-public-display.php';
 */

if ( ! empty( $server_error ) ) {
    include __DIR__ . '/smart-wp-integrations-server-error.php';
    return;
}
?>

<!-- Normal plugin output goes here -->

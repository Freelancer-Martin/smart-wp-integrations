<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://freelancermartin.com
 * @since      1.0.0
 *
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/admin
 * @author     Freelancer Martin <freelancermartin1@gmail.com>
 */
class Smart_Wp_Integrations_Admin {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Hook for admin area styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        // Add Bootstrap specifically for admin
        add_action('admin_enqueue_scripts', array($this, 'my_plugin_enqueue_bootstrap'));
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'css/smart-wp-integrations-admin.css',
            array(),
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'js/smart-wp-integrations-admin.js',
            array( 'jquery' ),
            $this->version,
            false
        );
    }

    public function my_plugin_enqueue_bootstrap() {
        wp_enqueue_style(
            'my-plugin-bootstrap-css',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
        );
        wp_enqueue_script(
            'my-plugin-bootstrap-js',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
            array('jquery'),
            null,
            true
        );
    }
}

// Instantiate with plugin name and version
$start_stylesheet = new Smart_Wp_Integrations_Admin('smart-wp-integrations', '1.0.0');


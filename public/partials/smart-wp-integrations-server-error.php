<?php
/**
 * Server connection error notice.
 *
 * Displayed whenever the plugin cannot reach its remote integration endpoint.
 *
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/public/partials
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="swi-error-wrapper" role="alert" aria-live="assertive">

    <div class="swi-error-card">

        <!-- Status bar -->
        <div class="swi-error-status-bar">
            <span class="swi-status-dot"></span>
            <span class="swi-status-label">Service Unavailable</span>
        </div>

        <!-- Icon -->
        <div class="swi-error-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="32" r="30" stroke="#c0392b" stroke-width="2.5" opacity=".15"/>
                <path d="M14 42 C14 38 18 35 22 35 L42 35 C46 35 50 38 50 42 L50 46 C50 47.1 49.1 48 48 48 L16 48 C14.9 48 14 47.1 14 46 Z"
                      fill="#c0392b" opacity=".12"/>
                <rect x="20" y="35" width="24" height="13" rx="2" fill="#c0392b" opacity=".08"/>
                <!-- Server rack lines -->
                <rect x="18" y="20" width="28" height="7" rx="2" stroke="#c0392b" stroke-width="2" fill="none"/>
                <rect x="18" y="30" width="28" height="7" rx="2" stroke="#c0392b" stroke-width="2" fill="none"/>
                <circle cx="41" cy="23.5" r="1.5" fill="#c0392b"/>
                <circle cx="41" cy="33.5" r="1.5" fill="#c0392b" opacity=".4"/>
                <!-- Broken connection bolt -->
                <path d="M34 10 L29 18 L33 18 L28 26" stroke="#c0392b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>

        <!-- Headline -->
        <h2 class="swi-error-title">
            <?php esc_html_e( 'Unable to Connect to Server', 'smart-wp-integrations' ); ?>
        </h2>

        <!-- Body copy -->
        <p class="swi-error-message">
            <?php esc_html_e(
                'We are currently experiencing a connection issue with our integration service. Our team has been notified and is working to restore access as quickly as possible.',
                'smart-wp-integrations'
            ); ?>
        </p>

        <p class="swi-error-sub">
            <?php esc_html_e(
                'This is a temporary disruption. Please try again shortly. We apologise for any inconvenience caused.',
                'smart-wp-integrations'
            ); ?>
        </p>

        <!-- Actions -->
        <div class="swi-error-actions">
            <button
                type="button"
                class="swi-btn swi-btn-primary"
                onclick="window.location.reload()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                <?php esc_html_e( 'Try Again', 'smart-wp-integrations' ); ?>
            </button>

            <a
                href="<?php echo esc_url( admin_url( 'admin.php?page=smart-wp-integrations' ) ); ?>"
                class="swi-btn swi-btn-secondary"
            >
                <?php esc_html_e( 'Go to Dashboard', 'smart-wp-integrations' ); ?>
            </a>
        </div>

        <!-- Footer meta -->
        <div class="swi-error-footer">
            <span class="swi-error-code">
                <?php
                printf(
                    /* translators: %s: human-readable timestamp */
                    esc_html__( 'Error recorded at %s', 'smart-wp-integrations' ),
                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
                );
                ?>
            </span>
            <span class="swi-error-sep" aria-hidden="true">&bull;</span>
            <a
                href="mailto:support@freelancermartin.com"
                class="swi-error-support-link"
            >
                <?php esc_html_e( 'Contact Support', 'smart-wp-integrations' ); ?>
            </a>
        </div>

    </div><!-- /.swi-error-card -->

</div><!-- /.swi-error-wrapper -->

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Kombineeritud "Integratsioonid" kolumn WC tellimuste nimekirjas.
 * Näitab iga lubatud teenuse (Merit / Simplebooks / Smart Accounts) staatust
 * ja "Saada" nuppu saatmata orderite peal.
 *
 * Tagasipööramine: kommenteeri välja require + new SWI_Order_Column() rida
 * failis class-smart-wp-integrations.php.
 */
class SWI_Order_Column {

    private array $services;

    public function __construct() {
        $this->services = $this->enabled_services();
        if ( empty( $this->services ) ) return;

        add_filter( 'woocommerce_shop_order_list_table_columns',       [ $this, 'add_column' ] );
        add_filter( 'manage_woocommerce_page_wc-orders_columns',       [ $this, 'add_column' ] );
        add_filter( 'manage_edit-shop_order_columns',                  [ $this, 'add_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_column' ], 10, 2 );
        add_action( 'manage_shop_order_posts_custom_column',           [ $this, 'render_column' ], 10, 2 );
        add_action( 'admin_footer',                                    [ $this, 'render_scripts' ] );
    }

    private function enabled_services(): array {
        $all = [
            'merit' => [
                'label'      => 'M',
                'title'      => 'Merit Aktiva',
                'option'     => 'smart_wp_integtaion_enable',
                'meta_sent'  => '_swi_sent_merit',
                'ajax'       => 'swi_merit_order_send',
                'nonce'      => 'swi_merit_order_send',
                'color'      => '#0369a1',
            ],
            'simplebooks' => [
                'label'      => 'SB',
                'title'      => 'Simplebooks',
                'option'     => 'swi_simplebooks_enable',
                'meta_sent'  => '_swi_sent_simplebooks',
                'ajax'       => 'swi_sb_order_send',
                'nonce'      => 'swi_sb_order_send',
                'color'      => '#0891b2',
            ],
            'smartaccounts' => [
                'label'      => 'SA',
                'title'      => 'Smart Accounts',
                'option'     => 'swi_smartaccounts_enable',
                'meta_sent'  => '_swi_sent_smartaccounts',
                'ajax'       => 'swi_sa_order_send',
                'nonce'      => 'swi_sa_order_send',
                'color'      => '#7c3aed',
            ],
        ];
        return array_filter( $all, fn( $s ) => get_option( $s['option'] ) === 'yes' );
    }

    public function add_column( array $cols ): array {
        $new   = [];
        $added = false;
        foreach ( $cols as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_status' ) {
                $new['swi_integrations'] = 'Integratsioonid';
                $added = true;
            }
        }
        if ( ! $added ) {
            $new['swi_integrations'] = 'Integratsioonid';
        }
        return $new;
    }

    public function render_column( string $column, $order_or_id ): void {
        if ( $column !== 'swi_integrations' ) return;
        $order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( (int) $order_or_id );
        if ( ! $order ) return;

        $parts = [];
        foreach ( $this->services as $key => $svc ) {
            $sent  = $order->get_meta( $svc['meta_sent'] );
            $nonce = wp_create_nonce( $svc['nonce'] );
            $color = esc_attr( $svc['color'] );
            $label = esc_html( $svc['label'] );
            $title = esc_attr( $svc['title'] );
            $id    = esc_attr( $order->get_id() );

            if ( $sent ) {
                $date = esc_html( date( 'd.m.y', strtotime( $sent ) ) );
                $parts[] = '<span title="' . $title . ': saadetud ' . esc_attr( date( 'd.m.Y H:i', strtotime( $sent ) ) ) . '" style="display:inline-flex;align-items:center;gap:2px;background:' . $color . '1a;border:1px solid ' . $color . '4d;border-radius:3px;padding:1px 4px;font-size:11px;color:' . $color . ';">'
                    . '<b>' . $label . '</b> ✓</span>';
            } else {
                $parts[] = '<button class="swi-col-send" data-service="' . esc_attr( $key ) . '" data-ajax="' . esc_attr( $svc['ajax'] ) . '" data-nonce="' . $nonce . '" data-id="' . $id . '" title="Saada ' . $title . '" style="display:inline-flex;align-items:center;gap:2px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:3px;padding:1px 5px;font-size:11px;color:#374151;cursor:pointer;line-height:1.4;">'
                    . '<b style="color:' . $color . ';">' . $label . '</b> <span class="swi-btn-text">↑</span></button>';
            }
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:3px;align-items:center;">' . implode( '', $parts ) . '</div>';
    }

    public function render_scripts(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! str_contains( $screen->id, 'order' ) ) return;
        ?>
        <script>
        jQuery(function($){
            $(document).on('click', '.swi-col-send', function(e){
                e.preventDefault();
                var btn  = $(this);
                var txt  = btn.find('.swi-btn-text');
                var id   = btn.data('id');
                var ajax = btn.data('ajax');
                var non  = btn.data('nonce');
                btn.prop('disabled', true);
                txt.text('…');
                $.post(ajaxurl, {action: ajax, order_id: id, nonce: non}, function(r){
                    if (r.success) {
                        var today = new Date().toLocaleDateString('et-EE',{day:'2-digit',month:'2-digit',year:'2-digit'});
                        var label = btn.find('b').text();
                        var color = btn.find('b').css('color');
                        btn.replaceWith(
                            '<span style="display:inline-flex;align-items:center;gap:2px;background:rgba(3,105,161,0.1);border:1px solid rgba(3,105,161,0.3);border-radius:3px;padding:1px 4px;font-size:11px;color:' + color + ';">'
                            + '<b>' + label + '</b> ✓</span>'
                        );
                    } else {
                        txt.text('!');
                        btn.prop('disabled', false).css('border-color','#dc2626');
                        btn.attr('title', (r.data && r.data.message) ? r.data.message : 'Viga');
                    }
                }).fail(function(){
                    txt.text('!');
                    btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }
}

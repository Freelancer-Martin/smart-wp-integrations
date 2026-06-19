<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Üks "Saada ▾" nupp WC tellimuste nimekirjas.
 * Klõps avab dropdown kus näha iga teenuse staatus ja "Saada" link.
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
                'label'     => 'Merit Aktiva',
                'option'    => 'smart_wp_integtaion_enable',
                'meta_sent' => '_swi_sent_merit',
                'ajax'      => 'swi_merit_order_send',
                'nonce'     => 'swi_merit_order_send',
            ],
            'simplebooks' => [
                'label'     => 'Simplebooks',
                'option'    => 'swi_simplebooks_enable',
                'meta_sent' => '_swi_sent_simplebooks',
                'ajax'      => 'swi_sb_order_send',
                'nonce'     => 'swi_sb_order_send',
            ],
            'smartaccounts' => [
                'label'     => 'Smart Accounts',
                'option'    => 'swi_smartaccounts_enable',
                'meta_sent' => '_swi_sent_smartaccounts',
                'ajax'      => 'swi_sa_order_send',
                'nonce'     => 'swi_sa_order_send',
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
                $new['swi_integrations'] = 'Saada';
                $added = true;
            }
        }
        if ( ! $added ) {
            $new['swi_integrations'] = 'Saada';
        }
        return $new;
    }

    public function render_column( string $column, $order_or_id ): void {
        if ( $column !== 'swi_integrations' ) return;
        $order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( (int) $order_or_id );
        if ( ! $order ) return;

        $all_sent = true;
        $rows     = [];

        foreach ( $this->services as $key => $svc ) {
            $sent  = $order->get_meta( $svc['meta_sent'] );
            $nonce = wp_create_nonce( $svc['nonce'] );
            $label = esc_html( $svc['label'] );
            $id    = (int) $order->get_id();

            if ( $sent ) {
                $date  = esc_html( date( 'd.m.y', strtotime( $sent ) ) );
                $rows[] = '<div class="swi-dd-row" data-key="' . esc_attr( $key ) . '">'
                    . '<span style="color:#16a34a;">✓</span> '
                    . '<span class="swi-dd-label">' . $label . '</span>'
                    . '<span style="color:#6b7280;font-size:10px;margin-left:4px;">' . $date . '</span>'
                    . '</div>';
            } else {
                $all_sent = false;
                $rows[] = '<div class="swi-dd-row" data-key="' . esc_attr( $key ) . '">'
                    . '<span style="color:#9ca3af;">–</span> '
                    . '<span class="swi-dd-label">' . $label . '</span>'
                    . '<button class="swi-dd-send" data-id="' . $id . '" data-ajax="' . esc_attr( $svc['ajax'] ) . '" data-nonce="' . $nonce . '" data-key="' . esc_attr( $key ) . '">Saada</button>'
                    . '</div>';
            }
        }

        $btn_label = $all_sent ? '✓' : '↑';
        $btn_style = $all_sent
            ? 'background:#f0fdf4;border-color:#86efac;color:#16a34a;'
            : 'background:#f9fafb;border-color:#d1d5db;color:#374151;';

        echo '<div class="swi-dd-wrap" style="position:relative;display:inline-block;">'
            . '<button class="swi-dd-toggle button button-small" style="' . $btn_style . 'min-width:32px;" title="Integratsioonide staatus">'
            . $btn_label . ' <span style="font-size:9px;">▾</span></button>'
            . '<div class="swi-dd-menu" style="display:none;position:absolute;z-index:9999;left:0;top:100%;margin-top:2px;background:#fff;border:1px solid #d1d5db;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);padding:6px 0;min-width:170px;">'
            . implode( '', $rows )
            . '</div>'
            . '</div>';
    }

    public function render_scripts(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! str_contains( $screen->id, 'order' ) ) return;
        ?>
        <style>
        .swi-dd-row { display:flex;align-items:center;padding:4px 10px;font-size:12px;gap:4px; }
        .swi-dd-label { flex:1; }
        .swi-dd-send { font-size:11px;padding:1px 6px;height:auto;line-height:1.5;cursor:pointer; }
        .swi-dd-send:disabled { opacity:.5; }
        </style>
        <script>
        jQuery(function($){
            // toggle dropdown
            $(document).on('click', '.swi-dd-toggle', function(e){
                e.stopPropagation();
                var menu = $(this).siblings('.swi-dd-menu');
                $('.swi-dd-menu').not(menu).hide();
                menu.toggle();
            });
            $(document).on('click', function(){ $('.swi-dd-menu').hide(); });

            // saada nupp
            $(document).on('click', '.swi-dd-send', function(e){
                e.stopPropagation();
                var btn  = $(this);
                var row  = btn.closest('.swi-dd-row');
                btn.prop('disabled', true).text('…');
                $.post(ajaxurl, {
                    action:   btn.data('ajax'),
                    order_id: btn.data('id'),
                    nonce:    btn.data('nonce'),
                }, function(r){
                    if (r.success) {
                        var today = new Date().toLocaleDateString('et-EE',{day:'2-digit',month:'2-digit',year:'2-digit'});
                        row.find('span:first').css('color','#16a34a').text('✓');
                        btn.replaceWith('<span style="color:#6b7280;font-size:10px;">' + today + '</span>');
                        // kui kõik saadetud, uuenda toggle nuppu
                        var wrap  = row.closest('.swi-dd-wrap');
                        var unsent = wrap.find('.swi-dd-send').length;
                        if (unsent === 0) {
                            wrap.find('.swi-dd-toggle').css({background:'#f0fdf4','border-color':'#86efac',color:'#16a34a'}).html('✓ <span style="font-size:9px;">▾</span>');
                        }
                    } else {
                        btn.prop('disabled', false).text('!').css('color','#dc2626');
                        btn.attr('title', (r.data && r.data.message) ? r.data.message : 'Viga');
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('!');
                });
            });
        });
        </script>
        <?php
    }
}

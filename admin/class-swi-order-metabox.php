<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWI_Order_Metabox {

    private array $services;

    public function __construct() {
        $this->services = $this->enabled_services();
        if ( empty( $this->services ) ) return;

        add_action( 'add_meta_boxes', [ $this, 'register' ] );
        add_action( 'admin_footer',   [ $this, 'render_script' ] );
    }

    private function enabled_services(): array {
        $all = [
            'merit' => [
                'label'       => 'Merit Aktiva',
                'option'      => 'smart_wp_integtaion_enable',
                'meta_sent'   => '_swi_sent_merit',
                'ajax'        => 'swi_merit_order_send',
                'nonce_act'   => 'swi_merit_order_send',
                'nonce_field' => 'nonce',
            ],
            'simplebooks' => [
                'label'       => 'Simplebooks',
                'option'      => 'swi_simplebooks_enable',
                'meta_sent'   => '_swi_sent_simplebooks',
                'ajax'        => 'swi_sb_order_send',
                'nonce_act'   => 'my_nonce',
                'nonce_field' => 'security',
            ],
            'smartaccounts' => [
                'label'       => 'Smart Accounts',
                'option'      => 'swi_smartaccounts_enable',
                'meta_sent'   => '_swi_sent_smartaccounts',
                'ajax'        => 'swi_sa_order_send',
                'nonce_act'   => 'my_nonce',
                'nonce_field' => 'security',
            ],
        ];
        return array_filter( $all, fn( $s ) => get_option( $s['option'] ) === 'yes' );
    }

    public function register(): void {
        foreach ( [ 'shop_order', 'wc-order', 'woocommerce_page_wc-orders' ] as $screen ) {
            add_meta_box(
                'swi_integrations',
                'Integratsioonid',
                [ $this, 'render' ],
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render( $post_or_order ): void {
        if ( $post_or_order instanceof WC_Order ) {
            $order = $post_or_order;
        } elseif ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
            $order = wc_get_order( $post_or_order->ID );
        } else {
            $order = wc_get_order( (int) $post_or_order );
        }
        if ( ! $order ) return;

        $order_id = $order->get_id();
        ?>
        <div id="swi-mb-wrap" style="font-size:13px;">

            <?php foreach ( $this->services as $key => $svc ) :
                $sent = $order->get_meta( $svc['meta_sent'] );
            ?>
            <div class="swi-mb-row" style="display:flex;align-items:center;justify-content:space-between;padding:3px 0;border-bottom:1px solid #f0f0f0;">
                <span style="color:<?php echo $sent ? '#16a34a' : '#6b7280'; ?>;">
                    <?php echo $sent ? '✓' : '–'; ?>
                </span>
                <span style="flex:1;margin:0 6px;"><?php echo esc_html( $svc['label'] ); ?></span>
                <?php if ( $sent ) : ?>
                    <span style="color:#9ca3af;font-size:11px;"><?php echo esc_html( date( 'd.m.y', strtotime( $sent ) ) ); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div style="margin-top:10px;display:flex;gap:6px;align-items:center;">
                <select id="swi-mb-service" style="flex:1;height:28px;font-size:12px;">
                    <?php foreach ( $this->services as $key => $svc ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"
                            data-ajax="<?php echo esc_attr( $svc['ajax'] ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( $svc['nonce_act'] ) ); ?>"
                            data-nonce-field="<?php echo esc_attr( $svc['nonce_field'] ); ?>">
                            <?php echo esc_html( $svc['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button id="swi-mb-send" class="button button-primary" style="height:28px;padding:0 10px;font-size:12px;" data-id="<?php echo esc_attr( $order_id ); ?>">
                    Saada
                </button>
            </div>

            <div id="swi-mb-msg" style="margin-top:6px;font-size:12px;min-height:16px;"></div>
        </div>
        <?php
    }

    public function render_script(): void {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        $valid = [ 'shop_order', 'wc-order', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $screen->id, $valid, true ) ) return;
        if ( $screen->id === 'woocommerce_page_wc-orders' && empty( $_GET['id'] ) ) return;
        ?>
        <script>
        jQuery(function($){
            $('#swi-mb-send').on('click', function(){
                var btn    = $(this);
                var sel    = $('#swi-mb-service');
                var opt    = sel.find(':selected');
                var id     = btn.data('id');
                var ajax        = opt.data('ajax');
                var nonce       = opt.data('nonce');
                var nonceField  = opt.data('nonce-field') || 'nonce';
                var label       = opt.text().trim();
                var msg         = $('#swi-mb-msg');
                var postData    = {action: ajax, order_id: id};
                postData[nonceField] = nonce;

                btn.prop('disabled', true).text('…');
                msg.css('color','#6b7280').text('Saadan ' + label + '…');

                $.post(ajaxurl, postData, function(r){
                    btn.prop('disabled', false).text('Saada');
                    if (r.success) {
                        msg.css('color','#16a34a').text('✓ ' + label + ' saadetud');
                        // uuenda staatuse rida
                        var today = new Date().toLocaleDateString('et-EE',{day:'2-digit',month:'2-digit',year:'2-digit'});
                        var rows  = $('#swi-mb-wrap .swi-mb-row');
                        var idx   = sel[0].selectedIndex;
                        var row   = rows.eq(idx);
                        row.find('span:first').css('color','#16a34a').text('✓');
                        var dateSpan = row.find('span').last();
                        if (dateSpan.length && dateSpan[0] !== row.find('span:first')[0] && dateSpan[0] !== row.find('span').eq(1)[0]) {
                            dateSpan.text(today);
                        } else {
                            row.append('<span style="color:#9ca3af;font-size:11px;">' + today + '</span>');
                        }
                    } else {
                        var err = (r.data && r.data.message) ? r.data.message : 'Saatmine ebaõnnestus';
                        msg.css('color','#dc2626').text('✗ ' + err);
                    }
                }).fail(function(xhr){
                    btn.prop('disabled', false).text('Saada');
                    msg.css('color','#dc2626').text('✗ HTTP ' + xhr.status + ': ' + (xhr.responseText || 'Ühenduse viga').substring(0,120));
                });
            });
        });
        </script>
        <?php
    }
}

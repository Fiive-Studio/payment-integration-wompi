<?php

class WC_Payment_Wompi_WWP extends WC_Payment_Gateway
{
    public function __construct()
    {
		$plugin_dir = plugin_dir_url(__FILE__);
		
        $this->id = 'wompi_wwp';
		$this->icon = apply_filters( 'woocommerce_gateway_icon', $plugin_dir.'admin/wompi-logo.png' );
        $this->method_title = __('Wompi');
        $this->method_description = sprintf( __( 'Una solución de Bancolombia, enfocada a agilizar los negocios de pequeñas y medianas empresas facilitando diferentes medios de pago.' );
        $this->description  = $this->get_option( 'description' );
        $this->order_button_text = __('Pagar');
        $this->supports = [
            'products'
        ];

        $this->title = $this->get_option('title');
        $this->debug = $this->get_option( 'debug' );
        $this->isTest = (bool)$this->get_option( 'environment' );
	
        if ($this->isTest){
            $this->public_key = $this->get_option( 'sandbox_public_key' );
        }else{
            $this->public_key = $this->get_option( 'public_key' );
        }

        $this->init();

        add_action('woocommerce_api_'.strtolower(get_class($this)), array($this, 'confirmation_ipn'));
    }

    public function is_available()
    {
        return parent::is_available() &&
            !empty($this->public_key);
    }

    public function init()
    {
        $this->init_form_fields();
        $this->init_settings();        
		
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));				
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_thankyou_order_received_text', array( $this, 'thankyou_order_received_text' ) );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_data_after_order_details' ) );
		add_filter( 'woocommerce_thankyou_order_key', array( $this, 'thankyou_order_key' ) );		
    }

    public function init_form_fields()
    {
        $this->form_fields = require( dirname( __FILE__ ) . '/admin/settings.php' );
    }

    public static function thankyou_order_key( $order_key ) {
        if ( empty( $_GET['key'] ) ) {
            global $wp;
            $order = wc_get_order( $wp->query_vars['order-received'] );
            $order_key = $order->get_order_key();
        }
        return $order_key;
    }

    public static function admin_order_data_after_order_details( $order ) {
        $order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
        echo '<p class="form-field form-field-wide wompi-payment-method-type"><strong>' . __( 'Payment method type', 'woocommerce-gateway-wompi' ) . ':</strong> ' . get_post_meta( $order_id, '_wompi_payment_method_type', true ) . '</p>';
    }

    public static function thankyou_order_received_text( $text ) {
        global $wp;
        $order = wc_get_order( $wp->query_vars['order-received'] );
        $status = $order->get_status();
        if ( in_array( $status, array( 'cancelled', 'failed', 'refunded', 'voided' ) ) ) {
            return '<div class="woocommerce-error">' . sprintf( __( 'El estado de tu orden cambió &ldquo;%s&rdquo;. Por favor, póngase en contacto con nosotros si necesita ayuda.', 'woocommerce-gateway-wompi' ), $status ) . '</div>';
        } else {
            return '<h3>Muchas gracias por tu compra.</h3><div>En unos minutos recibirás un correo electrónico con los datos de tu pedido. Recuerda que si deseas conocer el estado de tu envío ingresa a la opción "Rastreo de orden".</div>';
        }
    }

    public function admin_options()
    {
        ?>
        <h3><?php echo $this->title; ?></h3>
        <p><?php echo $this->method_description; ?></p>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    public function process_payment($order_id)
    {
		global $woocommerce;
		$order = new WC_Order( $order_id );
        $end_point = 'https://checkout.wompi.co/p/';

        $params = [
            'public-key' => $this->public_key,
            'currency' => 'COP',
            'amount-in-cents' => bcmul($order->get_total(), 100),
            'reference' => $order_id,
			'redirect-url' => $order->get_checkout_order_received_url()
        ];

        $url = $end_point . "?" . http_build_query($params);		
        return [
            'result' => 'success',
            'redirect' => $url,
        ];		
    }

    public function confirmation_ipn()
    {		
		$response = json_decode( file_get_contents('php://input') );
		$data = $response->data;
        // Validate transaction response
        if ( isset( $data->transaction ) ) {
            $transaction = $data->transaction;
            $order = new WC_Order( $transaction->reference );

            // Update order data
            update_post_meta( $order_id, '_transaction_id', $transaction->id );
			$this->apply_status( $order, $transaction );
            status_header( 200 );

        } else {
			woo_wompi_payment_wwp()->log('TRANSACTION Response Not Found');
            status_header( 400 );
        }		
    }

    public function apply_status( $order, $transaction ) {
        switch ( $transaction->status ) {
            case 'APPROVED':
                $order->payment_complete( $transaction->id );
                $this->update_transaction_status( $order, __('Pago Wompi Aprobado (APPROVED). TRANSACTION ID: ', 'woocommerce-gateway-wompi') . ' (' . $transaction->id . ')', 'completed' );
                break;
            case 'VOIDED':
                $this->update_transaction_status( $order, __('Pago Wompi Anulado (VOIDED). TRANSACTION ID: ', 'woocommerce-gateway-wompi') . ' (' . $transaction->id . ')', 'voided' );
                break;
            case 'DECLINED':
                $this->update_transaction_status( $order, __('Pago Wompi Declinado (DECLINED). TRANSACTION ID: ', 'woocommerce-gateway-wompi') . ' (' . $transaction->id . ')', 'cancelled' );
                break;
            default : // ERROR
                $this->update_transaction_status( $order, __('Pago Wompi con Error (ERROR). TRANSACTION ID: ', 'woocommerce-gateway-wompi') . ' (' . $transaction->id . ')', 'failed' );
        }
    }

    public function update_transaction_status( $order, $note, $status ) {
        $order->add_order_note( $note );
        $order->update_status( $status );
    }	

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->description && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
			echo wpautop( wptexturize( $this->description ) ) . PHP_EOL;
		}
	}
}
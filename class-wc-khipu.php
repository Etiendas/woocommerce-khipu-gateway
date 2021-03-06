<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

/**
 * Plugin Name: WooCommerce khipu
 * Plugin URI: https://khipu.com
 * Description: khipu payment gateway for woocommerce
 * Version: 1.4
 * Author: khipu
 * Author URI: https://khipu.com
 */

add_action('plugins_loaded', 'woocommerce_khipu_init', 0);


function woocommerce_khipu_showWooCommerceNeeded()
{
    woocommerce_khipu_showMessage("Debes instalar y activar el plugin WooCommerce.", true);
}


function woocommerce_khipu_showMessage($message, $errormsg = false)
{
    if ($errormsg) {
        echo '<div id="message" class="error">';
    }
    else {
        echo '<div id="message" class="updated fade">';
    }

    echo "<p><strong>$message</strong></p></div>";
}


function woocommerce_khipu_init()
{

    require_once "lib-khipu/src/Khipu.php";


    if (!class_exists('WC_Payment_Gateway')) {

        add_action('admin_notices', 'woocommerce_khipu_showWooCommerceNeeded');

    } else {

        class WC_Gateway_khipu extends WC_Payment_Gateway
        {

            var $notify_url;

            /**
             * Constructor for the gateway.
             *
             */
            public function __construct()
            {
                $this->id = 'khipu';
                $this->icon = plugins_url('images/buttons/50x25.png', __FILE__);
                $this->has_fields = false;
                $this->method_title = __('khipu - Transferencia simplificada', 'woocommerce');
                $this->notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/')));

                // Load the settings and init variables.
                $this->init_form_fields();
                $this->init_settings();
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->receiver_id = $this->get_option('receiver_id');
                $this->secret = $this->get_option('secret');

                // Actions
                add_action('valid-' . $this->id . '-ipn-request', array($this, 'successful_request'));
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

                // Payment listener/API hook
                add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_ipn_response'));

                if (!$this->is_valid_for_use()) {
                    $this->enabled = false;
                }
            }


            /**
             * Check if this gateway is enabled and available in the user's country
             */
            function is_valid_for_use()
            {
                if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_' . $this->id . '_supported_currencies', array('CLP')))) {
                    return false;
                }
                return true;
            }

            /**
             * Admin Panel Options
             */
            public function admin_options()
            {
                ?>
                <h3><?php _e('khipu', 'woocommerce'); ?></h3>
                <p><?php _e('khipu muestra el listado de bancos soportados y levanta el terminal de pagos o redirige a la página de confirmación de pago según se necesite.', 'woocommerce'); ?></p>

                <?php if ($this->is_valid_for_use()) : ?>
                <table class="form-table">
                    <?php
                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                    ?>
                </table><!--/.form-table-->

            <?php else : ?>
                <div class="inline error">
                    <p>
                        <strong><?php _e('Gateway Disabled', 'woocommerce'); ?></strong>: <?php _e('khipu does not support your store currency.', 'woocommerce'); ?>
                    </p>
                </div>
            <?php
            endif;
            }


            /**
             * Initialise Gateway Settings Form Fields
             */
            function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable khipu', 'woocommerce'),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title' => __('Title', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                        'default' => __('Transferencia simplificada', 'woocommerce'),
                        'desc_tip' => true
                    ),
                    'description' => array(
                        'title' => __('Description', 'woocommerce'),
                        'type' => 'textarea',
                        'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
                        'default' => __('khipu incluye una aplicación simple y segura para pagar con tu banco'
                            . ' Evita errores al escribir datos de la transferencia y brinda protección adicional contra algunos tipos de ataque.'
                            . ' Si no has instalado la aplicación, la página de pago te ayuda a instalarla. Esta es la opción recomendada.', 'woocommerce')
                    ),
                    'receiver_id' => array(
                        'title' => __('Id de cobrador', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('Ingrese su Id de cobrador. Se obtiene en https://khipu.com/merchant/profile', 'woocommerce'),
                        'default' => '',
                        'desc_tip' => true
                    ),
                    'secret' => array(
                        'title' => __('Llave', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('Ingrese su llave secreta. Se obtiene en https://khipu.com/merchant/profile', 'woocommerce'),
                        'default' => '',
                        'desc_tip' => true
                    )
                );

            }

            /**
             * Get banks for this receiver_id
             */
            function get_available_banks()
            {
                $Khipu = new Khipu();
                $Khipu->authenticate($this->receiver_id, $this->secret);
                $Khipu->setAgent('woocommerce-khipu-1.3');
                $service = $Khipu->loadService('ReceiverBanks');
                return $service->consult();
            }

            function comm_error()
            {
                $msg = __('Error de comunicación con khipu, por favor intente nuevamente más tarde.');
                return "<div class='woocommerce-error'>$msg</div>";
            }

            /**
             * Create the combos to select bank and bank type.
             */
            function generate_khipu_bankselect()
            {

                $banks = json_decode($this->get_available_banks());

                if (!$banks) {
                    return $this->comm_error();
                }

                $bankSelector = "<form method=\"GET\">\n";

                foreach ($_REQUEST as $key => $value) {
                    $bankSelector .= "<input type=\"hidden\" value =\"$value\" name=\"$key\">\n";
                }

                $send_label = __('Pagar');
                $bank_selector_label = __('Selecciona tu banco:');
                $bankSelector .= <<<EOD

<div class="form-row form-row-wide">
    <label for="bank-id"><span class="required">*</span> <strong>$bank_selector_label</strong></label>
    <select id="root-bank" name="root-bank" style="width: auto;"></select>
	<select id="bank-id" name="bank-id" style="display: none; width: auto;"></select>
</div>
<div class="form-row form-row-wide">
	<input type="submit" value="$send_label">
</div>

</form>
<script>
	(function ($) {
		var messages = [];
		var bankRootSelect = $('#root-bank')
		var bankOptions = []
		var selectedRootBankId = 0
		var selectedBankId = 0
		bankRootSelect.attr("disabled", "disabled");
EOD;

                foreach ($banks->banks as $bank) {
                    if (!$bank->parent) {
                        $bankSelector .= "bankRootSelect.append('<option value=\"$bank->id\">$bank->name</option>');\n";
                        $bankSelector .= "bankOptions['$bank->id'] = [];\n";
                        $bankSelector .= "bankOptions['$bank->id'].push('<option value=\"$bank->id\">$bank->type</option>')\n";
                    } else {
                        $bankSelector .= "bankOptions['$bank->parent'].push('<option value=\"$bank->id\">$bank->type</option>');\n";
                    }
                }
                $bankSelector .= <<<EOD
	function updateBankOptions(rootId, bankId) {
		if (rootId) {
			$('#root-bank').val(rootId)
		}

		var idx = $('#root-bank :selected').val();
		$('#bank-id').empty();
		var options = bankOptions[idx];
		for (var i = 0; i < options.length; i++) {
			$('#bank-id').append(options[i]);
		}
		if (options.length > 1) {
			$('#root-bank').addClass('form-control-left');
			$('#bank-id').show();
		} else {
			$('#root-bank').removeClass('form-control-left');
			$('#bank-id').hide();
		}
		if (bankId) {
			$('#bank-id').val(bankId);
		}
		$('#bank-id').change();
	}
	$('#root-bank').change(function () {
		updateBankOptions();
	});
	$(document).ready(function () {
		updateBankOptions(selectedRootBankId, selectedBankId);
		bankRootSelect.removeAttr("disabled");
	});
})(jQuery);
</script>
EOD;

                return $bankSelector;
            }


            function generate_khipu_terminal_page()
            {
                $json_string = stripslashes($_GET['payment-data']);

                $response = json_decode($json_string);

                $readyForTerminal = 'ready-for-terminal';

                if (!$response->$readyForTerminal) {
                    wp_redirect($response->url);
                    return;
                }

                // Add the external libraries
                wp_enqueue_script('atmosphere', '//cdnjs.cloudflare.com/ajax/libs/atmosphere/2.1.2/atmosphere.min.js');
                wp_enqueue_script('khipu-js', '//storage.googleapis.com/installer/khipu-1.1.js', array('jquery'));

                $waitMsg = __('Estamos iniciando el terminal de pagos khipu, por favor espera unos minutos.<br>No cierres esta página, una vez que completes el pago serás redirigido automáticamente.');
                $out = <<<EOD
<div id="wait-msg" class="woocommerce-info">$waitMsg</div>
<div id="khipu-chrome-extension-div" style="display: none"></div>
<script>
window.onload = function () {
    KhipuLib.onLoad({
        data: $json_string
    })
}
</script>
EOD;
                return $out;
            }

            /**
             * Create the payment on khipu and try to start the app.
             */
            function generate_khipu_generate_payment($order_id)
            {

                $order = new WC_Order($order_id);

                $Khipu = new Khipu();
                $Khipu->authenticate($this->receiver_id, $this->secret);
                $Khipu->setAgent('woocommerce-khipu-1.3');
                $create_page_service = $Khipu->loadService('CreatePaymentURL');

                $item_names = array();

                if (sizeof($order->get_items()) > 0) {
                    foreach ($order->get_items() as $item) {
                        if ($item['qty']) {
                            $item_names[] = $item['name'] . ' x ' . $item['qty'];
                        }
                    }
                }

                $create_page_service->setParameter('subject', 'Orden ' . $order->get_order_number() . ' - ' . get_bloginfo('name'));
                $create_page_service->setParameter('body', implode(', ', $item_names));
                $create_page_service->setParameter('amount', number_format($order->get_total(), 0, ',', ''));
                $create_page_service->setParameter('transaction_id', ltrim($order->get_order_number(), '#'));
                $create_page_service->setParameter('custom', serialize(array($order_id, $order->order_key)));
                $create_page_service->setParameter('payer_email', $order->billing_email);
                $create_page_service->setParameter('notify_url', $this->notify_url);
                $create_page_service->setParameter('bank_id', $_REQUEST['bank-id']);
                $create_page_service->setParameter('return_url', $this->get_return_url($order));

                // We need the string json to use it with the khipu.js
                $json_string = $create_page_service->createUrl();

                if (!$json_string) {
                    return $this->comm_error();
                }
                wp_redirect(add_query_arg(array('payment-data' => urlencode($json_string)), remove_query_arg(array('bank-id'), wp_get_referer())));

                return;
            }

            /**
             * Process the payment and return the result
             */
            function process_payment($order_id)
            {

                $order = new WC_Order($order_id);
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
                );
            }

            /**
             * Output for the order received page.
             */
            function receipt_page($order)
            {
                if ($_REQUEST['payment-data']) {
                    echo $this->generate_khipu_terminal_page();
                } else if ($_REQUEST['bank-id']) {
                    echo $this->generate_khipu_generate_payment($order);
                } else {
                    echo $this->generate_khipu_bankselect();
                }
            }

            /**
             * Check Khipu IPN validity
             **/
            function check_ipn_request_is_valid()
            {
                $Khipu = new Khipu();
                $_POST = array_map('stripslashes', $_POST);
                $Khipu->authenticate($this->receiver_id, $this->secret);
                $Khipu->setAgent('woocommerce-khipu-1.3');
                $create_page_service = $Khipu->loadService('VerifyPaymentNotification');
                $create_page_service->setDataFromPost();
                if ($_POST['receiver_id'] != $this->receiver_id) {
                    return false;
                }

                $verify = $create_page_service->verify();
                return $verify['response'] == 'VERIFIED';
            }

            /**
             * Check for Khipu IPN Response
             */
            function check_ipn_response()
            {
                @ob_clean();

                if (!empty($_POST) && $this->check_ipn_request_is_valid()) {
                    header('HTTP/1.1 200 OK');
                    do_action("valid-khipu-ipn-request", $_POST);
                } else {
                    wp_die("khipu notification validation failed");
                }
            }


            /**
             * Successful Payment
             */
            function successful_request($posted)
            {
                $posted = stripslashes_deep($posted);

                if (!empty($posted['transaction_id']) && !empty($posted['custom'])) {

                    $order = $this->get_khipu_order($posted);

                    if ($order->status == 'completed') {
                        exit;
                    }

                    $order->add_order_note(__('Pago con khipu verificado', 'woocommerce'));
                    $order->payment_complete();
                }
            }


            /**
             * get_khipu_order function.
             */
            function get_khipu_order($posted)
            {
                $custom = maybe_unserialize($posted['custom']);

                // Backwards comp for IPN requests
                if (is_numeric($custom)) {
                    $order_id = (int)$custom;
                    $order_key = $posted['transaction_id'];
                } elseif (is_string($custom)) {
                    $order_id = (int)str_replace($this->invoice_prefix, '', $custom);
                    $order_key = $custom;
                } else {
                    list($order_id, $order_key) = $custom;
                }

                $order = new WC_Order($order_id);

                if (!isset($order->id)) {
                    $order_id = woocommerce_get_order_id_by_order_key($order_key);
                    $order = new WC_Order($order_id);
                }

                // Validate key
                if ($order->order_key !== $order_key) {
                    if ($this->debug == 'yes') {
                        $this->log->add('paypal', 'Error: Order Key does not match invoice.');
                    }
                    exit;
                }

                return $order;
            }

        }

        /**
         * Add the Gateway to WooCommerce
         **/
        function woocommerce_add_khipu_gateway($methods)
        {
            $methods[] = 'WC_Gateway_khipu';
            return $methods;
        }

        add_filter('woocommerce_payment_gateways', 'woocommerce_add_khipu_gateway');

        function woocommerce_khipu_add_clp_currency($currencies)
        {
            $currencies["CLP"] = __('Pesos Chilenos');
            return $currencies;
        }

        function woocommerce_khipu_add_clp_currency_symbol($currency_symbol, $currency)
        {
            switch ($currency) {
                case 'CLP':
                    $currency_symbol = '$';
                    break;
            }
            return $currency_symbol;
        }

        add_filter('woocommerce_currencies', 'woocommerce_khipu_add_clp_currency', 10, 1);
        add_filter('woocommerce_currency_symbol', 'woocommerce_khipu_add_clp_currency_symbol', 10, 2);
    }

}

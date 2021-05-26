<?php

/*
 * Plugin Name: AsianSuperPay for WooCommerce
 * Plugin URI: https://astutemyndz.com
 * Description: AsianSuperPay Payment Gateway Integration for WooCommerce
 * Version: 1.0.0
 * Stable tag: 1.0.0
 * Author: Anik Banerjee
 * WC tested up to: 5.3.0
 * Author URI: https://astutemyndz.com
*/

include('Math/MathInterface.php');
include('Math/Bc.php');
include('Math/Gmp.php');
include('HashidsInterface.php');
include('HashidsException.php');
include('Hashids.php');

// check if wocommerce is activated
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

add_action('plugins_loaded', 'woocommerce_asppay_init', 0);

function woocommerce_asppay_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_AspPay extends WC_Payment_Gateway
    {
        const DEFAULT_LABEL                  = 'Credit Card/Debit Card/UPI';
        const DEFAULT_DESCRIPTION            = 'Pay securely by Credit or Debit card or Internet Banking through AsianSuperPay.';
        const DEFAULT_SUCCESS_MESSAGE        = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';

        public $id = 'asppay';
        public $has_fields = false;
        public $method_title = 'AsianSuperPay';
        public $method_description = 'Allow customers to securely pay via AsianSuperPay';
        public $icon;
        public $currency_code;

        public $country_info = [
            'INR' => [
                'country_code' => 'IN',
                'timezone' => 'Asia/Calcutta'
            ]
        ];

        public function __construct()
        {
            

            $this->init_form_fields();
            $this->init_settings();

            $liveMode = 'yes' === $this->get_option('livemode');
            $this->endpoint = $liveMode ? 'https://api.pbmf014056.com' : 'https://sitapi.pztc979894.com';
            $this->merchant_id = $this->get_option('merchant_id');
            $this->merchant_key = $this->get_option('merchant_key');
            $this->title = $this->get_option('title');
            $this->currency_code = $this->get_option('currency');
            date_default_timezone_set($this->country_info[$this->currency_code]['timezone']);

            $this->icon = plugins_url('logo.png', __FILE__);

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_asp-payment', array($this, 'asp_payment_deposit'));
            add_action('woocommerce_api_payment-success', array($this, 'payment_success'));
        }

        public function init_form_fields()
        {
            $defaultFormFields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', $this->id),
                    'type' => 'checkbox',
                    'label' => __('Enable this module?', $this->id),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', $this->id),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', $this->id),
                    'default' => __(static::DEFAULT_LABEL, $this->id),
                    'desc_tip' => true
                ),
                'description' => array(
                    'title' => __('Description', $this->id),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', $this->id),
                    'default' => __(static::DEFAULT_DESCRIPTION, $this->id),
                    'desc_tip' => true
                ),
                'livemode' => array(
                    'title'       => 'Live mode',
                    'label'       => 'Enable Live Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in live mode',
                    'desc_tip'    => true,
                ),
                'merchant_id' => array(
                    'title'       => 'Merchant Id',
                    'type'        => 'text',
                ),
                'merchant_key' => array(
                    'title'       => 'Merchant Key',
                    'type'        => 'text',
                ),
                'currency' => array(
                    'title' => 'Currency Code',
                    'type' => 'select',
                    'description' => 'For ex. INR for India',
                    'default' => 'INR',
                    'options' => array('INR' => 'INR'),
                    'desc_tip'    => true,
                )
            );

            foreach ($defaultFormFields as $key => $value) {
                $this->form_fields[$key] = $value;
            }
        }

        public function process_payment($order_id)
        {
            global $woocommerce;

            $woocommerce->session->set('asp-order-id', $order_id);

            return array(
                'result' => 'success',
                'redirect' => home_url("/wc-api/asp-payment?id=$order_id")
            );
        }

        public function encrypt_order_id($order_id)
        {
            $hashids = new Hashids\Hashids();
            return $hashids->encode($order_id, time());
        }

        public function decrypt_order_id($encrypted_text)
        {
            $hashids = new Hashids\Hashids();
            return $hashids->decode($encrypted_text)[0];
        }

        public function asp_payment_deposit()
        {

            global $woocommerce;

            $order_id = $_GET['id'];
            $asp_order_id = $woocommerce->session->get('asp-order-id');
            $woocommerce->session->__unset('asp-order-id');

            if ($asp_order_id != $order_id) {
                echo 'Invalid Request';
                exit;
            }

            $deposit_endpoint = $this->endpoint . '/v2/transaction/deposit';
            $order = wc_get_order($order_id);
            $country_code =  $this->country_info[$this->currency_code]['country_code'];
            $order_total = $order->get_total();
            $txn_date = date('YmdHis');
            $merchant_ref = $this->encrypt_order_id($order_id);
            $signature = hash('sha256', $order_total . $this->currency_code . $merchant_ref . $this->merchant_id . $txn_date . $this->merchant_key);

            $args = array(
                'Merchant_ID' => $this->merchant_id,
                'Country' => $country_code,
                'Merchant_Ref' => $merchant_ref,
                'Currency' => $this->currency_code,
                'Amount' => $order_total,
                'Mer_txn_date' => $txn_date,
                'Success_URL' => $order->get_checkout_order_received_url(),
                'Fail_URL' => home_url('/checkout'),
                'Callback_URL' => home_url('/wc-api/payment-success'),
                'Signature' => $signature,
                'Verno' => '03'
            );

            $response = wp_remote_post($deposit_endpoint, array(
                'headers'     => ['Content-Type' => 'application/json'],
                'body'        => wp_json_encode($args),
                'data_format' => 'body',
                'timeout' => 200
            ));

            $response_body = wp_remote_retrieve_body($response);
            $response_body = str_replace("action=\"", "action=\"" . $this->endpoint, $response_body);

            echo $response_body;
            exit;
        }

        public function payment_success()
        {
            global $woocommerce;

            $response = json_decode(file_get_contents("php://input"), true);
            $order_id = $this->decrypt_order_id($response['Merchant_Ref']);
            $order = wc_get_order($order_id);

            if ($response['Status'] == 0 && ($order->needs_payment() === true)) {
                $payment_id = $response['Order_Ref'];
                $order->payment_complete($payment_id);
                $order->add_order_note("AsianSuperPay payment successful <br/>Payment Id: $payment_id");
                $order->reduce_order_stock();

                if (isset($woocommerce->cart) === true) {
                    $woocommerce->cart->empty_cart();
                }
            } 
            else {
                $status_msg = $response['Status_Msg'];
                $order->update_status('failed');
                $order->add_order_note("Transaction Failed: $status_msg<br/>");
            }
        }
    }
}

function woocommerce_add_asppay_gateway($gateways)
{
    $gateways[] = 'WC_AspPay';
    return $gateways;
}


add_filter('woocommerce_payment_gateways', 'woocommerce_add_asppay_gateway');

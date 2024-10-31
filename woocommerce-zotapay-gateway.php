<?php

/* ZotaPay Payment Gateway Class */

class WC_ZotaPay_GateWay extends WC_Payment_Gateway
{

    const HOST_DEV = 'https://mg-sandbox.zotapay.com/api/';
    const HOST_PROD = 'https://mg-api.zotapay.com/api/';

    const API_VERSION = 'v1';

    const FORM_SALE_ENDPOINT = '/deposit/request/';

    protected $end_point_id;

    protected $control_key;

    protected $currency;

    protected $method;

    protected $debug = false;

    protected $test_mode;

    function __construct()
    {
        $this->id = "wc-ztp-gateway";

        $this->method_title = __("ZotaPay", 'wc-ztp-gateway');

        $this->method_description = __("ZotaPay Payment Gateway Plug-in for WooCommerce", 'wc-ztp-gateway');

        $this->title = __("ZotaPay", 'wc-ztp-gateway');

        $this->icon = null;

        $this->has_fields = true;

        $this->supports = array('payments');

        $this->init_form_fields();

        $this->init_settings();

        // Turn these settings into variables we can use
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        add_action('admin_notices', [$this, 'do_ssl_check']);
        add_action('woocommerce_api_callback', [$this, 'callback_handler']);

        // Save settings
        if (is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }
    }

    public function getHost()
    {
        $host = ($this->test_mode === 'yes' || !$this->test_mode ? self::HOST_DEV : self::HOST_PROD);
        $host .= self::API_VERSION;
        return $host;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'wc-ztp-gateway'),
                'label' => __('Enable this payment gateway', 'wc-ztp-gateway'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'test_mode' => array(
                'title' => __('Test Mode', 'wc-ztp-gateway'),
                'label' => __('Enable / Disable test mode', 'wc-ztp-gateway'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'wc-ztp-gateway'),
                'type' => 'text',
                'desc_tip' => __('Payment title the customer will see during the checkout process.', 'wc-ztp-gateway'),
                'default' => __('ZotaPay', 'wc-ztp-gateway'),
            ),
            'description' => array(
                'title' => __('Description', 'wc-ztp-gateway'),
                'type' => 'textarea',
                'desc_tip' => __('Payment description the customer will see during the checkout process.', 'wc-ztp-gateway'),
                'default' => __('Pay via ZotaPay payment.', 'wc-ztp-gateway'),
                'css' => 'max-width:400px;'
            ),
            /*'method' => array(
                'title' => __('Integration method', 'wc-ztp-gateway'),
                'type' => 'select',
                'desc_tip' => __('Define what payment experience your customers will have, whether inside or outside your store.', 'woocommerce-mercadopago'),
                'default' => $this->method,
                'options' => array(
                    'redirect' => __('Redirect', 'wc-ztp-gateway'),
                    'iframe' => __('iFrame', 'wc-ztp-gateway')
                )
            ),*/
            'control_key' => array(
                'title' => __('ZotaPay Control Key', 'wc-ztp-gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the Control Key provided by ZotaPay when you signed up for an account.', 'wc-ztp-gateway'),
            ),
            'currency' => array(
                'title' => __('ZotaPay Currency', 'wc-ztp-gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the Currency provided by ZotaPay when you signed up for an account.', 'wc-ztp-gateway'),
            ),
            'end_point_id' => array(
                'title' => __('ZotaPay End Point ID', 'wc-ztp-gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the End Point ID provided by ZotaPay when you signed up for an account.', 'wc-ztp-gateway'),
            )
        );
    }

    /**
     * @param $key
     * @param $value
     * @return string
     */
    public function validate_currency_field( $key, $value){
        if( get_woocommerce_currency() === $value){
            return $value;
        } else{
            $shopCurrency = get_woocommerce_currency();
            WC_Admin_Settings::add_error("Your currency should be equal to {$shopCurrency}");
        }
        return $this->currency;
    }

    /**
     * @param int $order_id
     * @return array
     * @throws Exception
     */
    public function process_payment($order_id)
    {
        $storeCurrency = get_option('woocommerce_currency');
        $endpoint = $this->getStoreEndpointId($storeCurrency);
        $customer_order = new WC_Order($order_id);
        $url_complete = get_site_url() . '/checkout/order-received/' . $order_id . '/';
        $checkout_url = get_site_url() . '/checkout/';
        $url_callback = get_site_url() . '/wc-api/CALLBACK/';
        $environment_url = $this->getHost() . self::FORM_SALE_ENDPOINT . $endpoint['id'];
        $payload = $this->getFormSaleRequestParameters($customer_order, $url_complete, $url_callback, $checkout_url, $endpoint);
        $response = wp_remote_post($environment_url, array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'method' => 'POST',
            'body' => json_encode($payload),
            'data_format' => 'body',
            'timeout' => 90,
            'sslverify' => false,
        ));
        if (is_wp_error($response)) {
            throw new Exception(__('We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.', 'wc-ztp-gateway'));
        }
        if (empty($response['body'])) {
            throw new Exception(__('ZotaPay\'s Response was empty.', 'wc-ztp-gateway'));
        }
        $params = json_decode($response['body']);
        if ($params->code !== '200') {
            throw new Exception(__($this->humanizeErrorMessage($params), 'wc-ztp-gateway'));
        }

        $customer_order->update_status('processing', __('Waiting for payment of the order', 'wc-ztp-gateway'));

        return [
            'result' => 'success',
            'redirect' => $params->data->depositUrl
        ];

    }

    public function callback_handler()
    {
        global $woocommerce;
        $params = json_decode(file_get_contents('php://input'));

        if ($this->debug) {
            $this->log(print_R($params, true));
        }
        $merchant_order_id = $params->merchantOrderID ?? 0;
        $order_id = $params->orderID ?? 0;
        $signature = $params->signature ?? false;
        $status = $params->status ?? false;
        $endpoint_id = $params->endpointID ?? false;
        $amount = $params->amount ?? false;
        $customer_email = $params->customerEmail ?? false;
        $customer_order = new WC_Order($merchant_order_id);
        if (!$customer_order) {
            return false;
        }
        if ($status === 'APPROVED') {
            if ($this->debug) {
                $this->log('Approved');
            }
            if ($this->isCallbackSignatureValid($signature, $endpoint_id, $order_id, $merchant_order_id, $status, $amount, $customer_email)) {
                if ($this->debug) {
                    $this->log('Valid');
                }
                $customer_order->update_status('completed', __('ZotaPay Payment success', 'wc-ztp-gateway'));
                $woocommerce->cart->empty_cart();
                return true;
            } else {
                if ($this->debug) {
                    $this->log('Not Valid');
                }
            }
        } else {
            $error_message = ($params->errorMessage ?? 'Unknown error') . " ({$status})";
            $customer_order->update_status('failed', __($error_message, 'wc-ztp-gateway'));
            if ($this->debug) {
                $this->log("Not approved: '{$status}'");
            }
        }
        return false;
    }

    public function humanizeErrorMessage($params)
    {
        switch ($params->code) {
            case 2:
                return 'Something went wrong. Please contact us.';
            default:
                return $params->message;
        }
    }

    private function getFormSaleRequestParameters($customer_order, $url_complete, $url_callback, $checkout_url, $endpoint)
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $orderId = $customer_order->get_order_number();
        $price = $customer_order->order_total;
        $email = $customer_order->billing_email ?: 'n/a';

        if (isset($customer_order->billing_phone) && $customer_order->billing_phone) {
            $phone = $customer_order->billing_phone;
        } else {
            $this->fillDuplicateParametersField($phone, $customer_order, 'phone');
        }

        $this->fillDuplicateParametersField($firstName, $customer_order, 'first_name');
        $this->fillDuplicateParametersField($lastName, $customer_order, 'last_name');
        $this->fillDuplicateParametersField($country, $customer_order, 'country');
        if (in_array($country, ['AU', 'CA', 'US'])) {
            $this->fillDuplicateParametersField($state, $customer_order, 'state');
        } else {
            $state = 'n/a';
        }
        $this->fillDuplicateParametersField($city, $customer_order, 'city');
        $this->fillDuplicateParametersField($zipCode, $customer_order, 'postcode');


        $address = '';
        if (isset($customer_order->shipping_address_1) && $customer_order->shipping_address_1) {
            $address = $customer_order->shipping_address_1;
            $address .= isset($customer_order->shipping_address_2)
                ? $customer_order->shipping_address_2
                : '';
        } elseif (isset($customer_order->billing_address_1) && $customer_order->billing_address_1) {
            $address = $customer_order->billing_address_1;
            $address .= isset($customer_order->billing_address_2)
                ? $customer_order->billing_address_2
                : '';
        }

        $parameters = [
            'orderCurrency' => $endpoint['currency'], #currency
            'merchantOrderID' => $orderId, #client_orderid
            'orderAmount' => $price, #amount
            'merchantOrderDesc' => "Order $orderId", #order_desc
            'customerEmail' => $email, #email
            'customerFirstName' => $firstName ?: 'n/a', #first_name
            'customerLastName' => $lastName ?: 'n/a', #last_name
            'customerCountryCode' => $country ?: 'n/a', #country
            'customerState' => $state ?: 'n/a', #state
            'customerCity' => $city ?: 'n/a', #city
            'customerAddress' => $address ?: 'n/a', #address1
            'customerZipCode' => $zipCode ?: 'n/a', #zip_code
            'customerPhone' => $phone ?: 'n/a', #phone
            'customerIP' => $ipAddress, #ipaddress
            'redirectUrl' => $url_complete, #redirect_url
            'callbackUrl' => $url_callback, #server_callback_url
            'checkoutUrl' => $checkout_url,
            'signature' => $this->generateSaleSignature($orderId, $price, $email, $endpoint) #control
        ];

        return $parameters;
    }

    private function fillDuplicateParametersField(&$field, $customer_order, $name)
    {
        $billing_field = 'billing_' . $name;
        $shipping_field = 'shipping_' . $name;
        if (isset($customer_order->$shipping_field) && $customer_order->$shipping_field) {
            $field = $customer_order->$shipping_field;
        } elseif (isset($customer_order->$billing_field) && $customer_order->$billing_field) {
            $field = $customer_order->$billing_field;
        }
    }

    public function isCallbackSignatureValid($signature, $endpoint_id, $order_id, $merchant_order_id, $status, $amount, $customer_email)
    {
        $providedSignature = $signature;
        $expectedSignature = $this->generateSignature($endpoint_id . $order_id . $merchant_order_id . $status . $amount . $customer_email);
        return strcmp($providedSignature, $expectedSignature) === 0;
    }

    /*
     * http://doc.zotapay.com/card_payment_API/sale-transactions.html?highlight=payment%20form#request-authorization-through-control-parameter
     */
    private function generateSaleSignature($orderId, $price, $email, $endpoint)
    {
        return $this->generateSignature($endpoint['id'] . $orderId . $price . $email);
    }

    private function generateSignature($parametersString)
    {
        return hash('sha256', $parametersString . $this->control_key);
    }

    private function getStoreEndpointId($storeCurrency = null)
    {
        $currency = $this->currency;
        $endpointId = $this->end_point_id;

        if ($storeCurrency !== $currency) {
            throw new InvalidArgumentException('This store has a currency mismatch issue. Please contact the shop owner.');
        }

        if (!$currency && !$endpointId) {
            throw new InvalidArgumentException('Set endpoint ID and currency in settings panel');
        }

        return [
            'id' => $endpointId,
            'currency' => $currency
        ];
    }

    public function validate_fields()
    {
        return true;
    }

    public function do_ssl_check()
    {
        if ($this->enabled == "yes") {
            if (get_option('woocommerce_force_ssl_checkout') == "no") {
                echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>";
            }
        }
    }

    private function log($message)
    {
        $timeMark = date('d.m.Y H:i:s');
        $file = dirname(__FILE__) . '/debug_callback.txt';
        $handle = fopen($file, 'a+');
        fputs($handle, "\n\n[{$timeMark}]{$message}\n\n");
        fclose($handle);
    }

}

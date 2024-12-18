<?php

defined('_JEXEC') or die('Restricted access');

class plgVmPaymentPaymentjoomla_v5 extends vmPSPlugin
{

    function __construct(&$subject, $config)
    {

        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        $this->setConvertable(array('min_amount', 'max_amount', 'cost_per_transaction', 'cost_min_transaction'));
    }

    public function getVmPluginCreateTableSQL()
    {

        return $this->createTableSQL('Payment Standard Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return string SQL Fileds
     */
    function getTableSQLFields()
    {

        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'merchant_id' => 'char(64)',
            'test_url' => 'char(64)',
            'redirect_url' => 'char(64)',
            'secret_key' => 'char(64)',
            'partner_name' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_min_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)'
        );

        return $SQLfields;
    }


    static function getPaymentCurrency(&$method, $selectedUserCurrency = false)
    {

        if (empty($method->payment_currency)) {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $method->payment_currency = $vendor->vendor_currency;
            return $method->payment_currency;
        } else {

            $vendor_model = VmModel::getModel('vendor');
            $vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies($method->virtuemart_vendor_id);

            if (!$selectedUserCurrency) {
                if ($method->payment_currency == -1) {
                    $mainframe = JFactory::getApplication();
                    $selectedUserCurrency = $mainframe->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt('virtuemart_currency_id', $vendor_currencies['vendor_currency']));
                } else {
                    $selectedUserCurrency = $method->payment_currency;
                }
            }

            $vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
            if (in_array($selectedUserCurrency, $vendor_currencies['all_currencies'])) {
                $method->payment_currency = $selectedUserCurrency;
            } else {
                $method->payment_currency = $vendor_currencies['vendor_currency'];
            }

            return $method->payment_currency;
        }

    }
 
    function plgVmConfirmedOrder($cart, $order)
{
    // Retrieve payment method details
    $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
    if (!$method || !$this->selectedThisElement($method->payment_element)) {
        return NULL; // Another method was selected or not applicable, do nothing
    }

    // Load necessary languages
    vmLanguage::loadJLang('com_virtuemart', true);
    vmLanguage::loadJLang('com_virtuemart_orders', true);

    // Get currency and payment details
    $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
    $currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
    $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);

    $merchant_id = $method->merchant_id;
    $partner_name = $method->partner_name;
    $amount = $totalInPaymentCurrency['value'];
    $merchantTransactionId = $order['details']['BT']->order_number;
    $redirect_url = $method->redirect_url;
    $secret_key = $method->secret_key;

    $checksum_maker = $merchant_id . '|' . $partner_name . '|' . $amount . '|' . $merchantTransactionId . '|' . $redirect_url. '|' . $secret_key;
                
    $checksum = md5($checksum_maker);
    
    // Prepare database values for payment storage
    $dbValues = [
        'payment_name' => $this->renderPluginName($method),
        'merchant_id' => $method->merchant_id,
        'test_url' => $method->test_url,
        'partner_name' => $method->partner_name,
        'secret_key' => $method->secret_key,
        'redirect_url' => $method->redirect_url,
        'currency' => $currency_code_3,
        'amount' => $totalInPaymentCurrency['value'],
        'checksum' => $checksum,
        'description' => $order['details']['BT']->order_number,
    ];
    $this->storePSPluginInternalData($dbValues);

    // Update order status
    $modelOrder = VmModel::getModel('orders');
    $order['order_status'] = $this->getNewStatus($method);
    $order['customer_notified'] = 1;
    $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);

    // Empty the cart
    $cart->emptyCart();

    // Prepare payment form for automatic submission
    $formFields = [
        'toid' => htmlspecialchars($method->merchant_id),
        'totype' => htmlspecialchars($method->partner_name),
        'merchantRedirectUrl' => htmlspecialchars($method->redirect_url),
        'amount' => htmlspecialchars($totalInPaymentCurrency['value']),
        'description' => htmlspecialchars($order['details']['BT']->order_number),
        'currency' => htmlspecialchars($currency_code_3),
        'checksum' => htmlspecialchars($checksum),
    ];

    $form = "
    <html>
    <body onload='document.paymentForm.submit()'>
        <form id='paymentForm' name='paymentForm' method='post' action='" . htmlspecialchars($method->test_url) . "'>
            " . implode('', array_map(function ($name, $value) {
                return "<input type='hidden' name='$name' value='$value'>";
            }, array_keys($formFields), $formFields)) . "
        </form>
        <p>Redirecting to the payment gateway...</p>
    </body>
    </html>
    ";

    echo $form; // Output the form to the user and submit it automatically
    exit(); // Stop further script execution after the redirect
}


// Background process to handle order updates after the redirect
protected function storeOrderDataInBackground($cart, $order, $method)
{
    $dbValues['order_number'] = $order['details']['BT']->order_number;
    $dbValues['merchant_id'] = $method->merchant_id;
    $dbValues['test_url'] = $method->test_url;
    $dbValues['virtuemart_paymentmethod_id'] = (int) $order['details']['BT']->virtuemart_paymentmethod_id;
    $dbValues['payment_order_total'] = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency)['value'];
    $this->storePSPluginInternalData($dbValues);

    // Update order status
    $modelOrder = VmModel::getModel('orders');
    $order['order_status'] = $this->getNewStatus($method);
    $order['customer_notified'] = 1;
    $order['comments'] = '';
    $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);

    // Empty the cart
    $cart->emptyCart();
}

    

    /*
     * Keep backwards compatibility
     * a new parameter has been added in the xml file
     */
    function getNewStatus($method)
    {

        if (isset($method->status_pending) and $method->status_pending != "") {
            return $method->status_pending;
        } else {
            return 'P';
        }
    }

    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {

        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return NULL;
        }
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        if ($paymentTable->email_currency) {
            $html .= $this->getHtmlRowBE('STANDARD_EMAIL_CURRENCY', $paymentTable->email_currency);
        }
        $html .= '</table>' . "\n";
        return $html;
    }


    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {

        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {

        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {

        return $this->displayListFE($cart, $selected, $htmlIn);
    }


    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {

        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {

        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmOnUserInvoice($orderDetails, &$data)
    {

        if (!($method = $this->getVmPluginMethod($orderDetails['virtuemart_paymentmethod_id']))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }
        //vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

        if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null == 1 or $orderDetails['order_total'] > 0.00) {
            return NULL;
        }

        if ($orderDetails['order_salesPrice'] == 0.00) {
            $data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
        }

    }
    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return bool|null
     */
    function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
    {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }

        if (empty($method->email_currency)) {

        } else if ($method->email_currency == 'vendor') {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $emailCurrencyId = $vendor->vendor_currency;
        } else if ($method->email_currency == 'payment') {
            $emailCurrencyId = $this->getPaymentCurrency($method);
        }


    }

    function plgVmOnShowOrderPrintPayment($order_number, $method_id)
    {

        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {

        return $this->setOnTablePluginParams($name, $id, $table);
    }

    public function plgVmOnPaymentNotification() {

        echo "<script>console.log('confirm order clicked')</script>";

        return true;
    }

}

// No closing tag

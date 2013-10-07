<?php

require_once dirname(__FILE__) . '/../../paymill/v2/lib/Services/Paymill/PaymentProcessor.php';
require_once dirname(__FILE__) . '/../../paymill/v2/lib/Services/Paymill/LoggingInterface.php';

/**
 * validation
 *
 * @category   PayIntelligent
 * @copyright  Copyright (c) 2013 PayIntelligent GmbH (http://payintelligent.de)
 */
class PigmbhpaymillValidationModuleFrontController extends ModuleFrontController implements Services_Paymill_LoggingInterface
{

    public function initContent()
    {
        session_start();
        unset($_SESSION['log_id']);
        $_SESSION['log_id'] = time();
        $db = Db::getInstance();
        $token = Tools::getValue('paymillToken');
        $payment = Tools::getValue('payment');
        $validPayments = array();
        if (Configuration::get('PIGMBH_PAYMILL_DEBIT')) {
            $validPayments[] = 'debit';
        }
        if (Configuration::get('PIGMBH_PAYMILL_CREDITCARD')) {
            $validPayments[] = 'creditcard';
        }

        $this->paramName = "start_process";
        if (empty($token)) {
            $this->log('No paymill token was provided. Redirect to payments page.', null);
            Tools::redirectLink(__PS_BASE_URI__ . 'order.php?step=1');
        } elseif (!in_array($payment, $validPayments)) {
            $this->log('The selected Paymentmethod is not valid.', $payment);
            Tools::redirectLink(__PS_BASE_URI__ . 'order.php?step=1');
        }
        $this->log('Start processing payment with token', $token);


        $paymentProcessor = new Services_Paymill_PaymentProcessor(Configuration::get('PIGMBH_PAYMILL_PRIVATEKEY'), "https://api.paymill.com/v2/");

        $cart = $this->context->cart;
        $user = $this->context->customer;
        $shop = $this->context->shop;
        foreach ($this->module->getCurrency((int) $cart->id_currency) as $currency) {
            if ($currency['id_currency'] == $cart->id_currency) {
                $iso_currency = $currency['iso_code'];
                break;
            }
        }

        $paymentProcessor->setAmount((int) ($cart->getOrderTotal(true, Cart::BOTH) * 100));
        $paymentProcessor->setPreAuthAmount($_SESSION['pigmbhPaymill']['authorizedAmount']);
        $paymentProcessor->setToken($token);
        $paymentProcessor->setCurrency(strtolower($iso_currency));
        $paymentProcessor->setName($user->lastname . ', ' . $user->firstname);
        $paymentProcessor->setEmail($user->email);
        $paymentProcessor->setDescription($shop->name . ' ' . $user->email);
        $paymentProcessor->setLogger($this);
        $paymentProcessor->setSource(Configuration::get('PIGMBH_PAYMILL_VERSION') . "_prestashop_" . _PS_VERSION_);
        if ($payment == 'creditcard') {
            $userData = $db->getRow('SELECT `clientId`,`paymentId` FROM `pigmbh_paymill_creditcard_userdata` WHERE `userId`=' . $this->context->customer->id);
        } elseif ($payment == 'debit') {
            $userData = $db->getRow('SELECT `clientId`,`paymentId` FROM `pigmbh_paymill_directdebit_userdata` WHERE `userId`=' . $this->context->customer->id);
        }

        $paymentProcessor->setClientId(!empty($userData['clientId']) ? $userData['clientId'] : null);
        $paymentProcessor->setPaymentId($token === 'dummyToken' && !empty($userData['paymentId']) ? $userData['paymentId'] : null);

        $result = $paymentProcessor->processPayment();
        $this->paramName = "result";
        $this->log(
            'Payment processing resulted in'
            , ($result ? 'Success' : 'Fail')
        );
        // finish the order if payment was sucessfully processed
        if ($result === true) {
            $this->saveUserData($paymentProcessor->getClientId(), $paymentProcessor->getPaymentId());
            $this->module->validateOrder(
                (int) $this->context->cart->id, Configuration::get('PS_OS_PREPARATION'), $cart->getOrderTotal(true, Cart::BOTH), $this->module->displayName, null, array(), null, false, $user->secure_key);
            Tools::redirectLink(__PS_BASE_URI__ . 'order-confirmation.php?key=' . $user->secure_key . '&id_cart=' . (int) $cart->id . '&id_module=' . (int) $this->module->id . '&id_order=' . (int) $this->module->currentOrder);
        } else {
            Tools::redirectLink(__PS_BASE_URI__ . 'order.php&step=3&paymillerror=1&paymillpayment='.$payment);
        }
    }

    public function log($message, $debugInfo)
    {
        $db = Db::getInstance();
        if (Configuration::get('PIGMBH_PAYMILL_LOGGING') === 'on') {
            $data = array(
                'identifier' => $_SESSION['log_id'],
                'debug' => $debugInfo,
                'message' => $message
            );
            $db->insert('pigmbh_paymill_logging', $data, false, false, DB::INSERT, false);
        }
    }

    private function saveUserData($clientId, $paymentId)
    {
        $db = Db::getInstance();
        $userId = $this->context->customer->id;
        $table = Tools::getValue('payment') == 'creditcard' ? 'pigmbh_paymill_creditcard_userdata' : 'pigmbh_paymill_directdebit_userdata';
        $data = array();
        $data['clientId'] = $clientId;

        //change payment only when fastchekout is active
        if (Configuration::get('PIGMBH_PAYMILL_FASTCHECKOUT') === 'on') {
            $data['paymentId'] = $paymentId;
        }

        try {
            $query = "SELECT COUNT(*) FROM $table WHERE clientId='$clientId';";
            $count = (int)$db->getValue($query);
            if ($count === 0) {
                //insert
                $this->log("Inserted new data.", var_export($data, true));
                $data['userId'] = $userId;
                $db->insert($table, $data, false, false, DB::INSERT, false);
            } elseif($count === 1) {
                //update
                $this->log("Updated data.", var_export($data, true));
                $db->update($table, $data, 'userId="' . $userId . '"', 0, false, false, false);
            }
        } catch (Exception $exception) {
            $this->log("Failed saving UserData. " . $exception->getMessage());
        }
    }

}

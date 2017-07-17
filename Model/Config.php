<?php
namespace Conekta\Payments\Model;

use Conekta\Webhook;
use Conekta\Error;
class Config extends \Magento\Payment\Model\Method\AbstractMethod {
    const CODE = 'conekta_config';
    protected $_code = self::CODE;

    public static  function checkBalance($order, $total) {
        $amount = 0;
        foreach ($order['line_items'] as $line_item) {
            $amount = $amount +
                ($line_item['unit_price'] * $line_item['quantity']);
        }
        foreach ($order['shipping_lines'] as $shipping_line) {
            $amount = $amount + $shipping_line['amount'];
        }
        foreach ($order['discount_lines'] as $discount_line) {
            $amount = $amount - $discount_line['amount'];
        }
        foreach ($order['tax_lines'] as $tax_line) {
            $amount = $amount + $tax_line['amount'];
        }
        if ($amount != $total) {
            $adjustment = $total - $amount;
            $order['tax_lines'][0]['amount'] =
                $order['tax_lines'][0]['amount'] + $adjustment;
            if (empty($order['tax_lines'][0]['description'])) {
                $order['tax_lines'][0]['description'] = 'Round Adjustment';
            }
        }
        return $order;
    }


    public static function getCardToken($info)
    {
        $cardToken = $info->getAdditionalInformation('card_token');
        if (!$cardToken)
            throw new \Magento\Framework\Validator\Exception(__('Error process your card info.'));
        return $cardToken;
    }
    public static function getCharge($amount, $token_id) {
        $charge = array(
            'payment_method' => array(
                'type'     => 'card',
                'token_id' => $token_id
            ),
            'amount' => $amount
        );
        return $charge;
    }
    public  function createWebhook() {
        $sandbox_mode = (boolean) ((integer) $this->getConfigData("sandbox_mode"));
        if ($sandbox_mode) {
            $privateKey = (string) $this->getConfigData("test_private_api_key");
        } else {
            $privateKey = (string) $this->getConfigData("live_private_api_key");
        }
        self::initializeConektaLibrary($privateKey);
        $url_webhook = (string) $this->getConfigData("conekta_webhook");
        if (empty($url_webhook)) {
            $url_webhook = \Conekta\Payments\Model\Source\Webhook::getUrl();
        }
        $events = ["events" => ["charge.paid"]];
        $error_message = null;
        try {
            $different = true;
            $webhooks = Webhook::where();
            foreach ($webhooks as $webhook) {
                if (strpos($webhook->webhook_url, $url_webhook) !== false) {
                    $different = false;
                }
            }
            if ($different) {
                if (!$sandbox_mode) {
                    $mode = array(
                        "production_enabled" => 1
                    );
                } else {
                    $mode = array(
                        "development_enabled" => 1
                    );
                }
                $webhook = Webhook::create(
                    array_merge(["url" => $url_webhook], $mode, $events)
                );
            } else {
                throw new \Magento\Framework\Validator\Exception(
                    __('Webhook was already registered in Conekta!<br>URL: ' . $url_webhook)
                );
            }
        } catch (Error $e) {
            $error_message = $e->getMessage();
            $this->_logger->error(
                __('[Conekta]: Webhook error, Message: ' . $error_message
                    . ' URL: ' . $url_webhook)
            );
            throw new \Magento\Framework\Validator\Exception(
                __('Can not register this webhook ' . $url_webhook . '<br>'
                    . 'Message: ' . (string) $error_message));
        }
    }


    /**
     * Conekta initializer
     * @throws \Magento\Framework\Validator\Exception
     */
    public static function initializeConektaLibrary($privateKey)
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resolver = $objectManager->get('Magento\Framework\Locale\Resolver');
            $lang = explode('_', $resolver->getLocale());

            if($lang[0] == 'en'){
                $locale = 'en';
            }elseif($lang[0] == 'es'){
                $locale = 'es';
            }else{
                $locale = 'en';
            }
            if (empty($privateKey)) {
                throw new \Magento\Framework\Validator\Exception(
                    __("Please check your conekta config.")
                );
            }
            \Conekta\Conekta::setApiKey($privateKey);
            \Conekta\Conekta::setApiVersion("2.0.0");
            \Conekta\Conekta::setPlugin("Magento 2");
            \Conekta\Conekta::setLocale($locale);
        }catch(\Exception $e){
            throw new \Magento\Framework\Validator\Exception(
                __($e->getMessage())
            );
        }
    }

    /**
     * OXXO Charge getter
     * @param $amount
     * @param $expiry_date
     * @return array
     */
    public static function getChargeOxxo($amount, $expiry_date)
    {
        $charge = array(
            'payment_method' => array(
                'type' => 'oxxo_cash',
                'expires_at' => $expiry_date
            ),
            'amount' => $amount
        );
        return $charge;
    }
    /**
     * SPEI Charge getter
     * @param $amount
     * @param $expiry_date
     * @return array
     */
    public static function getChargeSpei($amount, $expiry_date)
    {
        $charge = array(
            'payment_method' => array(
                'type' => 'spei',
                'expires_at' => $expiry_date
            ),
            'amount' => $amount
        );
        return $charge;
    }
    /**
     * Customer info getter
     * @param $order
     * @return array
     */
    public static function getCustomerInfo($order)
    {
        $billing = $order->getBillingAddress()->getData();
        $customer_info = [
            'name' => preg_replace(
                '!\s+!', ' ',
                $billing['firstname'] . ' '
                . $billing['middlename'] . ' '
                . $billing['lastname']
            ),
            'email' => $order->getCustomerEmail(),
            'phone' => $billing['telephone'],
            'metadata' => [
                'soft_validations' => true
            ]
        ];
        return $customer_info;
    }
    /**
     * Line Items getter
     * @param $order
     * @return array
     */
    public static function getLineItems($order)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $line_items = [];
        $order->getAllVisibleItems();
        $items = $order->getAllVisibleItems();
        foreach ($items as $itemId => $item) {
            if ($item->getProductType() == 'simple' && $item->getPrice() <= 0)
                break;
            $line_items[] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'unit_price' => intval(strval($item->getPrice()) * 100),
                'description' => strip_tags($objectManager
                    ->get('Magento\Catalog\Model\Product')
                    ->load($item->getProductId())->getDescription()),
                'quantity' => intval($item->getQtyOrdered()),
                'tags' => [
                    $item->getProductType()
                ]
            ];
        }
        return $line_items;
    }
    /**
     * Shipping contact getter
     * @param $order
     * @return array
     */
    public static function getShippingContact($order)
    {
        $shippingAddress = $order->getShippingAddress();
        $billing = $order->getBillingAddress()->getData();
        $shipping_contact = [];
        if ($shippingAddress) {
            $shippingData = $shippingAddress->getData();
            $shipping_contact = [
                'receiver' => preg_replace(
                    '!\s+!', ' ',
                    $billing['firstname'] . ' '
                    . $billing['middlename'] . ' '
                    . $billing['lastname']),
                'phone' => $billing['telephone'],
                'address' => [
                    'street1' => $shippingData['street'],
                    'city' => $shippingData['city'],
                    'state' => $shippingData['region'],
                    'country' => $shippingData['country_id'],
                    'postal_code' => $shippingData['postcode'],
                    'phone' => $shippingData['telephone'],
                    'email' => $order->getCustomerEmail()
                ]
            ];
        }
        return $shipping_contact;
    }
    /**
     * Shipping lines getter
     * @param $order
     * @return array
     */
    public static function getShippingLines($order)
    {
        $shipping_lines = [];
        if ($order->getShippingAmount() > 0) {
            $shipping_tax = $order->getShippingTaxAmount();
            $shipping_cost = $order->getShippingAmount() + $shipping_tax;
            $shipping_lines [] = [
                'amount' => intval(strval($shipping_cost) * 100),
                'method' => $order->getShippingMethod(),
                'carrier' => $order->getShippingDescription()
            ];
        } else {
            $shipping_lines [] = [
                'amount' => 0,
                'method' => $order->getShippingMethod(),
                'carrier' => $order->getShippingDescription()
            ];
        }
        return $shipping_lines;
    }
    /**
     * Discount lines getter
     * @param $order
     * @return array
     */
    public static function getDiscountLines($order)
    {
        $discount_lines = array();
        $totalDiscount = abs(intval(strval($order->getDiscountAmount()) * 100));
        $totalDiscountCoupons = 0;
        foreach ($order->getAllItems() as $item) {
            if (floatval($item->getDiscountAmount()) > 0.0) {
                $description = $order->getDiscountDescription();
                if (empty($description))
                    $description = "discount_code";
                $discount_line = array();
                $discount_line["code"] = $description;
                $discount_line["type"] = "coupon";
                $discount_line["amount"] = abs(intval(strval($order->getDiscountAmount()) * 100));
                $discount_lines =
                    array_merge($discount_lines, array($discount_line));
                $totalDiscountCoupons = $totalDiscountCoupons + $discount_line["amount"];
            }
        }
        if (floatval($totalDiscount) > 0.0 && $totalDiscount != $totalDiscountCoupons) {
            $discount_lines = array();
            $discount_line = array();
            $discount_line["code"] = "discount";
            $discount_line["type"] = "coupon";
            $discount_line["amount"] = $totalDiscount;
            $discount_lines =
                array_merge($discount_lines, array($discount_line));
        }
        return $discount_lines;
    }
    /**
     * Tax lines getter
     * @param $order
     * @return array
     */
    public static function getTaxLines($order)
    {
        $tax_lines = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getProductType() == 'simple' && $item->getPrice() <= 0)
                break;
            $tax_lines[] = [
                'description' => self::getTaxName($item),
                'amount' => intval(strval($item->getTaxAmount()) * 100)
            ];
        }
        return $tax_lines;
    }
    /**
     * Tax name getter
     * @param $item
     * @return string
     */
    public static function getTaxName($item)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $_product = $objectManager->get('Magento\Catalog\Model\Product')->load($item->getProductId());
        $tax_class_id = $_product->getTaxClassId();
        $tax_class = $objectManager->get('Magento\Tax\Model\ClassModel')->load($tax_class_id);
        $tax_class_name = $tax_class->getClassName();
        if (empty($tax_class_name))
            $tax_class_name = "tax";
        return $tax_class_name;
    }


}
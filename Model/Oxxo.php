<?php

namespace Conekta\Payments\Model;

use Conekta\Payments\Model\Offline;
use Magento\Sales\Model\Order;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;

/**
 * Pay In Oxxo Store payment method model
 */
class Oxxo extends Offline {
    const CODE = 'conekta_oxxo';
    protected $_code = self::CODE;
    
    public function __construct(
        Context $context, 
        Registry $registry, 
        ExtensionAttributesFactory $extensionFactory, 
        AttributeValueFactory $customAttributeFactory, 
        Data $paymentData, 
        ScopeConfigInterface $scopeConfig, 
        Logger $logger, 
        AbstractResource $resource = null, 
        AbstractDb $resourceCollection = null, 
        array $data = array()){
        
        parent::__construct(
            $context, 
            $registry, 
            $extensionFactory, 
            $customAttributeFactory, 
            $paymentData, 
            $scopeConfig, 
            $logger, 
            $resource, 
            $resourceCollection, 
            $data);
    }
    
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        if (NULL === $this->_privateKey) {
            throw new \Magento\Framework\Validator\Exception(__('Please check your Conekta config.'));
        }
        
        \Conekta\Conekta::setApiKey($this->_privateKey);
        \Conekta\Conekta::setApiVersion("1.0.0");
        \Conekta\Conekta::setLocale("es");
        
        $order = $payment->getOrder();
        $billing = $order->getBillingAddress()->getData();
        
        $items = $order->getAllVisibleItems();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $customer = $objectManager->get('Magento\Customer\Model\Session');

        $shipping_data = $order->getShippingAddress()->getData();
        $shipp = [];
        if (empty($shipping_data) !== true) {
            $shipp = [
                'price' => intval(((float) $order->getShippingAmount()) * 100),
                'service' => $order->getShippingMethod(),
                'carrier' => $order->getShippingDescription(),
                'address' => [
                    'street1' => $shipping_data['street'],
                    'city' => $shipping_data['city'],
                    'state' => $shipping_data['region'],
                    'country' => $shipping_data['country_id'],
                    'zip' => $shipping_data['postcode'],
                    'phone' => $shipping_data['telephone'],
                    'email' => $order->getCustomerEmail()
                    ]
                ];
        }
        
        $shipping_amount = null;
        $shipping_description = null;
        $shipping_method = null;

        if (empty($shipping_address) != true) {
            $shipping_amount = $shipping_address->getShippingAmount();
            $shipping_description = $shipping_address->getShippingDescription();
            $shipping_method = $shipping_address->getShippingMethod();
        }
        
        $line_items = [];
        
        foreach($items as $itemId => $item) {
            $line_items[] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'unit_price' => $item->getPrice(),
                'description' => strip_tags($objectManager->get('Magento\Catalog\Model\Product')->load($item->getProductId())->getDescription()),
                'quantity' => 1,
                'type' => $item->getProductType()
            ];
        }
        
        try {
            $total_amount = intval(((float) $amount) * 100);
            $chargeData = [
                'currency' => (ctype_lower($order->getStoreCurrencyCode())) ? strtoupper($order->getStoreCurrencyCode()) : strtolower($order->getStoreCurrencyCode()),
                'amount' => $total_amount,
                'cash'=> [
                    'type' => 'oxxo'
                ],
                'description' => 'Compra en Magento order #' . $order->getIncrementId(),
                'reference_id' => $order->getIncrementId(),
                'details' => [
                    'name' => preg_replace('!\s+!', ' ', $billing['firstname'] . ' ' . $billing['middlename'] . ' ' . $billing['lastname']),
                    'email' => $order->getCustomerEmail(),
                    'phone' => $billing['telephone'],
                    'billing_address' => [
                        'company_name' => $billing['company'],
                        'street1' => $billing['street'],
                        'city' => $billing['city'],
                        'state' => $billing['region'],
                        'country' => $billing['country_id'],
                        'zip' => $billing['postcode'],
                        'phone' => $billing['telephone'],
                        'email' => $order->getCustomerEmail()
                    ],
                    'line_items' => $line_items,
                    'shipment' => $shipp
                ],
                'coupon_code' => $order->getCouponCode(),
                'custom_fields' => [
                    'customer' => [
                        'website_id' => $customer->getWebsiteId(),
                        'entity_id' => NULL,
                        'entity_type_id' => $order->getEntityType(),
                        'attribute_set_id' => $customer->getAttributeSetId(),
                        'email' => $customer->getEmail(),
                        'group_id' => $customer->getGroupId(),
                        'store_id' => $customer->getStoreId(),
                        'created_at' => $customer->getCreatedAt(),
                        'updated_at' => $customer->getUpdatedAt(),
                        'is_active' => $order->getCustomerIsGuest(),
                        'disable_auto_group_change' => $customer->getDisableAutoGroupChange(),
                        'get_tax_vat' => $customer->getTaxvat(),
                        'created_in' => NULL,
                        'gender' => $customer->getGender() ,
                        'default_billing' => $customer->getDefaultBillingAddress(),
                        'default_shipping' => $customer->getDefaultShippingAddress(),
                        'dob' => $order->getCustomerDob() ,
                        'tax_class_id' => $customer->getTaxClassId()
                    ],
                    'discount_description' => $order->getDiscountDescription() ,
                    'discount_amount' => $order->getDiscountAmount() ,
                    'shipping_amount' => $shipping_amount,
                    'shipping_description' => $shipping_description,
                    'shipping_method' => $shipping_method
                ]
            ];
            
            try {
                $charge = \Conekta\Charge::create($chargeData);
            } catch (\Conekta\Error $e) {
                echo $e->getMessage();
            }
            
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus(Order::STATE_PENDING_PAYMENT);
            $order->setExtOrderId($charge->id);
            $order->setIsTransactionClosed(0);
            $order->save();
            
            $payment->setTransactionId($charge->id);
            
            $this->getInfoInstance()->setAdditionalInformation("offline_info", [
                "type" => $this->_code,
                "data" => [
                    "barcode" => $charge->payment_method->barcode,
                    "barcode_url" => $charge->payment_method->barcode_url,
                    "expires_at" => $charge->payment_method->expires_at
                ]
            ]);
        } catch(Exception $e) {
            $this->debugData(['exception' => $e->getMessage() ]);
            $this->_logger->error(__('[Conekta]: Payment capturing error.'));
            throw new \Magento\Framework\Validator\Exception(__('Payment capturing error.'));
        }
        
        $payment->setSkipOrderProcessing(true);
        return $this;
    }
}
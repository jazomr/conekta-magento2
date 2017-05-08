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
 * Pay in Spei payment method model
 */
class Spei extends Offline {
    const CODE = 'conekta_spei';
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
        self::initializeConektaLibrary();
        $info = $this->getInfoInstance();
        $order = $payment->getOrder();
        $items = $order->getAllVisibleItems();
        $shippingAddress = $order->getShippingAddress();
        $shippingAmount = ($shippingAddress ? $shippingAddress->getShippingAmount() : null);
        $shippingDescription = ($shippingAddress ? $shippingAddress->getShippingDescription() : null);
        $shippingMethod = ($shippingAddress ? $shippingAddress->getShippingMethod() : null);
        $totalAmount = intval(((float) $amount) * 100);

        $chargeData = [
            'bank'=> ['type' => 'spei'],
            'currency' => strtolower($order->getStoreCurrencyCode()),
            'amount' => $totalAmount,
            'description' => 'Compra en Magento order #' . $order->getIncrementId(),
            'reference_id' => $order->getIncrementId(),
            'details' => self::getDetails($order),
            'coupon_code' => $order->getCouponCode(),
            'custom_fields' => [
                'customer' => self::getCustomer($order),
                'discount_description' => $order->getDiscountDescription() ,
                'discount_amount' => $order->getDiscountAmount() ,
                'shipping_amount' => $shippingAmount,
                'shipping_description' => $shippingDescription,
                'shipping_method' => $shippingMethod
            ]
        ];
        
        try {       
            $charge = \Conekta\Charge::create($chargeData);
        } catch(\Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage() ]);
            $this->_logger->error(__('[Conekta]: Payment capturing error.'));
            throw new Magento\Framework\Validator\Exception(__('Payment capturing error.'));
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
                "clabe" => $charge->payment_method->clabe,
                "bank_name" => $charge->payment_method->bank,
                "expires_at" => $charge->payment_method->expires_at
            ]
        ]);
        $payment->setSkipOrderProcessing(true);

        return $this;
    }
/**
     * Conekta initializer
     * @throws \Magento\Framework\Validator\Exception
     */

    public function initializeConektaLibrary()
    {
        if (NULL === $this->_privateKey)
            throw new \Magento\Framework\Validator\Exception(__('Please check your Conekta config.'));

        \Conekta\Conekta::setApiKey($this->_privateKey);
        \Conekta\Conekta::setApiVersion("1.0.0");
        \Conekta\Conekta::setLocale("es");
    }

    /**
     * Shipping address getter
     * @return array
     */

    public function getShipment($order)
    {
        $shippingAddress = $order->getShippingAddress();
        $shippingAddressArray = [];
        if ($shippingAddress) {
            $shippingData = $shippingAddress->getData();
            $shippingAddressArray = [
                'price' => intval(((float) $order->getShippingAmount()) * 100),
                'service' => $order->getShippingMethod(),
                'carrier' => $order->getShippingDescription(),
                'address' => [
                    'street1' => $shippingData['street'],
                    'city' => $shippingData['city'],
                    'state' => $shippingData['region'],
                    'country' => $shippingData['country_id'],
                    'zip' => $shippingData['postcode'],
                    'phone' => $shippingData['telephone'],
                    'email' => $order->getCustomerEmail()
                    ]
                ];
        }

        return $shippingAddressArray;
    }

    /**
     * Line Items getter
     * @return array
     */

    public function getLineItems($order)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $lineItems = [];
        $order->getAllVisibleItems();
        $items = $order->getAllVisibleItems();
        foreach($items as $itemId => $item) {
            $lineItems[] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'unit_price' => $item->getPrice(),
                'description' => strip_tags($objectManager->get('Magento\Catalog\Model\Product')->load($item->getProductId())->getDescription()),
                'quantity' => 1,
                'type' => $item->getProductType()
            ];
        }

        return $lineItems;
    }

    /**
     * Details getter
     * @return array
     */

    public function getDetails($order)
    {
        $billing = $order->getBillingAddress()->getData();
        $details = [
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
            'line_items' => self::getLineItems($order),
            'shipment' => self::getShipment($order)
        ];

        return $details;
    }

    /**
     * Customer getter
     * @return array
     */

    public function getCustomer($order)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $customerSession = $objectManager->get('Magento\Customer\Model\Session');

        $customer = [
            'website_id' => $customerSession->getWebsiteId(),
            'entity_id' => NULL,
            'entity_type_id' => $order->getEntityType(),
            'attribute_set_id' => $customerSession->getAttributeSetId(),
            'email' => $customerSession->getEmail(),
            'group_id' => $customerSession->getGroupId(),
            'store_id' => $customerSession->getStoreId(),
            'created_at' => $customerSession->getCreatedAt(),
            'updated_at' => $customerSession->getUpdatedAt(),
            'is_active' => $order->getCustomerIsGuest(),
            'disable_auto_group_change' => $customerSession->getDisableAutoGroupChange(),
            'get_tax_vat' => $customerSession->getTaxvat(),
            'created_in' => NULL,
            'gender' => $customerSession->getGender() ,
            'default_billing' => $customerSession->getDefaultBillingAddress(),
            'default_shipping' => $customerSession->getDefaultShippingAddress(),
            'dob' => $order->getCustomerDob() ,
            'tax_class_id' => $customerSession->getTaxClassId()
        ];

        return $customer;
    }
}
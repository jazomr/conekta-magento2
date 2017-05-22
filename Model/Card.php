<?php

namespace Conekta\Payments\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Payment\Model\Method\Cc;

class Card extends Cc
{
    const CODE = 'conekta_card';
    
    protected $_code = self::CODE;
    protected $_isGateway                   = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = true;
    protected $_canRefund                   = true;
    protected $_canRefundInvoicePartial     = true;

    protected $_countryFactory;
    protected $_minAmount = null;
    protected $_maxAmount = null;
    protected $_supportedCurrencyCodes = ["USD", "MXN"];
    protected $_debugReplacePrivateDataKeys = ['number', 'exp_month', 'exp_year', 'cvc'];
    protected $_scopeConfig;

    protected $_isSandbox = true;
    protected $_privateKey = null;
    protected $_publicKey = null;
    protected $_monthlyInstallments;
    protected $_activeMonthlyInstallments;
    protected $_minimumAmountMonthlyInstallments;
    protected $_typesCards;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ModuleListInterface $moduleList,
        TimezoneInterface $localeDate,
        CountryFactory $countryFactory,
        array $data = array()
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            null,
            null,
            $data);
        
        if (!class_exists('\\Conekta\\Payments\\Model\\Config')){
            throw new \Magento\Framework\Validator\Exception(__("Class Conekta\\Payments\\Model\\Config not found."));
        }

        $this->_scopeConfig = $scopeConfig;
        $this->_countryFactory = $countryFactory;

        $this->_isSandbox = (boolean) $this->_getConektaConfig('sandbox_mode');

        $this->_typesCards = $this->getConfigData('cctypes');

        $this->_activeMonthlyInstallments = (boolean) ((integer) $this->getConfigData('active_monthly_installments'));

        if ($this->_activeMonthlyInstallments) {
            $this->_monthlyInstallments = $this->getConfigData('monthly_installments');
            $this->_minimumAmountMonthlyInstallments = (float) $this->getConfigData('minimum_amount_monthly_installments');
            if (empty($this->_minimumAmountMonthlyInstallments) || $this->_minimumAmountMonthlyInstallments <= 0) {
                $this->_minimumAmountMonthlyInstallments = 300;
            }
        }
        
        if ($this->_isSandbox) {
            $privateKey = (string) $this->_getConektaConfig('test_private_api_key');
            $publicKey = (string) $this->_getConektaConfig('test_public_api_key');
        } else {
            $privateKey = (string) $this->_getConektaConfig('live_private_api_key');
            $publicKey = (string) $this->_getConektaConfig('live_public_api_key');
        }
        
        if (!empty($privateKey)) {
            $this->_privateKey = $privateKey;
            unset($privateKey);
        } else {
            $this->_logger->error(__('Please set Conekta API keys in your admin.'));
        }

        if (!empty($publicKey)) {
            $this->_publicKey = $publicKey;
            unset($publicKey);
        } else {
            $this->_logger->error(__('Please set Conekta API keys in your admin.'));
        }

        $this->_minAmount = $this->getConfigData('min_order_total');
        $this->_maxAmount = $this->getConfigData('max_order_total');
    }


    /**
    * Assign corresponding data
    *
    * @param \Magento\Framework\DataObject|mixed $data
    * @return $this
    * @throws LocalizedException
    */
    public function assignData(\Magento\Framework\DataObject $data) {
        parent::assignData($data);
        $content = (array) $data->getData();
        $info = $this->getInfoInstance();
        if (key_exists('additional_data', $content)) {
            if (key_exists('card_token',$content['additional_data'])) {
                $additionalData = $content['additional_data'];

                $info->setAdditionalInformation('card_token', $additionalData['card_token']);
                $info->setCcType($additionalData['cc_type'])
                    ->setCcExpYear($additionalData['cc_exp_year'])
                    ->setCcExpMonth($additionalData['cc_exp_month']);
                // Additional data
                if (key_exists('monthly_installments', $additionalData))
                    $info->setAdditionalInformation('monthly_installments', $additionalData['monthly_installments']);
                // PCI assurance
                $info->setAdditionalInformation('cc_bin', $additionalData['cc_bin']);
                $info->setAdditionalInformation('cc_last_4', $additionalData['cc_last_4']);
            } else {
                $this->_logger->error(__('[Conekta]: Card token not found.'));
                throw new \Magento\Framework\Validator\Exception(__("Payment capturing error."));
            }

            if ($this->isActiveMonthlyInstallments()) {
                if (key_exists('monthly_installments', $content['additional_data'])) {
                    $info->setAdditionalInformation('monthly_installments', $content['additional_data']['monthly_installments']);
                }
            }
            
            return $this;
        }

        throw new \Magento\Framework\Validator\Exception(__("Payment capturing error."));
    }

    /**
     * Payment capturing
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
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
            'card' => self::getCardToken($info),
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

        $monthlyInstallments = $info->getAdditionalInformation('monthly_installments');

        try {
            
            if ($this->isActiveMonthlyInstallments() && intval($monthlyInstallments) > 1) {
                if ($this->_validateMonthlyInstallments($totalAmount, $monthlyInstallments)) {
                    $chargeData['monthly_installments'] = $monthlyInstallments;
                    $order->addStatusHistoryComment("Monthly installments select " . $chargeData['monthly_installments'] . ' months');
                    $order->save();
                } else {
                    $this->_logger->error(__('[Conekta]: installments: ' .  $monthlyInstallments . ' Amount: ' . $totalAmount));
                    throw new \Magento\Framework\Validator\Exception(__('Problem with monthly installments.'));
                }
            }

            $charge = \Conekta\Charge::create($chargeData);

            $payment->setTransactionId($charge->id)->setIsTransactionClosed(0);
        } catch(\Exception $e) {
            $this->debugData(['request' => $chargeData, 'exception' => $e->getMessage() ]);
            $this->_logger->error(__('[Conekta]: Payment capturing error. ' . $e->getMessage()));
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }

        return $this;
    }

    /**
     * Payment refund
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        self::initializeConektaLibrary();

        $transactionId = $payment->getParentTransactionId();

        try {
            $charge = \Conekta\Charge::find($transactionId);
            $charge->refund();
        } catch (\Exception $e) {
            $this->debugData(['transaction_id' => $transactionId, 'exception' => $e->getMessage()]);
            $this->_logger->error(__('Payment refunding error.'));
            throw new \Magento\Framework\Validator\Exception(__('Payment refunding error.'));
        }

        $payment->setTransactionId($transactionId . '-' . \Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND)
        ->setParentTransactionId($transactionId)
        ->setIsTransactionClosed(1)
        ->setShouldCloseParentTransaction(1);
        
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
     * Conekta token getter
     * @return string
     * @throws \Magento\Framework\Validator\Exception
     */

    public function getCardToken($info)
    {
        $cardToken = $info->getAdditionalInformation('card_token');

        if (!$cardToken)
            throw new \Magento\Framework\Validator\Exception(__('Error process your card info.'));

        return $cardToken;
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

    /**
     * Determine method availability based on quote amount and config data
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote && (
            $quote->getBaseGrandTotal() < $this->_minAmount
            || ($this->_maxAmount && $quote->getBaseGrandTotal() > $this->_maxAmount))
            ) {
            return false;
        }
        
        if (empty($this->_privateKey) || empty($this->_publicKey)) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * Availability for currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }

        return true;
    }

    /**
    * Return the publishable key
    *
    * @return string
    */
    public function getPubishableKey()
    {
        return $this->_publicKey;
    }

    public function getActiveTypeCards()
    {
        $activeTypes = explode(",", $this->_typesCards);
        $supportType = [
            "AE" => "American Express",
            "VI" => "Visa",
            "MC" => "Master Card"
        ];

        $out = [];

        foreach ($activeTypes AS $value) {
            $out[$value] = $supportType[$value];
        }

        return $out;
    }

    /**
    *
    *
    */
    public function isActiveMonthlyInstallments()
    {
        return $this->_activeMonthlyInstallments;
    }

    /**
    * Return Monthly Installments
    * @return array
    */
    public function getMonthlyInstallments()
    {
        $months = explode(',', $this->_monthlyInstallments);

        if (!in_array("1", $months)) {
            array_push($months, "1");
        }

        asort($months);

        return $months;
    }

    /**
    * Get Minimum MI
    * @return integer
    *
    */
    public function getMinimumAmountMonthlyInstallments()
    {
        return $this->_minimumAmountMonthlyInstallments;
    }

    /**
    * Validate MI
    * @return boolean
    *
    */
    private function _validateMonthlyInstallments($totalAmount, $installments)
    {
        if ($totalAmount >= $this->getMinimumAmountMonthlyInstallment()) {
            if (intval($installments) > 1)
                return ($totalAmount > $installments * 100);

        }

        return false;
    }
    
    /**
     * Conekta Config getter
     * @return Config
     */
    private function _getConektaConfig($field){
        $path = 'payment/' . \Conekta\Payments\Model\Config::CODE . '/' . $field;

        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, parent::getStore());
    }

    /**
     * Validate payment method information object
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function validate()
    {
        $info = $this->getInfoInstance();
        $errorMsg = false;
        $availableTypes = explode(',', $this->getConfigData('cctypes'));

        // PCI assurance
        $binNumber = $info->getAdditionalInformation('cc_bin');
        $last4 =  $info->getAdditionalInformation('cc_last_4');
        $ccNumber = $binNumber.$last4;

        // remove credit card number delimiters such as "-" and space
        $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
        
        $info->setCcNumber($ccNumber."******".$last4);

        $ccType = '';

        if (in_array($info->getCcType(), $availableTypes)) {
            if ($this->validateCcNumOther($binNumber)) {
                $ccTypeRegExpList = [
                    // Visa
                    'VI' => '/^4[0-9]{6}([0-9]{4})?$/',
                    // Master Card
                    'MC' => '/^5[1-5][0-9]{10}$/',
                    // American Express
                    'AE' => '/^3[47][0-9]{10}$/',
                ];

                // Validate only main brands.
                $ccNumAndTypeMatches = isset(
                    $ccTypeRegExpList[$info->getCcType()]
                ) && preg_match(
                    $ccTypeRegExpList[$info->getCcType()],
                    $ccNumber
                ) || !isset(
                    $ccTypeRegExpList[$info->getCcType()]
                );

                $ccType = $ccNumAndTypeMatches ? $info->getCcType() : 'OT';
            } else {
                $errorMsg = __('Custom Invalid Credit Card Number');
            }
        } else {
            $errorMsg = __('Custom This credit card type is not allowed for this payment method.');
        }

        if ($ccType != 'SS' && !$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            $errorMsg = __('Custom Please enter a valid credit card expiration date.'.$info->getCcType());
        }

        if ($errorMsg) {
            throw new \Magento\Framework\Exception\LocalizedException($errorMsg);
        }

        return $this;
    }

}

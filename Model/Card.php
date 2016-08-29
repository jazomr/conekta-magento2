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

        if (key_exists('additional_data', $content)) {
            if (key_exists('card_token',$content['additional_data'])) {
                $this->getInfoInstance()->setAdditionalInformation('card_token', $content['additional_data']['card_token']);
            } else {
                $this->_logger->error(__('[Conekta]: Card token not found.'));
                throw new \Magento\Framework\Validator\Exception(__("Payment capturing error."));
            }

            if ($this->isActiveMonthlyInstallments()) {
                if (key_exists('monthly_installments', $content['additional_data'])) {
                    $this->getInfoInstance()->setAdditionalInformation('monthly_installments', $content['additional_data']['monthly_installments']);
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
        if (!$this->getInfoInstance()->getAdditionalInformation('card_token')) {
            throw new \Magento\Framework\Validator\Exception(__('Error process your card info.'));
        }

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

        $shipp = [];
        if (empty($shipping_data) !== true) {
            $shipp = [
                'price' => intval(((float) $order->getShippingAmount()) * 100) ,
                'service' => $order->getShippingMethod() ,
                'carrier' => $order->getShippingDescription() ,
                'address' => [
                    'street1' => $shipping_data['street'],
                    'city' => $shipping_data['city'],
                    'state' => $shipping_data['region'],
                    'country' => $shipping_data['country_id'],
                    'zip' => $shipping_data['postcode'],
                    'phone' => $shipping_data['telephone'],
                    'email' => $email
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
                'card' => $this->getInfoInstance()->getAdditionalInformation('card_token'),
                'currency' => strtolower($order->getStoreCurrencyCode()),
                'amount' => $total_amount,
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

            if ($this->isActiveMonthlyInstallments()){
                $monthly_installments = (integer) $this->getInfoInstance()->getAdditionalInformation('monthly_installments');
                if (!$this->_validateMonthlyInstallments($total_amount, $monthly_installments)) {
                    $this->_logger->error(__('[Conekta]: installments: ' .  $monthly_installments . ' Amount: ' . $total_amount));
                    throw new \Magento\Framework\Validator\Exception(__('Problem with monthly installments.'));
                }

                if ($monthly_installments > 1) {
                    $chargeData['monthly_installments'] = $monthly_installments;
                    $order->addStatusHistoryComment("Monthly installments select " . $chargeData['monthly_installments'] . ' months');
                    $order->save();
                }
            }
            
            $charge = \Conekta\Charge::create($chargeData);

            $payment->setTransactionId($charge->id)->setIsTransactionClosed(0);
        } catch(Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage() ]);
            $this->_logger->error(__('[Conekta]: Payment capturing error.'));
            throw new Magento\Framework\Validator\Exception(__('Payment capturing error.'));
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
        if (NULL === $this->_privateKey) {
            throw new \Magento\Framework\Validator\Exception(__('Please check your Conekta config.'));
        }

        \Conekta\Conekta::setApiKey($this->_privateKey);
        \Conekta\Conekta::setApiVersion("1.0.0");
        \Conekta\Conekta::setLocale("es");

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
    *
    *
    */
    public function getMinimumAmountMonthlyInstallments(){
        return $this->_minimumAmountMonthlyInstallments;
    }

    /**
    *
    *
    */
    private function _validateMonthlyInstallments($totalOrder, $installments){
        if ($totalOrder >= $this->getMinimumAmountMonthlyInstallment()){
            switch ($installments) {
                case 6: case 9: case 12:
                    if ($totalOrder > 400) {
                        return true;
                    }
                    break;
                case 3:
                    if ($totalOrder > 300) {
                        return true;
                    }
                    break;
            }
            return false;
        }
        return false;
    }
    
    /**
     * 
     */
    private function _getConektaConfig($field){
        $path = 'payment/' . \Conekta\Payments\Model\Config::CODE . '/' . $field;
        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, parent::getStore());
    }
}
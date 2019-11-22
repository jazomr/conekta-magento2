<?php

namespace Conekta\Payments\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * @category  Payments
 * @package   Conekta_Payments
 * @copyright Copyright (c) 2012 Magestore (http://www.magestore.com/)
 * @license   http://www.magestore.com/license-agreement.html
 *
 */
class Index extends Action
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $_resultFactory;

    /*
     * var \Magento\Sales\Model\Order\PaymentFactory
    */
    protected $_paymentFactory;

    /**
     * @var Magento\Sales\Model\OrderRepository
     */
    protected $_orderRepository;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * @var \Magento\Sales\Model\Order\Payment
     */
    protected $_payment;

    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * Conekta Constructor
     *
     * @param \Magento\Framework\App\Action\Context     $context
     * @param \Magento\Sales\Model\OrderFactory         $orderFactory
     * @param \Magento\Sales\Model\Order\PaymentFactory $paymentFactory
     * @param \Psr\Log\LoggerInterface                  $logger
     * @param \Magento\Sales\Model\OrderRepository      $orderRepository
     * @param ScopeConfigInterface                      $scopeConfig
     * @param StoreManagerInterface                     $storeManager
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Model\Order\PaymentFactory $paymentFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->_orderRepository = $orderRepository;
        $this->_resultFactory = $context->getResultFactory();
        $this->_logger = $logger;
        $this->_orderFactory = $orderFactory;
        $this->_paymentFactory =  $paymentFactory;
        $this->_scopeConfig = $scopeConfig;
        $this->_storeManager = $storeManager;
        parent::__construct($context);
    }

    /**
     * Executes the controller action
     *
     * @return ResultFactory The http json response
     */
    public function execute()
    {
        $body = @file_get_contents('php://input');
        $event = json_decode($body);

        if (isset($_SERVER['HTTP_DIGEST'])) {
            $authenticated = $this->_authenticateEvent($body, $_SERVER['HTTP_DIGEST']);
            if (!$authenticated) {
                return $this->_processErrorResponse();
            }
        }


        if (!isset($event->data->object)) {
            $this->_logger->critical("The event has no data object");
            return $this->_processErrorResponse();
        }

        if ($event->type !== "order.paid") {
            return $this->_processResponse();
        }

        $charge = $event->data->object;

        try {
            $this->_getOrderFromCharge($charge);
            $this->registerPaymentCapture($charge);
            return $this->_processResponse();
        } catch (\Exception $e) {
            $this->_logger->critical(
                'Error processing webhook notification',
                ['exception' => $e]
            );
        }
    }

    /**
     * Process completed payment
     *
     * @param bool $charge
     *
     * @return void
     * @throws LocalizedException
     */
    protected function registerPaymentCapture($charge)
    {
        if (!$this->_order->canInvoice()) {
            $this->_logger->info(__('The orden %1 can not be invoiced', $this->_order->getIncrementId()));
            return false;
        }
        $singleCharge = $charge->charges->data[0];

        $this->_order->setState(Order::STATE_PROCESSING);
        $this->_payment->setIsTransactionApproved(true);
        $this->_payment->setCurrencyCode($singleCharge->currency);
        $this->_payment->registerCaptureNotification($singleCharge->amount / 100, true);
        $this->_orderRepository->save($this->_order);
    }

    /**
     * Returns Magento Order from chearge
     *
     * @param Array $charge The conekta charge object
     *
     * @return Order         Magento Order
     */
    private function _getOrderFromCharge($charge)
    {
        if (isset($charge->metadata) && isset($charge->metadata->checkout_id)) {
            $this->_order = $this->_orderFactory->create();
            $this->_order->loadByIncrementId($charge->metadata->checkout_id);
            $this->_payment = $this->_order->getPayment();
            return true;
        }
        $this->_payment = $this->_paymentFactory->create();
        $this->_payment->load($charge->id, OrderPaymentInterface::LAST_TRANS_ID);
        $this->_order = $this->_payment->getOrder();
    }

    /**
     * Processes Response and returns 200
     *
     * @return ResultFactory The http json response
     */
    private function _processResponse()
    {
        $resultPage = $this->_resultFactory
            ->create(ResultFactory::TYPE_JSON)
            ->setData([])
            ->setHttpResponseCode(200);
        return $resultPage;
    }

    /**
     * Process Error Response
     *
     * @return ResultFactory The http json error response
     */
    private function _processErrorResponse()
    {
        $resultPage = $this->_resultFactory
            ->create(ResultFactory::TYPE_JSON)
            ->setData([])
            ->setHttpResponseCode(404);
        return $resultPage;
    }

    /**
     * Checks if event is authenticated
     *
     * @param string $body   body of the request
     * @param string $digest digest autenticated header
     *
     * @return [type]         [description]
     */
    private function _authenticateEvent($body, $digest)
    {
        try {
            $private_key_string = $this->_getPrivateKey();
            if (!empty($private_key_string) && !empty($body)) {
                if (!empty($digest)) {
                    $private_key = openssl_pkey_get_private($private_key_string);
                    $encrypted_message = base64_decode($digest);
                    $sha256_message = "";
                    $bool = openssl_private_decrypt($encrypted_message, $sha256_message, $private_key);
                    if (hash("sha256", $body) == $sha256_message) {
                        return true;
                    }
                    $this->_logger->critical('Event not authenticatedn');
                    return false;
                } else {
                    $this->_logger->critical('Empty digest');
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Get Private key from config
     *
     * @return string privatekey
     */
    private function _getPrivateKey()
    {
        $isSandbox = (boolean)((integer)$this->_getConektaConfig('sandbox_mode'));
        if ($isSandbox) {
            return $this->_getConektaConfig('live_signature_key');
        }

        return $this->_getConektaConfig('test_signature_key');
    }

    /**
     * Get Conekta Config
     *
     * @param string $field Field to fetch config
     *
     * @return string        The fetched configuration
     */
    private function _getConektaConfig($field)
    {
        $store = $this->_storeManager->getStore();
        $path = 'payment/' . \Conekta\Payments\Model\Config::CODE . '/' . $field;
        return $this->_scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store);
    }
}

<?php

namespace Conekta\Payments\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;

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
     * @param \Magento\Framework\App\Action\Context     $context
     * @param \Magento\Sales\Model\OrderFactory         $orderFactory
     * @param \Magento\Sales\Model\Order\PaymentFactory $paymentFactory
     * @param \Psr\Log\LoggerInterface                  $logger
     * @param Magento\Sales\Model\OrderRepository       $orderRepository
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
    public function execute()
    {
        $body = @file_get_contents('php://input');
        $event = json_decode($body);

        if (isset($_SERVER['HTTP_DIGEST'])) {
            $authenticated = $this->authenticateEvent($body, $_SERVER['HTTP_DIGEST']);
            if (!$authenticated) {
                return $this->processErrorResponse();
            }
        }


        if (!isset($event->data->object)) {
            $this->_logger->critical("The event has no data object");
            return $this->processErrorResponse();
        }

        if ($event->type !== "order.paid") {
            return $this->processResponse();
        }

        $charge = $event->data->object;

        try {
            if (isset($charge->metadata)) {
                $this->_order = $this->_orderFactory->create();
                $this->_order->loadByIncrementId($charge->metadata->checkout_id);
                $this->_payment = $this->_order->getPayment();
            } else {
                $this->_payment = $this->_paymentFactory->create();
                $this->_payment->load($charge->id, OrderPaymentInterface::LAST_TRANS_ID);
                $this->_order = $this->_payment->getOrder();
            }

            $this->_registerPaymentCapture($charge);
            return $this->processResponse();
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
     * @param bool $skipFraudDetection
     * @return void
     * @throws LocalizedException
     */
    protected function _registerPaymentCapture($charge)
    {
        if (!$this->_order->canInvoice()) {
            $this->_logger->info(__('The orden %1 can not be invoiced', $this->_order->getIncrementId()));
            return false;
        }
        $single_charge = $charge->charges->data[0];

        $this->_order->setState(Order::STATE_PROCESSING);
        $this->_payment->setIsTransactionApproved(true);
        $this->_payment->setCurrencyCode($single_charge->currency);
        $this->_payment->registerCaptureNotification($single_charge->amount / 100, true);
        $this->_orderRepository->save($this->_order);
    }

    private function processResponse()
    {
        $resultPage = $this->_resultFactory
            ->create(ResultFactory::TYPE_JSON)
            ->setData([])
            ->setHttpResponseCode(200);
        return $resultPage;
    }

    private function processErrorResponse()
    {
        $resultPage = $this->_resultFactory
            ->create(ResultFactory::TYPE_JSON)
            ->setData([])
            ->setHttpResponseCode(404);
        return $resultPage;
    }

    private function authenticateEvent($body, $digest)
    {
        try {
            $private_key_string = $this->getPrivateKey();
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

    private function getPrivateKey()
    {
        $isSandbox = (boolean)((integer)$this->_getConektaConfig('sandbox_mode'));
        if ($isSandbox) {
            $privateKey = $this->_getConektaConfig('live_signature_key');
        } else {
            $privateKey = $this->_getConektaConfig('test_signature_key');
        }
        return $privateKey;
    }

    private function _getConektaConfig($field)
    {
        $store = $this->_storeManager->getStore();
        $path = 'payment/' . \Conekta\Payments\Model\Config::CODE . '/' . $field;
        return $this->_scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store);
    }
}

<?php

namespace Conekta\Payments\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Controller\ResultFactory;

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
     * @param \Magento\Framework\App\Action\Context     $context
     * @param \Magento\Sales\Model\OrderFactory         $orderFactory
     * @param \Magento\Sales\Model\Order\PaymentFactory $paymentFactory
     * @param \Psr\Log\LoggerInterface                  $logger
     * @param Magento\Sales\Model\OrderRepository $orderRepository
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Model\Order\PaymentFactory $paymentFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\OrderRepository $orderRepository
    ) {
        $this->_orderRepository = $orderRepository;
        $this->_resultFactory = $context->getResultFactory();
        $this->_logger = $logger;
        $this->_orderFactory = $orderFactory;
        $this->_paymentFactory =  $paymentFactory;
        parent::__construct($context);
    }
    public function execute()
    {
        $body = @file_get_contents('php://input');
        $event = json_decode($body);

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
        $this->_orderRepository->save($order);
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
}

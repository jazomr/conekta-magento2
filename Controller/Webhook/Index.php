<?php

namespace Conekta\Payments\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Sales\Model\Order;
class Index extends Action
{
    protected $logger;
    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    public function execute() {
        $body = @file_get_contents('php://input');
        $event = json_decode($body);
        
        $charge = $event->data->object;
        $order = $this->_objectManager->create('Magento\Sales\Model\Order');
        
        if ($charge->status === "paid"){
            try{ 
                $order->loadByIncrementId($charge->reference_id);
                $order->setSate(Order::STATE_PROCESSING);
                $order->setStatus(Order::STATE_PROCESSING);
                
                $order->addStatusHistoryComment("Payment received successfully")
                        ->setIsCustomerNotified(true);
                
                $order->save();
                
                header('HTTP/1.1 200 OK');
                return;
            } catch (\Exception $e) {
                $this->_logger->log(100, json_encode($e->getMessage());
            }
        }
    }

}

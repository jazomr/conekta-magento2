<?php

namespace Conekta\Payments\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Sales\Model\Order;

class Index extends Action
{
    public function execute() {
        $body = @file_get_contents('php://input');
        $event = json_decode($body);
        
        $charge = $event->object;
        $order = $this->_objectManager->create('Magento\Sales\Model\Order');
        
        if ($charge->status === "paid"){
            try{ 
                $order->loadByIncrementId($charge->reference_id);
                $order->setSate(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
                
                $order->addStatusHistoryComment("Payment received successfully")
                        ->setIsCustomerNotified(true);
                
                $order->save();
                
                header('HTTP/1.1 200 OK');
                exit;
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }
    }

}

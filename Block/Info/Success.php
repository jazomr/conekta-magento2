<?php

namespace Conekta\Payments\Block\Info;

use Magento\Checkout\Block\Onepage\Success as CompleteCheckout;

class Success extends CompleteCheckout
{
    public function getInstructions(){
        return $this->getOrder()->getPayment()->getMethodInstance()->getInstructions();
    }
    
    public function getMethod(){
        return $this->getOrder()->getPayment()->getMethod();
    }
    
    public function getOfflineInfo(){
        return $this->getOrder()
             ->getPayment()
             ->getMethodInstance()
             ->getOfflineInfo();
    }
    
    public function getOrder(){
        return $this->_checkoutSession->getLastRealOrder();
    }
}

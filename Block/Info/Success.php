<?php

namespace Conekta\Payments\Block\Info;

use Magento\Checkout\Block\Onepage\Success as CompleteCheckout;
use Magento\Store\Model\ScopeInterface;

class Success extends CompleteCheckout
{
    public function getInstructions(){
        $path = 'payment/' . \Conekta\Payments\Model\Oxxo::CODE . '/instructions';
        return $this->_scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }
    
    public function getMethod(){
        return $this->getOrder()->getPayment()->getMethod();
    }
    
    public function getBarcode(){
        return (object) $this->getOrder()
             ->getPayment()
             ->getMethodInstance()
             ->getInfoInstance()
             ->getAdditionalInformation("oxxo_barcode_info");
    }
    
    public function getOrder(){
        return $this->_checkoutSession->getLastRealOrder();
    }
}

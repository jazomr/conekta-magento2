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
             ->getInfoInstance()
             ->getAdditionalInformation("offline_info");
    }
    
    public function getOrder(){
        return $this->_checkoutSession->getLastRealOrder();
    }

    public function getAccountOwner()
    {
        return $this->_scopeConfig->getValue(
            'payment/conekta_spei/account_owner',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}

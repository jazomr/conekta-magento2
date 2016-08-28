<?php

namespace Conekta\Payments\Block\Info;
use Magento\Payment\Block\Info;

class Custom extends Info {
    protected $_template = 'Conekta_Payments::info/custom.phtml';
    
    public function getBarcode(){
        return (object) $this->getMethod()
                             ->getInfoInstance()
                             ->getAdditionalInformation("oxxo_barcode_info");
    }
}


<?php

namespace Conekta\Payments\Plugin;

use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderInterface;

class AuthorizeCommandPlugin
{
    public function aroundExecute(
        AuthorizeCommand $subject,
        \Closure $proceed,
        OrderPaymentInterface $payment,
        $amount,
        OrderInterface $order
    ) {
        $result = $proceed($payment, $amount, $order);
        $orderStatus = $payment->getMethodInstance()->getConfigData('order_status');
        if ($orderStatus && $order->getState() !== $orderStatus) {
            $order->setStatus($orderStatus);
            $order->setState($orderStatus);
        }

        return $result;
    }
}

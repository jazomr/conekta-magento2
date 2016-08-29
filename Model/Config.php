<?php

namespace Conekta\Payments\Model;

use Conekta\Conekta;
use Conekta\Webhook;
use Conekta\Error;

class Config extends \Magento\Payment\Model\Method\AbstractMethod {

    const CODE = 'conekta_config';

    protected $_code = self::CODE;

    public function createWebhook() {
        $sandbox_mode = (boolean) ((integer) $this->getConfigData("sandbox_mode"));

        if ($sandbox_mode) {
            $privateKey = (string) $this->getConfigData("test_private_api_key");
        } else {
            $privateKey = (string) $this->getConfigData("live_private_api_key");
        }

        if (empty($privateKey)) {
            throw new \Magento\Framework\Validator\Exception(__("Please check your conekta config."));
        }

        Conekta::setApiKey($privateKey);
        Conekta::setApiVersion("1.0.0");
        Conekta::setLocale("es");

        $url_webhook = (string) $this->getConfigData("conekta_webhook");

        if (empty($url_webhook)) {
            $url = new \Conekta\Payments\Model\Source\Webhook;
            $url_webhook = $url->getUrl();
            unset($url);
        }

        $events = ["events" => ["charge.paid"]];
        $error_message = null;

        try {
            $different = true;
            $webhooks = Webhook::where();

            foreach ($webhooks as $webhook) {
                if (strpos($webhook->webhook_url, $url_webhook) !== false) {
                    $different = false;
                }
            }

            if ($different) {
                if (!$sandbox_mode) {
                    $mode = array(
                        "production_enabled" => 1
                    );
                } else {
                    $mode = array(
                        "development_enabled" => 1
                    );
                }

                $webhook = Webhook::create(array_merge(["url" => $url_webhook], $mode, $events));
            }
        } catch (Error $e) {
            $error_message = $e->getMessage();
            $this->_logger->error(__('[Conekta]: Webhook error, Message: ' . $error_message . ' URL: ' . $url_webhook));
            throw new \Magento\Framework\Validator\Exception(__('Can not register this webhook ' . $url_webhook . '<br>' . 'Message: ' . (string) $error_message));
        }
    }

}

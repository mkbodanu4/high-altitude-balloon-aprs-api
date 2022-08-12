<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "viber_api.class.php";

$webhook_url = trim(getenv('APP_URL'), "/") . "/viber_webhook.php";

$Viber_API = new Viber_API(getenv('VIBER_API_KEY'));
$set_webhook = $Viber_API->post('set_webhook', array(
    'url' => $webhook_url,
    'event_types' => array(
        'unsubscribed',
        'conversation_started'
    )
));
if (isset($set_webhook->status) && $set_webhook->status === 0) {
    //echo $set_webhook->status_message;
    http_response_code(200);
    echo "Ok";
} else {
    //var_dump($set_webhook);
    http_response_code(500);
    echo "Not ok";
}

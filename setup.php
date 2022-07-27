<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "telegram_api.class.php";

$webhook_url = trim(getenv('APP_URL'), "/") . "/webhook.php";
$secret_token = getenv('TELEGRAM_SECRET_TOKEN');

$Telegram_API = new Telegram_API(getenv('TELEGRAM_API_KEY'));
$get_webhook = $Telegram_API->get('getWebhookInfo');
if($get_webhook->ok) {
    $set_webhook = $Telegram_API->get('setWebhook', array(
        'url' => $webhook_url,
        'secret_token' => $secret_token
    ));
    if($set_webhook->ok && $set_webhook->result) {
        //echo $set_webhook->description;
        http_response_code(200);
        echo "Ok";
    } else {
        //var_dump($set_webhook);
        http_response_code(500);
        echo "Not ok";
    }
} else {
    //var_dump($get_webhook);
    http_response_code(401);
    echo "Error";
}

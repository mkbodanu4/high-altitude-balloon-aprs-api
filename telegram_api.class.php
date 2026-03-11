<?php

class Telegram_API
{
    private $base = "https://api.telegram.org/bot";

    private $api_key = "";

    private $http_code = NULL;

    public function __construct($api_key, $base = NULL)
    {
        $this->api_key = $api_key;
        if ($base) $this->base = $base;
    }

    public function get_url($method, $params = array()): string
    {
        return $this->base . $this->api_key . '/' . $method . (count($params) > 0 ? "?" . http_build_query($params) : "");
    }

    public function get($method, $params = array(), $headers = array())
    {
        return $this->get_json($this->get_url($method, $params), NULL, $headers);
    }

    public function sendMessage($chat_id, $text, $message_thread_id = NULL, $disable_web_page_preview = FALSE, $reply_markup = NULL)
    {
        $payload = array(
            'chat_id' => $chat_id,
            'text' => $text,
            'disable_web_page_preview' => $disable_web_page_preview
        );

        if ($message_thread_id) {
            $payload['message_thread_id'] = $message_thread_id;
            $payload['chat_id'] = $chat_id . "_" . $message_thread_id;
        }

        if ($reply_markup !== NULL) {
            $payload['reply_markup'] = $reply_markup;
        }

        return $this->get_json($this->get_url("sendMessage"), json_encode($payload), array(
            "Content-type:application/json"
        ), "POST");
    }

    public function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = array())
    {
        $payload = array(
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'reply_markup' => $reply_markup
        );
        return $this->get_json($this->get_url("editMessageReplyMarkup"), json_encode($payload), array(
            "Content-type:application/json"
        ), "POST");
    }

    public function answerCallbackQuery($callback_query_id, $text = NULL)
    {
        $payload = array('callback_query_id' => $callback_query_id);
        if ($text !== NULL) {
            $payload['text'] = $text;
        }
        return $this->get_json($this->get_url("answerCallbackQuery"), json_encode($payload), array(
            "Content-type:application/json"
        ), "POST");
    }

    public function sendLocation($chat_id, $latitude, $longitude, $message_thread_id = NULL)
    {
        $payload = array(
            'chat_id' => $chat_id,
            'longitude' => $longitude,
            'latitude' => $latitude
        );

        if ($message_thread_id) {
            $payload['message_thread_id'] = $message_thread_id;
            $payload['chat_id'] = $chat_id . "_" . $message_thread_id;
        }

        return $this->get_json($this->get_url("sendLocation"), json_encode($payload), array(
            "Content-type:application/json"
        ), "POST");
    }

    public function get_http_code()
    {
        return $this->http_code;
    }

    private function get_json($url, $post = NULL, $headers = array(), $method = NULL)
    {
        $content = $this->get_content($url, $post, $headers, $method);

        return json_decode($content);
    }

    private function get_content($url, $post = NULL, $headers = array(), $method = NULL)
    {
        $handler = curl_init();
        curl_setopt($handler, CURLOPT_URL, $url);
        curl_setopt($handler, CURLOPT_HEADER, FALSE);
        curl_setopt($handler, CURLOPT_HTTPHEADER, $headers);
        if ($post || $method !== NULL) {
            if ($method !== NULL) {
                curl_setopt($handler, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($handler, CURLOPT_POST, FALSE);
            } else {
                curl_setopt($handler, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($handler, CURLOPT_POST, TRUE);
            }
            curl_setopt($handler, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($handler, CURLINFO_HEADER_OUT, FALSE);
        curl_setopt($handler, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($handler, CURLOPT_MAXREDIRS, 10);
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($handler, CURLOPT_TIMEOUT, 30);
        curl_setopt($handler, CURLOPT_USERAGENT, "PHP/" . phpversion());
        $result = curl_exec($handler);
        $this->http_code = curl_getinfo($handler, CURLINFO_HTTP_CODE);
        curl_close($handler);


        return $result;
    }
}

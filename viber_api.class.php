<?php

class Viber_API
{
    private $base = "https://chatapi.viber.com/pa/";

    private $api_key = "";

    private $http_code = NULL;

    public function __construct($api_key, $base = NULL)
    {
        $this->api_key = $api_key;
        if ($base) $this->base = $base;
    }

    public function get_url($method, $params = array()): string
    {
        return $this->base . $method . (count($params) > 0 ? "?" . http_build_query($params) : "");
    }

    public function get($method, $params = array(), $headers = array())
    {
        return $this->get_json($this->get_url($method, $params), NULL, array_merge($headers, array(
            "Content-type: application/json",
            "X-Viber-Auth-Token: " . $this->api_key
        )));
    }

    public function post($method, $params = array(), $headers = array())
    {
        return $this->get_json($this->get_url($method), json_encode($params), array_merge($headers, array(
            "Content-type: application/json",
            "X-Viber-Auth-Token: " . $this->api_key
        )), "POST");
    }

    public function send_message($receiver, $sender_name, $sender_avatar, $text)
    {
        return $this->post("send_message", array(
            'receiver' => $receiver,
            'sender' => array(
                'name' => $sender_name,
                'avatar' => $sender_avatar
            ),
            'type' => 'text',
            'text' => $text,
        ));
    }

    public function send_location($receiver, $sender_name, $sender_avatar, $latitude, $longitude)
    {
        return $this->post("send_message", array(
            'receiver' => $receiver,
            'sender' => array(
                'name' => $sender_name,
                'avatar' => $sender_avatar
            ),
            'type' => 'location',
            'location' => array(
                'lat' => $latitude,
                'lon' => $longitude
            )
        ));
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
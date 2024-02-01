<?php

function isCaptchaValid($token)
{
    if (!$token) {
        return false;
    }
    $secret = trim(file_get_contents(__DIR__ . '/../../config/recaptcha_secret'));
    try {
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = ['secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR']];
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        return json_decode($result)->success;
    } catch (Exception $e) {
        return false;
    }
}
<?php

$url = 'http://localhost/codeindex/products/public/paypal_listener';

$raw_post_data = "";

$raw_post_array = explode('&', $raw_post_data);
$req = array();

foreach ($raw_post_array as $keyval) {
    $keyval = explode("=", $keyval);
    if (count($keyval) == 2) {
        $req[$keyval[0]] = urldecode($keyval[1]);
    }
}

/* $req = array(
    'txn_type' => 'web_accept',
    'txn_id' => '123333',
    'custom' => '9',
); */

var_dump($req);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);

curl_setopt($ch, CURLOPT_SSLVERSION, 6);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . "/cacert.pem");

$response = curl_exec($ch);
if (!($response)) {
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    curl_close($ch);
    throw new \Exception("cURL error: [$errno] $errstr");
}

$info = curl_getinfo($ch);
$httpCode = $info['http_code'];
if ($httpCode != 200) {
    throw new \Exception("responded with http code $httpCode");
}

curl_close($ch);

printf("El resultado es: \n");
var_dump($response);

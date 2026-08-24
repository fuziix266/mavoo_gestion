<?php
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

require 'c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\vendor\autoload.php';

$client = new Client([
    'base_uri' => 'http://localhost/mavoo_gestion/gestion_laminas/',
    'cookies' => true,
    'http_errors' => false
]);

$response = $client->request('POST', 'login', [
    'form_params' => [
        'email' => 'admin@admin.cl',
        'password' => 'admin123'
    ]
]);

$response = $client->request('GET', 'ligas/padel/sedes');
echo "STATUS CODE: " . $response->getStatusCode() . "\n";
echo substr($response->getBody()->getContents(), 0, 1000);

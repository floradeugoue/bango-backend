<?php
$ch = curl_init('http://127.0.0.1:8000/api/auth/reset-password/request');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => 'kamenideugoue22@gmail.com']));
$response = curl_exec($ch);
curl_close($ch);
echo $response;

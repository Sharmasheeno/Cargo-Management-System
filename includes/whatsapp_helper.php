<?php
// includes/whatsapp_helper.php

require_once __DIR__ . '/../config/greenapi_config.php';

function cleanWhatsAppPhone($phone)
{
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);

    if ($phone === '') {
        return '';
    }

    // Somalia: 061/062/063/064/065/068 -> 25261...
    if (substr($phone, 0, 1) === '0') {
        $phone = '252' . substr($phone, 1);
    }

    // Haddii number-ku yahay 61xxxxxxx, 68xxxxxxx iwm
    if (strlen($phone) === 9 && substr($phone, 0, 1) === '6') {
        $phone = '252' . $phone;
    }

    // Haddii +252 horey u ahaa, clean ayaa ka dhigtay 252...
    return $phone;
}

function sendWhatsAppMessage($phone, $message)
{
    $phone = cleanWhatsAppPhone($phone);

    if (empty($phone)) {
        return [
            'success' => false,
            'message' => 'Phone number waa madhan.'
        ];
    }

    if (empty($message)) {
        return [
            'success' => false,
            'message' => 'Message waa madhan.'
        ];
    }

    $chatId = $phone . '@c.us';

    $url = rtrim(GREEN_API_URL, '/') .
        '/waInstance' . GREEN_API_ID .
        '/sendMessage/' . GREEN_API_TOKEN;

    $payload = [
        'chatId' => $chatId,
        'message' => $message
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'message' => 'Curl Error: ' . $curlError
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'message' => 'WhatsApp message waa la diray.',
            'response' => $decoded
        ];
    }

    return [
        'success' => false,
        'message' => 'GreenAPI Error',
        'http_code' => $httpCode,
        'response' => $decoded ?: $response
    ];
}

function sendCustomerWhatsApp($customer, $message)
{
    $phone = $customer['phone'] 
        ?? $customer['phone_number'] 
        ?? $customer['mobile'] 
        ?? '';

    return sendWhatsAppMessage($phone, $message);
}
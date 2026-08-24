<?php
// includes/MessagingService.php

class MessagingService {
    private $pdo;
    private $settings = [];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    private function loadSettings() {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE tenant_id IS NULL");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            error_log("MessagingService settings error: " . $e->getMessage());
        }
    }

    public function sendWhatsApp($phone, $message) {
        if (($this->settings['whatsapp_enabled'] ?? '0') !== '1') {
            return ['success' => false, 'message' => 'WhatsApp API is disabled in settings.'];
        }

        $provider = $this->settings['whatsapp_provider'] ?? 'ultramsg';
        $token = $this->settings['whatsapp_token'] ?? '';
        $instance_id = $this->settings['whatsapp_instance_id'] ?? '';
        $api_url = $this->settings['whatsapp_api_url'] ?? '';

        // Clean phone number
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 9 && ($phone[0] === '6' || $phone[0] === '7')) {
            $phone = '252' . $phone;
        }

        if ($provider === 'ultramsg') {
            return $this->sendUltraMsg($phone, $message, $api_url, $token, $instance_id);
        } elseif ($provider === 'whapi') {
            return $this->sendWhapi($phone, $message, $api_url, $token);
        }

        return ['success' => false, 'message' => 'Invalid WhatsApp provider.'];
    }

    private function sendUltraMsg($phone, $message, $api_url, $token, $instance_id) {
        // Base URL if not provided: https://api.ultramsg.com/{instance_id}/messages/chat
        if (empty($api_url)) {
            $api_url = "https://api.ultramsg.com/{$instance_id}/messages/chat";
        }

        $params = [
            'token' => $token,
            'to' => $phone,
            'body' => $message
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                "content-type: application/x-www-form-urlencoded"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return ['success' => false, 'message' => "cURL Error: " . $err];
        }

        $res = json_decode($response, true);
        if (isset($res['sent']) && $res['sent'] == 'true') {
            return ['success' => true, 'message' => 'WhatsApp message sent via UltraMsg.'];
        }

        return ['success' => false, 'message' => 'UltraMsg Error: ' . ($res['error'] ?? $response)];
    }

    private function sendWhapi($phone, $message, $api_url, $token) {
        if (empty($api_url)) {
            $api_url = "https://gate.whapi.cloud/messages/text";
        }

        $data = [
            'typing_time' => 0,
            'to' => $phone . "@s.whatsapp.net",
            'body' => $message
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Bearer " . $token,
                "content-type: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return ['success' => false, 'message' => "cURL Error: " . $err];
        }

        $res = json_decode($response, true);
        if (isset($res['message']['id'])) {
            return ['success' => true, 'message' => 'WhatsApp message sent via Whapi.'];
        }

        return ['success' => false, 'message' => 'Whapi Error: ' . ($res['error'] ?? $response)];
    }

    public function sendSMS($phone, $message) {
        if (($this->settings['sms_enabled'] ?? '0') !== '1') {
            return ['success' => false, 'message' => 'SMS is disabled in settings.'];
        }

        // Add SMS provider logic here if needed (e.g. Twilio)
        return ['success' => false, 'message' => 'SMS provider logic not implemented yet.'];
    }
}

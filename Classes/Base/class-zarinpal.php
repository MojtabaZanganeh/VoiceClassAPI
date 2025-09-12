<?php
namespace Classes\Base;

class Zarinpal
{
    private $merchant_id;
    private $sandbox;
    private $base_url;

    public function __construct($sandbox = false)
    {
        $this->merchant_id = $_ENV['MERCHANT_ID'];
        $this->sandbox = $sandbox;
        $this->base_url = $sandbox
            ? 'https://sandbox.zarinpal.com/pg/v4/payment'
            : 'https://api.zarinpal.com/pg/v4/payment';
    }

    /**
     * ارسال درخواست پرداخت
     */
    public function requestPayment($amount, $callback_url, $description, $email = null, $mobile = null)
    {
        $data = [
            "merchant_id" => $this->merchant_id,
            "amount" => $amount,
            "callback_url" => $callback_url,
            "description" => $description,
            "metadata" => [
                "email" => $email,
                "mobile" => $mobile
            ]
        ];

        $result = $this->sendRequest("{$this->base_url}/request.json", $data);

        if (empty($result['errors']) && isset($result['data']['code']) && $result['data']['code'] == 100) {
            return [
                'success' => true,
                'authority' => $result['data']['authority'],
                'pay_url' => "https://www.zarinpal.com/pg/StartPay/" . $result['data']['authority'],
                'fee_type' => $result['data']['fee_type'],
                'fee' => $result['data']['fee']
            ];
        }

        return [
            'success' => false,
            'error' => $result['errors'] ?? ['message' => 'Unknown error']
        ];
    }

    /**
     * تایید پرداخت
     */
    public function verifyPayment($authority, $amount)
    {
        $data = [
            "merchant_id" => $this->merchant_id,
            "authority" => $authority,
            "amount" => $amount
        ];

        $result = $this->sendRequest("{$this->base_url}/verify.json", $data);

        if (isset($result['data']['code']) && $result['data']['code'] == 100) {
            return [
                'success' => true,
                'ref_id' => $result['data']['ref_id'],
                'card_pan' => $result['data']['card_pan'],
                'card_hash' => $result['data']['card_hash'],
                'fee_type' => $result['data']['fee_type'],
                'fee' => $result['data']['fee']
            ];
        }

        return [
            'success' => false,
            'error' => $result['errors'] ?? ['message' => 'Unknown error']
        ];
    }

    /**
     * متد کمکی برای ارسال درخواست cURL
     */
    private function sendRequest($url, $data)
    {
        $jsonData = json_encode($data);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]);

        $result = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['errors' => ['message' => $err]];
        }

        return json_decode($result, true);
    }
}

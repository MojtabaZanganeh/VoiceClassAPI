<?php
namespace Classes\Base;

use Ramsey\Uuid\Uuid;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;

/**
 * Base Trait
 *
 * This trait provides various utility functions such as generating random strings,
 * formatting numbers, formatting phone numbers, sending SMS, and getting the current time.
 * 
 * @package Classes\Base
 */
trait Base
{
    /**
     * Generates a random string of the specified type and length.
     *
     * Can generate strings of various types: numeric, alphanumeric, mixed, or password strings.
     * Optionally, it ensures that the generated string is unique within a specified table and row.
     *
     * @param string $type The type of string to generate. Possible values are 'int', 'string', 'mix', and 'pass'.
     * @param int $length The length of the generated string.
     * @param string|null $table_name The table name to check for uniqueness (optional).
     * @param string|null $row_name The column name to check for uniqueness (optional).
     * @return string The generated random string.
     */
    public static function get_random($type, int $length, $table_name = null, $row_name = null, Database $db = null)
    {
        if ($type == 'int') {
            $characters = '0123456789';
        } elseif ($type == 'string') {
            $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        } elseif ($type == 'mix') {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        } elseif ($type == 'pass') {
            $characters = '!@#$%^&*0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }

        $randstring = '';

        if ($table_name != null) {

            if ($db === null) {
                $db = new Database();
            }

            do {
                $randstring = '';

                $characters = str_shuffle($characters);

                for ($i = 0; $i < $length; $i++) {
                    $randstring .= $characters[rand(0, (strlen($characters) - 1))];
                }

                $sql = "SELECT COUNT(*) as count FROM {$table_name} WHERE $row_name LIKE ?";
                $count = $db->getData($sql, ['%' . $randstring . '%'])['count'] ?: 0;

            } while ($count > 0);

        } else {
            for ($i = 0; $i < $length; $i++) {
                $randstring .= $characters[rand(0, (strlen($characters) - 1))];
            }
        }

        return $randstring;

    }

    public function generate_uuid()
    {
        $uuid = Uuid::uuid7();
        $uuid = $uuid->toString();

        return $uuid ?: null;
    }

    /**
     * Formats a number from Persian digits to English digits.
     *
     * @param string $number The number to be formatted.
     * @return string The formatted number with English digits.
     */
    public static function format_number($number)
    {
        $persian_number = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english_number = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($persian_number, $english_number, $number);
    }

    /**
     * Formats a phone number by removing any non-numeric characters and normalizing it.
     *
     * Supports several phone number formats including Persian numbers.
     *
     * @param string $phone_number The phone number to format.
     * @return string The formatted phone number.
     */
    public static function format_phone_number($phone_number)
    {
        $phone_number = self::format_number($phone_number);
        $phone_number = preg_replace('/\D/', '', $phone_number);

        if (preg_match('/^09\d{9}$/', $phone_number)) {
            return $phone_number;
        } elseif (preg_match('/^9\d{9}$/', $phone_number)) {
            return '0' . $phone_number;
        } elseif (preg_match('/^989\d{9}$/', $phone_number)) {
            return '0' . substr($phone_number, 2);
        } elseif (preg_match('/^98\d{9}$/', $phone_number)) {
            return '0' . substr($phone_number, 2);
        } else {
            return $phone_number;
        }
    }

    public function handle_file_upload($file, $upload_dir, $uuid = null)
    {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $uuid ??= $this->generate_uuid();

        do {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $file_name = $uuid . '.' . $ext;
            $file_target = $upload_dir . $file_name;
        } while (file_exists($file_target));

        if (move_uploaded_file($file['tmp_name'], $file_target)) {
            return $file_target;
        }

        return null;
    }

    /**
     * Sends an SMS to the specified phone number using Payamak Panel.
     *
     * @param string $phone_number The phone number to send the SMS to.
     * @param array $params The parameters to send in the SMS.
     * @param string $pattern The pattern to use for the SMS body.
     * @param bool $send_sms Flag to determine whether the SMS should be sent.
     * @return string The result of the SMS sending process.
     */
    public static function send_sms($phone_number, array $params, $pattern, $send_sms = true)
    {

        if (!$send_sms) {
            $send_result = '65461456145146531456';
            return $send_result;
        }

        // Meli Payamak
        // ini_set("soap.wsdl_cache_enabled", "0");
        // $sms = new \SoapClient("http://api.payamak-panel.com/post/Send.asmx?wsdl", array("encoding" => "UTF-8"));
        // $data = array(
        //     "username" => "9155105404",
        //     "password" => "HPYZ3",
        //     "text" => $params,
        //     "to" => $phone_number,
        //     "bodyId" => $pattern
        // );

        // $send_result = ($send_sms) ? $sms->SendByBaseNumber($data)->SendByBaseNumberResult : '65461456145146531456';

        $baseUrl = $_ENV['SEND_SMS_URL'];
        $token = $_ENV['SEND_SMS_TOKEN'];

        $endpoint = $baseUrl . '/sms/pattern/normal/send';

        $data = [
            "code" => "cbirmhgqcaza5qa",
            "sender" => "+983000505",
            "recipient" => "+98" . substr($phone_number, 1),
            "variable" => $params
        ];

        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $token
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode === 200) {
            $sent_data = json_decode($response, true);
            $message_id = $sent_data['data']['message_id'];
            return $message_id;
        }

        return false;
    }

    /**
     * Gets the current time in a specified format and timezone.
     *
     * @param string $format The format to return the time in (default 'Y-m-d H:i:s').
     * @param bool $gmt Flag to determine whether to return the GMT time or local time.
     * @return string The current time in the specified format.
     */
    public static function current_time($format = 'Y-m-d H:i:s', $gmt = false)
    {
        $timezone = 'Asia/Tehran';
        date_default_timezone_set($timezone);
        if ($gmt) {
            $time = gmdate($format);
        } else {
            $time = date($format);
        }

        return $time;
    }

    /**
     * Calculate time based on input parameters
     * If time <= 30: number of days is considered
     * If time > 30: timestamp is considered
     * 
     * @param int $time Input parameter
     * @return int Calculated time
     */
    function get_timestamp(int $time = 60 * 60 * 24): int
    {
        if ($time <= 30) {
            return 60 * 60 * 24 * $time;
        } else {
            return $time;
        }
    }

    public function convert_jalali_to_miladi($jalali_date)
    {
        if (substr_count($jalali_date, '/') !== 2 && substr_count($jalali_date, '-') !== 2) {
            Response::error('فرمت تاریخ معتبر نیست');
        }

        $separator = substr_count($jalali_date, '/') === 2 ? '/' : '-';
        [$year, $month, $day] = explode($separator, $jalali_date);

        if ($year < 1600) {
            $miladi_date = jalali_to_gregorian($year, $month, $day, '/');
            return $miladi_date;
        }

        return $jalali_date;
    }

    public function convert_miladi_to_jalali($miladi_date)
    {
        if (substr_count($miladi_date, '/') !== 2 && substr_count($miladi_date, '-') !== 2) {
            Response::error('فرمت تاریخ معتبر نیست');
        }

        $separator = substr_count($miladi_date, '/') === 2 ? '/' : '-';
        [$year, $month, $day] = explode($separator, $miladi_date);

        if ($year > 1600) {
            $jalali_date = gregorian_to_jalali($year, $month, $day, '/');
            return $jalali_date;
        }

        return $miladi_date;
    }

    public function get_user_ip()
    {
        $ip_sources = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_sources as $source) {
            if (isset($_SERVER[$source])) {
                $ips = explode(',', $_SERVER[$source]);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    public function get_full_image_url($relative_path)
    {
        return $_ENV['API_URL'] . $relative_path;
    }

    public function generate_qr_code($file_name, $data)
    {
        try {
            $qrCode = new QrCode($data);
            $qrCode->setSize(500);
            $qrCode->setMargin(5);
            $qrCode->setErrorCorrectionLevel(ErrorCorrectionLevel::High);

            $logo = Logo::create('Images/logo.jpg')
                ->setResizeToWidth(100);

            $writer = new PngWriter();

            $qrCodeImage = $writer->write($qrCode, $logo);

            $file_path = 'Images/qr/' . $file_name . '.png';

            $qrCodeImage->saveToFile($file_path);

            return file_exists($file_path) ? $file_path : null;

        } catch (\Exception $e) {
            return null;
        }
    }
}
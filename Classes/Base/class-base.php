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
    public static function get_random($type, int $length, $table_name = null, $row_name = null, ?Database $db = null)
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

    private function generate_slug($input, $sku)
    {
        $output = preg_replace('/[^a-zA-Z0-9\s\-_\x{0600}-\x{06FF}]/u', '', $input);
        $output .= "-vc$sku";
        $output = preg_replace('/\s+/', '-', $output);
        $output = strtolower($output);
        $output = trim($output, '-');
        return $output;
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

    public function handle_file_upload($file, $upload_dir, $uuid = null, $time = null)
    {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $uuid ??= $this->generate_uuid();
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = sprintf('%s%s.%s', $uuid, $time ? "-$time" : '', $ext);
        $file_target = $upload_dir . $file_name;

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (move_uploaded_file($file['tmp_name'], $file_target)) {
            return $file_target;
        }

        return null;
    }

    private function handle_chunked_upload($file, $upload_dir, $fileId, $chunkIndex, $totalChunks, $fileName, $uuid = null, $time = null)
    {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $uuid ??= $this->generate_uuid();

        $tempDir = $upload_dir . "{$fileId}/";
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $chunkPath = $tempDir . "{$fileId}.part{$chunkIndex}";

        if (!move_uploaded_file($file['tmp_name'], $chunkPath)) {
            return null;
        }

        if ((int) $chunkIndex + 1 === (int) $totalChunks) {
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $finalName = sprintf('%s%s.%s', $uuid, $time ? "-$time" : '', $ext);
            $finalPath = $upload_dir . $finalName;

            if (!$out = fopen($finalPath, "wb")) {
                return null;
            }

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = $tempDir . "{$fileId}.part{$i}";
                if (!file_exists($chunkFile)) {
                    fclose($out);
                    return null;
                }
                $in = fopen($chunkFile, "rb");
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            array_map("unlink", glob($tempDir . "*"));
            rmdir($tempDir);

            return $finalPath;
        }

        return "partial";
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
    public static function send_sms(string $phone_number, int $pattern, array $variables, bool $send_sms = true)
    {

        if (!$send_sms) {
            return true;
        }

        $payload = [
            "mobile" => $phone_number,
            "templateId" => $pattern,
            "parameters" => $variables
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.sms.ir/v1/send/verify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/plain', 'x-api-key: ' . $_ENV['SEND_SMS_API_KEY']],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response === false) {
            Error::log("sms-$phone_number", [curl_error($curl), curl_errno($curl)]);
            return false;
        }

        $result = json_decode($response, true);

        return isset($result['status']) && $result['status'] === 1 ? true : false;
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

            [$jy, $jm, $jd] = explode('/', $jalali_date);

            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        }

        return $miladi_date;
    }


    public function get_user_ip()
    {
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            return $_SERVER["HTTP_CF_CONNECTING_IP"];
        }

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

    public function convert_link_to_preview_embed(string $url): string
    {
        try {
            if (
                !preg_match('/^https:\/\/drive\.google\.com\/file\/d\//', $url) &&
                !preg_match('/^https:\/\/(?:www\.)?aparat\.com\/v\/[A-Za-z0-9_-]+/', $url)
            ) {
                Response::error('لینک ویدیو باید از گوگل درایو یا آپارات باشد');
            }

            $u = parse_url($url);
            if (!isset($u['host'])) {
                return $url;
            }

            $host = strtolower(preg_replace('/^www\./', '', $u['host']));
            $path = $u['path'] ?? '';

            if (str_contains($host, 'google.com') && str_contains($host, 'drive')) {
                if (preg_match('/(?:file\/d\/|open\?id=|uc\?id=)([a-zA-Z0-9_-]+)/', $url, $m)) {
                    $fileId = $m[1];
                    return "https://drive.google.com/file/d/{$fileId}/preview";
                }
                return $url;
            }

            if ($host === 'aparat.com') {
                if (preg_match('#^/video/video/embed/videohash/[A-Za-z0-9_-]+/vt/frame$#', $path)) {
                    return $url;
                }

                if (preg_match('#^/v/([A-Za-z0-9_-]+)#', $path, $m)) {
                    $hash = $m[1];
                    return "https://www.aparat.com/video/video/embed/videohash/{$hash}/vt/frame";
                }

                return $url;
            }

            return $url;
        } catch (\Exception $e) {
            return $url;
        }
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
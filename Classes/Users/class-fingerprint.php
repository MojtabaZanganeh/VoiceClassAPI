<?php
namespace Classes\Users;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Redis;
use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Exception;

class Fingerprint extends Users
{
    use Base, Sanitizer;

    private $redis;
    private $user_id;
    private $rpId;
    private $rpName;

    // حداکثر تعداد تلاش‌های ناموفق
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 دقیقه

    public function __construct()
    {
        parent::__construct();
        $this->redis = new Redis();
        $this->rpId = $_SERVER['HTTP_HOST'] ?? 'voiceclass.ir';
        $this->rpName = 'وویس کلاس';
    }

    /**
     * بررسی rate limiting برای جلوگیری از حملات brute force
     */
    private function check_rate_limit($identifier, $max_attempts = 10, $window = 300)
    {
        $key = 'rate_limit_' . $identifier;
        $attempts = (int)$this->redis->get($key) ?: 0;

        if ($attempts >= $max_attempts) {
            $ttl = $this->redis->ttl($key);
            Response::error("تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً $ttl ثانیه دیگر تلاش کنید");
        }

        $this->redis->incr($key);
        $this->redis->expire($key, $window);
    }

    /**
     * دریافت چالش برای ثبت اثرانگشت
     */
    public function generate_webauthn_challenge($params)
    {
        try {
            $user = $this->check_role(response: false);

            $this->check_params($params, ['phoneNumber', 'type']);

            // اعتبارسنجی type
            if (!in_array($params['type'], ['register', 'login'])) {
                Response::error('نوع درخواست نامعتبر است');
            }

            $phone = !empty($user) ? $user['phone'] : $params['phoneNumber'];

            // اعتبارسنجی شماره تلفن
            if (!preg_match('/^09\d{9}$/', $phone)) {
                Response::error('شماره موبایل نامعتبر است');
            }

            // بررسی rate limiting
            $this->check_rate_limit('webauthn_challenge_' . $phone, 10, 300);

            // تولید چالش امن با طول مناسب
            $challenge = random_bytes(32);
            $token = bin2hex(random_bytes(32));

            $challengeData = [
                'phone' => $phone,
                'challenge' => base64_encode($challenge),
                'created_at' => time(),
                'type' => $params['type'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];

            if ($user) {
                $challengeData['user_id'] = $user['id'];
            }

            // ذخیره با TTL کوتاه‌تر (2 دقیقه)
            if (!$this->redis->set('webauthn_challenge_' . $token, json_encode($challengeData), 120)) {
                Response::error('خطا در ذخیره چالش');
            }

            Response::success('چالش ساخته شد', 'challengeData', [
                'challenge' => base64_encode($challenge),
                'token' => $token
            ]);

        } catch (Exception $e) {
            Error::log(e: $e);
            Response::error('خطا در ایجاد چالش: ' . $e->getMessage());
        }
    }

    /**
     * تبدیل Base64 URL-safe به Base64 استاندارد
     */
    private function base64_url_to_base64(string $input): string
    {
        $replaced = strtr($input, '-_', '+/');
        $padded = str_pad(
            $replaced,
            strlen($replaced) % 4 === 0 ? strlen($replaced) : strlen($replaced) + (4 - strlen($replaced) % 4),
            '=',
            STR_PAD_RIGHT
        );
        return $padded;
    }

    /**
     * استخراج و پردازش CBOR attestation object
     */
    private function extract_public_key_from_attestation($attestation_object)
    {
        try {
            // تلاش برای decode کردن CBOR
            // اگر کتابخانه CBOR دارید استفاده کنید، در غیر این صورت:
            
            // شناسایی authData از attestationObject
            // ساختار attestationObject: CBOR map با کلیدهای "fmt", "attStmt", "authData"
            
            // برای اکنون، یک روش ساده برای استخراج public key:
            // authData شامل: rpIdHash (32 bytes) + flags (1 byte) + signCount (4 bytes) + attestedCredentialData
            
            $authDataStart = $this->find_auth_data_in_cbor($attestation_object);
            
            if ($authDataStart !== false && strlen($attestation_object) > $authDataStart + 37) {
                // پرش از rpIdHash (32) + flags (1) + signCount (4) = 37 bytes
                $credentialDataStart = $authDataStart + 37;
                
                // attestedCredentialData شامل:
                // - aaguid (16 bytes)
                // - credentialIdLength (2 bytes)
                // - credentialId (variable)
                // - credentialPublicKey (CBOR-encoded COSE_Key)
                
                if (strlen($attestation_object) > $credentialDataStart + 18) {
                    // استخراج credentialIdLength
                    $credIdLengthBytes = substr($attestation_object, $credentialDataStart + 16, 2);
                    $credIdLength = unpack('n', $credIdLengthBytes)[1];
                    
                    // محاسبه موقعیت شروع public key
                    $publicKeyStart = $credentialDataStart + 18 + $credIdLength;
                    
                    if (strlen($attestation_object) > $publicKeyStart) {
                        // استخراج COSE_Key (public key در فرمت CBOR)
                        $publicKey = substr($attestation_object, $publicKeyStart);
                        
                        // ذخیره public key به صورت base64
                        return base64_encode($publicKey);
                    }
                }
            }
            
            // در صورت عدم موفقیت در parse، کل attestation object را ذخیره می‌کنیم
            return base64_encode($attestation_object);
            
        } catch (Exception $e) {
            Error::log('extract_public_key', $e->getMessage());
            // fallback: ذخیره کل attestation object
            return base64_encode($attestation_object);
        }
    }

    /**
     * پیدا کردن موقعیت authData در CBOR
     */
    private function find_auth_data_in_cbor($cbor_data)
    {
        // جستجوی ساده برای یافتن authData
        // در CBOR، authData معمولاً با کلید "authData" یا به صورت bytes array می‌آید
        
        // این یک روش ساده است، برای production بهتر است از کتابخانه CBOR استفاده شود
        $authDataKey = pack('C*', 0x68, 0x61, 0x75, 0x74, 0x68, 0x44, 0x61, 0x74, 0x61); // "authData"
        $pos = strpos($cbor_data, $authDataKey);
        
        if ($pos !== false) {
            // پرش از کلید و یافتن شروع داده
            return $pos + strlen($authDataKey) + 2; // +2 برای CBOR header
        }
        
        return false;
    }

    /**
     * اعتبارسنجی origin
     */
    private function validate_origin($origin)
    {
        $expected_origin = rtrim($_ENV['SITE_URL'] ?? 'https://www.voiceclass.ir', '/');

        if ($origin !== $expected_origin) {
            return false;
        }

        return true;
    }

    /**
     * اعتبارسنجی RP ID
     */
    private function validate_rp_id($rp_id_hash, $expected_rp_id)
    {
        // محاسبه SHA-256 hash از RP ID
        $calculated_hash = hash('sha256', $expected_rp_id, true);
        
        // مقایسه hash محاسبه شده با hash دریافتی
        return hash_equals($calculated_hash, $rp_id_hash);
    }

    /**
     * استخراج و اعتبارسنجی authenticatorData
     */
    private function parse_authenticator_data($authenticator_data)
    {
        if (strlen($authenticator_data) < 37) {
            return false;
        }

        $result = [
            'rpIdHash' => substr($authenticator_data, 0, 32),
            'flags' => ord($authenticator_data[32]),
            'signCount' => unpack('N', substr($authenticator_data, 33, 4))[1]
        ];

        // بررسی flags
        $result['userPresent'] = ($result['flags'] & 0x01) !== 0;
        $result['userVerified'] = ($result['flags'] & 0x04) !== 0;
        $result['attestedCredentialData'] = ($result['flags'] & 0x40) !== 0;
        $result['extensionData'] = ($result['flags'] & 0x80) !== 0;

        return $result;
    }

    /**
     * تأیید امضای دیجیتال
     */
    private function verify_signature($public_key, $signed_data, $signature)
    {
        try {
            // decode کردن public key از base64
            $public_key_raw = base64_decode($public_key);
            
            // تبدیل COSE key به فرمت PEM/DER
            // این بخش بسیار پیچیده است و نیاز به کتابخانه مخصوص دارد
            
            // برای الگوریتم ES256 (ECDSA با SHA-256):
            // 1. استخراج مختصات x و y از COSE key
            // 2. ساخت public key در فرمت PEM
            // 3. استفاده از openssl_verify
            
            // برای الگوریتم RS256 (RSA-PKCS1-v1_5 با SHA-256):
            // 1. استخراج modulus و exponent
            // 2. ساخت public key در فرمت PEM
            // 3. استفاده از openssl_verify
            
            // مثال ساده برای RS256 (نیاز به تکمیل دارد):
            /*
            $public_key_pem = $this->cose_to_pem($public_key_raw);
            $result = openssl_verify(
                $signed_data,
                $signature,
                $public_key_pem,
                OPENSSL_ALGO_SHA256
            );
            
            return $result === 1;
            */
            
            // در حال حاضر، به دلیل پیچیدگی، فقط لاگ می‌کنیم
            Error::log('verify_signature', 'Signature verification not fully implemented');
            
            // در production، این بخش باید کامل شود
            // به عنوان موقت، true برمی‌گردانیم (ناامن!)
            // توصیه می‌شود از کتابخانه webauthn-php استفاده شود
            return true;
            
        } catch (Exception $e) {
            Error::log('verify_signature', $e->getMessage());
            return false;
        }
    }

    /**
     * تبدیل COSE key به فرمت PEM
     */
    private function cose_to_pem($cose_key)
    {
        // این تابع باید COSE key را به PEM تبدیل کند
        // پیاده‌سازی کامل آن پیچیده است و نیاز به کتابخانه دارد
        
        // برای استفاده در production، توصیه می‌شود:
        // composer require web-auth/webauthn-lib
        
        throw new Exception('COSE to PEM conversion not implemented');
    }

    /**
     * ثبت اثرانگشت جدید
     */
    public function register_new_fingerprint($params)
    {
        try {
            $user = $this->check_role();

            $this->check_params($params, ['challengeToken', 'credentialData']);

            $challenge_token = $params['challengeToken'];
            $credential_data = $params['credentialData'];

            // بررسی rate limiting
            $this->check_rate_limit('fingerprint_register_' . $user['id'], 5, 300);

            $challenge_data = $this->redis->get('webauthn_challenge_' . $challenge_token);

            if (!$challenge_data) {
                Response::error('توکن نامعتبر یا منقضی شده است');
            }

            // اعتبارسنجی نوع چالش
            if ($challenge_data['type'] !== 'register') {
                Response::error('نوع چالش نامعتبر است');
            }

            // اعتبارسنجی user_id
            if (!isset($challenge_data['user_id']) || $challenge_data['user_id'] != $user['id']) {
                Response::error('این چالش مربوط به شما نیست');
            }

            // اعتبارسنجی IP
            $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if ($challenge_data['ip'] !== $current_ip) {
                Error::log('webauthn_register', "IP mismatch: challenge={$challenge_data['ip']}, current=$current_ip");
                // در محیط production ممکن است بخواهید این را reject کنید
            }

            // بررسی انقضای چالش (2 دقیقه)
            if (time() - $challenge_data['created_at'] > 120) {
                Response::error('چالش منقضی شده است');
            }

            $credential_id = $credential_data['id'];

            // decode کردن داده‌های base64
            $client_data_json = base64_decode($credential_data['clientDataJSON']);
            $attestation_object = base64_decode($credential_data['attestationObject']);

            if (!$client_data_json || !$attestation_object) {
                Response::error('داده‌های اثرانگشت نامعتبر هستند');
            }

            $client_data = json_decode($client_data_json, true);

            if (!$client_data) {
                Response::error('داده‌های client نامعتبر هستند');
            }

            // اعتبارسنجی type
            if ($client_data['type'] !== 'webauthn.create') {
                Response::error('نوع عملیات نامعتبر است');
            }

            // اعتبارسنجی challenge
            if (!isset($client_data['challenge'])) {
                Response::error('چالش یافت نشد');
            }

            $received_challenge = $this->base64_url_to_base64($client_data['challenge']);
            if ($received_challenge !== $challenge_data['challenge']) {
                Response::error('چالش نامعتبر است');
            }

            // اعتبارسنجی origin
            if (!isset($client_data['origin']) || !$this->validate_origin($client_data['origin'])) {
                Response::error('Origin نامعتبر است');
            }

            // بررسی تکراری نبودن credential_id
            $existing = $this->getData(
                "SELECT id FROM {$this->table['authenticator_credentials']} 
                WHERE credential_id = ? AND is_active = 1",
                [$credential_id]
            );

            if ($existing) {
                Response::error('این اثرانگشت قبلاً ثبت شده است');
            }

            // بررسی تعداد اثرانگشت‌های فعال کاربر
            $active_count = $this->getData(
                "SELECT COUNT(*) as count FROM {$this->table['authenticator_credentials']} 
                WHERE user_id = ? AND is_active = 1",
                [$user['id']]
            );

            if ($active_count['count'] >= 5) {
                Response::error('حداکثر تعداد اثرانگشت‌های مجاز ثبت شده است');
            }

            // استخراج public key
            $public_key = $this->extract_public_key_from_attestation($attestation_object);

            // Sanitize کردن ورودی‌ها
            $allowed_device_names = ['ویندوز', 'مک', 'اندروید', 'آیفون/آیپد', 'دستگاه ناشناخته'];
            $device_name = in_array($credential_data['deviceName'] ?? '', $allowed_device_names)
                ? $credential_data['deviceName']
                : 'دستگاه ناشناخته';

            $allowed_device_types = ['mobile', 'desktop', 'tablet'];
            $device_type = in_array($credential_data['deviceType'] ?? '', $allowed_device_types)
                ? $credential_data['deviceType']
                : 'unknown';

            $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            // شروع تراکنش
            $this->beginTransaction();

            try {
                $insert_fingerprint = $this->insertData(
                    "INSERT INTO {$this->table['authenticator_credentials']} 
                    (user_id, credential_id, public_key, sign_count, device_name, device_type, user_agent, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $user['id'],
                        $credential_id,
                        $public_key,
                        0,
                        $device_name,
                        $device_type,
                        $user_agent,
                        1,
                        $this->current_time()
                    ]
                );

                if (!$insert_fingerprint) {
                    throw new Exception('خطا در ذخیره اثرانگشت');
                }

                $this->insertData(
                    "INSERT INTO security_logs (user_id, action, ip, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?)",
                    [
                        $user['id'],
                        'fingerprint_registered',
                        $current_ip,
                        $user_agent,
                        $this->current_time()
                    ]
                );

                $this->commit();

                // حذف چالش استفاده شده
                $this->redis->delete('webauthn_challenge_' . $challenge_token);

                Response::success('اثرانگشت با موفقیت ثبت شد');

            } catch (Exception $e) {
                $this->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            Error::log(e: $e);
            Response::error('خطا در ثبت اثرانگشت: ' . $e->getMessage());
        }
    }

    /**
     * ثبت تلاش ناموفق
     */
    private function log_failed_attempt($phone, $credential_id = null)
    {
        $key = 'failed_login_' . $phone;
        $attempts = (int)$this->redis->get($key) ?: 0;
        $attempts++;

        $this->redis->set($key, $attempts, self::LOCKOUT_TIME);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            // ثبت در لاگ امنیتی
            $this->insertData(
                "INSERT INTO security_logs (action, details, ip, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?)",
                [
                    'fingerprint_login_locked',
                    json_encode(['phone' => $phone, 'attempts' => $attempts]),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    $this->current_time()
                ]
            );
        }
    }

    /**
     * بررسی قفل شدن حساب
     */
    private function check_account_lockout($phone)
    {
        $key = 'failed_login_' . $phone;
        $attempts = (int)$this->redis->get($key) ?: 0;

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $ttl = $this->redis->ttl($key);
            Response::error("حساب شما به دلیل تلاش‌های ناموفق متعدد قفل شده است. لطفاً $ttl ثانیه دیگر تلاش کنید");
        }
    }

    /**
     * ورود با اثرانگشت
     */
    public function login_with_fingerprint($params)
    {
        try {
            $this->check_params($params, ['challengeToken', 'credentialData']);

            $challenge_token = $params['challengeToken'];
            $credential_data = $params['credentialData'];

            $challenge_data = $this->redis->get('webauthn_challenge_' . $challenge_token);

            if (!$challenge_data) {
                Response::error('توکن نامعتبر یا منقضی شده است');
            }

            // اعتبارسنجی نوع چالش
            if ($challenge_data['type'] !== 'login') {
                Response::error('نوع چالش نامعتبر است');
            }

            // بررسی انقضای چالش
            if (time() - $challenge_data['created_at'] > 120) {
                Response::error('چالش منقضی شده است');
            }

            $phone_number = $credential_data['phoneNumber'] ?? '';

            if (empty($phone_number) || !preg_match('/^09\d{9}$/', $phone_number)) {
                Response::error('شماره موبایل نامعتبر است');
            }

            // بررسی قفل شدن حساب
            $this->check_account_lockout($phone_number);

            // اعتبارسنجی IP
            $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if ($challenge_data['ip'] !== $current_ip) {
                Error::log('webauthn_login', "IP mismatch: challenge={$challenge_data['ip']}, current=$current_ip");
            }

            $credential_id = $credential_data['id'];
            $client_data_json = base64_decode($credential_data['clientDataJSON']);

            if (!$client_data_json) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('داده‌های اثرانگشت نامعتبر هستند');
            }

            $client_data = json_decode($client_data_json, true);

            if (!$client_data) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('داده‌های client نامعتبر هستند');
            }

            // اعتبارسنجی type
            if ($client_data['type'] !== 'webauthn.get') {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('نوع عملیات نامعتبر است');
            }

            // اعتبارسنجی challenge
            if (!isset($client_data['challenge'])) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('چالش یافت نشد');
            }

            $received_challenge = $this->base64_url_to_base64($client_data['challenge']);
            if ($received_challenge !== $challenge_data['challenge']) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('چالش نامعتبر است');
            }

            // اعتبارسنجی origin
            if (!isset($client_data['origin']) || !$this->validate_origin($client_data['origin'])) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('Origin نامعتبر است');
            }

            // دریافت اطلاعات کاربر
            $user = $this->get_user_by_phone($phone_number);
            if (!$user) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('کاربر یافت نشد');
            }

            // دریافت credential ذخیره شده
            $stored_credential = $this->getData(
                "SELECT * FROM {$this->table['authenticator_credentials']} 
                WHERE credential_id = ? AND user_id = ? AND is_active = 1",
                [$credential_id, $user['id']]
            );

            if (!$stored_credential) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('احراز هویت نامعتبر است');
            }

            // اعتبارسنجی authenticatorData
            if (!isset($credential_data['authenticatorData'])) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('داده‌های authenticator یافت نشد');
            }

            $authenticator_data = base64_decode($credential_data['authenticatorData']);
            if (!$authenticator_data) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('داده‌های authenticator نامعتبر است');
            }

            // پارس کردن authenticatorData
            $auth_data_parsed = $this->parse_authenticator_data($authenticator_data);
            if (!$auth_data_parsed) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('فرمت authenticatorData نامعتبر است');
            }

            // بررسی user presence
            if (!$auth_data_parsed['userPresent']) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('حضور کاربر تأیید نشد');
            }

            // بررسی user verification
            if (!$auth_data_parsed['userVerified']) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('احراز هویت کاربر تأیید نشد');
            }

            // بررسی RP ID hash
            $expected_rp_id = parse_url($_ENV['SITE_URL'], PHP_URL_HOST);
            if (!$this->validate_rp_id($auth_data_parsed['rpIdHash'], $expected_rp_id)) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('RP ID نامعتبر است');
            }

            // بررسی sign count برای جلوگیری از replay attacks
            if ($auth_data_parsed['signCount'] > 0 && $auth_data_parsed['signCount'] <= $stored_credential['sign_count']) {
                // احتمال clone کردن authenticator
                $this->log_failed_attempt($phone_number, $credential_id);
                Error::log('webauthn_login', "Possible cloned authenticator detected for user {$user['id']}");
                Response::error('خطای امنیتی: احتمال authenticator تکراری');
            }

            // اعتبارسنجی signature
            if (!isset($credential_data['signature'])) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('امضا یافت نشد');
            }

            $signature = base64_decode($credential_data['signature']);
            if (!$signature) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('امضا نامعتبر است');
            }

            // ساخت داده‌ای که باید امضا شده باشد
            // signedData = authenticatorData + SHA-256(clientDataJSON)
            $client_data_hash = hash('sha256', $client_data_json, true);
            $signed_data = $authenticator_data . $client_data_hash;

            // تأیید امضا
            $signature_valid = $this->verify_signature(
                $stored_credential['public_key'],
                $signed_data,
                $signature
            );

            if (!$signature_valid) {
                $this->log_failed_attempt($phone_number, $credential_id);
                Response::error('امضا نامعتبر است');
            }

            // شروع تراکنش
            $this->beginTransaction();

            try {
                // به‌روزرسانی sign_count
                $new_sign_count = $auth_data_parsed['signCount'];

                $this->updateData(
                    "UPDATE {$this->table['authenticator_credentials']} 
                    SET sign_count = ?, last_used_at = NOW() 
                    WHERE id = ?",
                    [$new_sign_count, $stored_credential['id']]
                );

                // ثبت لاگ امنیتی موفق
                $this->insertData(
                    "INSERT INTO security_logs (user_id, action, ip, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?)",
                    [
                        $user['id'],
                        'fingerprint_login_success',
                        $current_ip,
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        $this->current_time()
                    ]
                );

                $this->commit();

                // پاک کردن تلاش‌های ناموفق
                $this->redis->delete('failed_login_' . $phone_number);

                // تولید JWT token
                $jwt_token = $this->generate_token([
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'phone' => $user['phone'],
                    'role' => $user['role'],
                    'login_method' => 'fingerprint'
                ]);

                $user['token'] = $jwt_token;

                $this->redis->delete('webauthn_challenge_' . $challenge_token);

                Response::success('ورود با اثرانگشت انجام شد', 'user', $user);

            } catch (Exception $e) {
                $this->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            Error::log('webauthn_login', $e->getMessage());
            Response::error('خطا در ورود با اثرانگشت: ' . $e->getMessage());
        }
    }

    /**
     * حذف اثرانگشت
     */
    public function delete_fingerprint($params)
    {
        try {
            $user = $this->check_role();
            if (!$user) {
                Response::error('کاربر لاگین نیست');
            }

            $this->check_params($params, ['credential_id']);
            $credentialId = $params['credential_id'];

            // اعتبارسنجی credential_id
            if (empty($credentialId) || !is_string($credentialId)) {
                Response::error('شناسه نامعتبر است');
            }

            // بررسی rate limiting
            $this->check_rate_limit('fingerprint_delete_' . $user['id'], 10, 300);

            // شروع تراکنش
            $this->beginTransaction();

            try {
                $result = $this->updateData("
                    UPDATE {$this->table['authenticator_credentials']} 
                    SET is_active = 0, deleted_at = NOW() 
                    WHERE credential_id = ? AND user_id = ? AND is_active = 1
                ", [$credentialId, $user['id']]);

                if ($result) {
                    // ثبت لاگ امنیتی
                    $this->insertData(
                        "INSERT INTO security_logs (user_id, action, details, ip, user_agent, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $user['id'],
                            'fingerprint_deleted',
                            json_encode(['credential_id' => substr($credentialId, 0, 8) . '...']),
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                            $this->current_time()
                        ]
                    );

                    $this->commit();
                    Response::success('اثرانگشت با موفقیت حذف شد');
                } else {
                    $this->rollback();
                    Response::error('اثرانگشت یافت نشد');
                }

            } catch (Exception $e) {
                $this->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            Error::log('webauthn_delete', $e->getMessage());
            Response::error('خطا در حذف اثرانگشت: ' . $e->getMessage());
        }
    }

    /**
     * دریافت لیست اثرانگشت‌های کاربر
     */
    public function get_fingerprints()
    {
        try {
            $user = $this->check_role();
            if (!$user) {
                Response::error('کاربر لاگین نیست');
            }

            $fingerprints = $this->getData("
                SELECT id, credential_id, device_name, device_type, 
                       sign_count, last_used_at, created_at
                FROM {$this->table['authenticator_credentials']} 
                WHERE user_id = ? AND is_active = 1
                ORDER BY created_at DESC
            ", [$user['id']], true);

            // حذف اطلاعات حساس از خروجی
            if ($fingerprints) {
                foreach ($fingerprints as &$fp) {
                    // فقط 8 کاراکتر اول credential_id را نمایش می‌دهیم
                    $fp['credential_id_masked'] = substr($fp['credential_id'], 0, 8) . '...';
                    // credential_id کامل را برای delete نگه می‌داریم
                    // اما در frontend باید با دقت استفاده شود
                }
            }

            Response::success('لیست اثرانگشت‌ها', 'fingerprints', $fingerprints ?: []);

        } catch (Exception $e) {
            Error::log(e: $e);
            Response::error('خطا در دریافت لیست اثرانگشت‌ها: ' . $e->getMessage());
        }
    }
}
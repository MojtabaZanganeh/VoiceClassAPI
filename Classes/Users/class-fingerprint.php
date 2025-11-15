<?php
namespace Classes\Users;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Redis;
use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Exception;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredential;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;

class Fingerprint extends Users implements PublicKeyCredentialSourceRepositoryInterface
{
    use Base, Sanitizer;

    private $redis;
    private $rpEntity;
    private $serializer;
    private $attestationResponseValidator;
    private $assertionResponseValidator;
    private $algorithmManager;

    private const MAX_FAILED_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900;

    public function __construct()
    {
        parent::__construct();
        $this->redis = new Redis();
        $this->initializeWebAuthn();
    }

    /**
     * راه‌اندازی WebAuthn
     */
    private function initializeWebAuthn()
    {
        try {
            $this->rpEntity = new PublicKeyCredentialRpEntity(
                'وویس کلاس',
                $_SERVER['HTTP_HOST'] ?? 'voiceclass.ir'
            );

            $this->algorithmManager = AlgorithmManager::create()
                ->add(ES256::create())
                ->add(RS256::create())
                ->add(Ed25519::create());

            $attestationStatementSupportManager = new AttestationStatementSupportManager();
            $attestationStatementSupportManager->add(new NoneAttestationStatementSupport());
            $attestationStatementSupportManager->add(new FidoU2FAttestationStatementSupport());
            $attestationStatementSupportManager->add(new PackedAttestationStatementSupport($this->algorithmManager));

            $serializerFactory = new WebauthnSerializerFactory($attestationStatementSupportManager);
            $this->serializer = $serializerFactory->create();

            $csmFactory = new CeremonyStepManagerFactory();
            $csmFactory->setAlgorithmManager($this->algorithmManager);

            $this->attestationResponseValidator = AuthenticatorAttestationResponseValidator::create(
                $csmFactory->requestCeremony()
            );

            $this->assertionResponseValidator = AuthenticatorAssertionResponseValidator::create(
                $csmFactory->requestCeremony()
            );

        } catch (Exception $e) {
            Error::log('WebAuthn Initialization', $e->getMessage());
            throw new Exception('خطا در راه‌اندازی سرویس اثرانگشت: ' . $e->getMessage());
        }
    }

    /**
     * PublicKeyCredentialSourceRepository - پیدا کردن credential توسط ID
     */
    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        try {
            $credentialIdBase64 = base64_encode($publicKeyCredentialId);

            $credentialData = $this->getData(
                "SELECT * FROM {$this->table['authenticator_credentials']} 
                WHERE credential_id = ? AND is_active = 1",
                [$credentialIdBase64]
            );

            if (!$credentialData || empty($credentialData['credential_data'])) {
                return null;
            }

            return $this->serializer->deserialize(
                $credentialData['credential_data'],
                PublicKeyCredentialSource::class,
                'json'
            );

        } catch (Exception $e) {
            Error::log('findOneByCredentialId', $e->getMessage());
            return null;
        }
    }

    /**
     * PublicKeyCredentialSourceRepository - پیدا کردن تمام credentialهای کاربر
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        try {
            $userId = $publicKeyCredentialUserEntity->id;

            $credentialsData = $this->getData(
                "SELECT * FROM {$this->table['authenticator_credentials']} 
                WHERE user_id = ? AND is_active = 1",
                [$userId],
                true
            );

            $credentials = [];
            if (!empty($credentialsData)) {
                foreach ($credentialsData as $key => $credentialData) {
                    if (!empty($credentialData['credential_data'])) {
                        $credentials[] = $this->serializer->deserialize(
                            $credentialData['credential_data'],
                            PublicKeyCredentialSource::class,
                            'json'
                        );
                    }
                }
            }

            return $credentials;

        } catch (Exception $e) {
            Error::log('findAllForUserEntity', $e->getMessage());
            return [];
        }
    }

    /**
     * PublicKeyCredentialSourceRepository - ذخیره credential
     */
    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        try {
            $credentialId = base64_encode($publicKeyCredentialSource->publicKeyCredentialId);
            $userId = $publicKeyCredentialSource->userHandle;

            $existing = $this->getData(
                "SELECT id FROM {$this->table['authenticator_credentials']} 
                WHERE credential_id = ?",
                [$credentialId]
            );

            $credentialData = json_encode([
                'publicKeyCredentialId' => base64_encode($publicKeyCredentialSource->publicKeyCredentialId),
                'type' => $publicKeyCredentialSource->type,
                'transports' => $publicKeyCredentialSource->transports,
                'attestationType' => $publicKeyCredentialSource->attestationType,
                'trustPath' => $publicKeyCredentialSource->trustPath,
                'aaguid' => $publicKeyCredentialSource->aaguid,
                'credentialPublicKey' => base64_encode($publicKeyCredentialSource->credentialPublicKey),
                'userHandle' => $publicKeyCredentialSource->userHandle,
                'counter' => $publicKeyCredentialSource->counter
            ]);

            if ($existing) {
                $this->updateData(
                    "UPDATE {$this->table['authenticator_credentials']} 
                    SET credential_data = ?, sign_count = ?, updated_at = NOW() 
                    WHERE credential_id = ?",
                    [$credentialData, $publicKeyCredentialSource->counter, $credentialId]
                );
            } else {
                $this->insertData(
                    "INSERT INTO {$this->table['authenticator_credentials']} 
                    (user_id, credential_id, credential_data, sign_count, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $userId,
                        $credentialId,
                        $credentialData,
                        $publicKeyCredentialSource->counter,
                        1,
                        $this->current_time()
                    ]
                );
            }

        } catch (Exception $e) {
            Error::log('saveCredentialSource', $e->getMessage());
            throw new Exception('خطا در ذخیره credential: ' . $e->getMessage());
        }
    }

    /**
     * بررسی rate limiting
     */
    private function check_rate_limit($identifier, $max_attempts = MAX_FAILED_ATTEMPTS, $window = LOCKOUT_TIME)
    {
        $key = 'rate_limit_' . $identifier;
        $attempts = (int) $this->redis->get($key) ?: 0;

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

            if (!in_array($params['type'], ['register', 'login'])) {
                Response::error('نوع درخواست نامعتبر است');
            }

            $phone = !empty($user) ? $user['phone'] : $params['phoneNumber'];

            if (!preg_match('/^09\d{9}$/', $phone)) {
                Response::error('شماره موبایل نامعتبر است');
            }

            $this->check_rate_limit('webauthn_challenge_' . $phone, 10, 300);

            $token = bin2hex(random_bytes(32));

            $userData = $user ?: $this->get_user_by_phone($phone);

            $challengeData = [
                'phone' => $phone,
                'created_at' => time(),
                'type' => $params['type'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];

            if ($userData) {
                $challengeData['user_id'] = $userData['id'];
            }

            if ($params['type'] === 'register') {
                if (!$userData) {
                    Response::error('کاربر یافت نشد');
                }

                $userEntity = PublicKeyCredentialUserEntity::create(
                    $userData['username'],
                    (string) $userData['id'],
                    $phone
                );

                $excludeCredentials = [];
                $existingCredentials = $this->findAllForUserEntity($userEntity);
                if (!empty($existingCredentials)) {
                    foreach ($existingCredentials as $credential) {
                        $excludeCredentials[] = $credential->getPublicKeyCredentialDescriptor();
                    }
                }

                $pubKeyCredParams = [
                    PublicKeyCredentialParameters::create('public-key', -7),
                    PublicKeyCredentialParameters::create('public-key', -257),
                    PublicKeyCredentialParameters::create('public-key', -8),
                ];

                $publicKeyCredentialCreationOptions = PublicKeyCredentialCreationOptions::create(
                    $this->rpEntity,
                    $userEntity,
                    random_bytes(32),
                    $pubKeyCredParams
                );

                if (!empty($excludeCredentials)) {
                    $publicKeyCredentialCreationOptions->excludeCredentials = $excludeCredentials;
                }

                $publicKeyCredentialCreationOptions->timeout = 120000;
                $publicKeyCredentialCreationOptions->attestation = PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE;

                $challengeData['challenge'] = base64_encode($publicKeyCredentialCreationOptions->challenge);
                $publicKeyCredentialCreationOptionsJSON = $this->serializer->serialize($publicKeyCredentialCreationOptions, 'json');
                $response = [
                    'creationOptions' => json_decode($publicKeyCredentialCreationOptionsJSON),
                    'token' => $token
                ];

            } else {
                $allowedCredentials = [];

                if ($userData) {
                    $userEntity = PublicKeyCredentialUserEntity::create(
                        $userData['username'] ?? $phone,
                        (string) $userData['id'],
                        $userData['phone'] ?? $phone
                    );
                    $existingCredentials = $this->findAllForUserEntity($userEntity);
                    if (!empty($existingCredentials)) {
                        foreach ($existingCredentials as $credential) {
                            $allowedCredentials[] = $credential->getPublicKeyCredentialDescriptor();
                        }
                    }
                }

                $publicKeyCredentialRequestOptions = PublicKeyCredentialRequestOptions::create(
                    random_bytes(32),
                    allowCredentials: $allowedCredentials
                );

                $publicKeyCredentialRequestOptions->rpId = $this->rpEntity->id;
                $publicKeyCredentialRequestOptions->timeout = 120000;

                $challengeData['challenge'] = base64_encode($publicKeyCredentialRequestOptions->challenge);
                $publicKeyCredentialRequestOptionsJSON = $this->serializer->serialize($publicKeyCredentialRequestOptions, 'json');
                $response = [
                    'requestOptions' => json_decode($publicKeyCredentialRequestOptionsJSON),
                    'token' => $token
                ];

            }

            if (!$this->redis->set('webauthn_challenge_' . $token, json_encode($challengeData), 120)) {
                Response::error('خطا در ذخیره چالش');
            }

            Response::success('چالش ساخته شد', 'challengeData', $response);
        } catch (Exception $e) {
            Error::log(e: $e);
            Response::error('خطا در ایجاد چالش: ' . $e->getMessage());
        }
    }

    /**
     * ثبت اثرانگشت جدید
     */
    public function register_new_fingerprint($params)
    {
        try {
            $user = $this->check_role();

            $this->check_params($params, ['challengeToken', 'credentialData', 'creationOptions']);

            $challenge_token = $params['challengeToken'];
            $credentialData = $params['credentialData'];

            $deviceName = $credentialData['deviceName'];

            $credentialJson = json_encode($credentialData);

            $this->check_rate_limit('fingerprint_register_' . $user['id'], 5, 300);

            $challenge_data = $this->redis->get('webauthn_challenge_' . $challenge_token);

            if (!$challenge_data) {
                Response::error('توکن نامعتبر یا منقضی شده است');
            }

            if ($challenge_data['type'] !== 'register') {
                Response::error('نوع چالش نامعتبر است');
            }

            if (!isset($challenge_data['user_id']) || $challenge_data['user_id'] != $user['id']) {
                Response::error('این چالش مربوط به شما نیست');
            }

            $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if ($challenge_data['ip'] !== $current_ip) {
                Error::log('webauthn_register', "IP mismatch: challenge={$challenge_data['ip']}, current=$current_ip");
            }

            if (time() - $challenge_data['created_at'] > 120) {
                Response::error('چالش منقضی شده است');
            }

            $publicKeyCredential = $this->serializer->deserialize(
                $credentialJson,
                PublicKeyCredential::class,
                'json'
            );

            $authenticatorAttestationResponse = $publicKeyCredential->response;

            if (!($authenticatorAttestationResponse instanceof \Webauthn\AuthenticatorAttestationResponse)) {
                Response::error('پاسخ نامعتبر است');
            }

            $creationOptions = $this->serializer->deserialize(
                json_encode($params['creationOptions']),
                PublicKeyCredentialCreationOptions::class,
                'json'
            );

            $publicKeyCredentialSource = $this->attestationResponseValidator->check(
                $authenticatorAttestationResponse,
                $creationOptions,
                $current_ip
            );

            $this->saveCredentialSource($publicKeyCredentialSource);

            $allowed_device_names = ['ویندوز', 'مک', 'اندروید', 'آیفون/آیپد', 'دستگاه ناشناخته'];
            $device_name = in_array($deviceName, $allowed_device_names)
                ? $deviceName
                : 'دستگاه ناشناخته';

            $device_type = $this->detect_device_type($_SERVER['HTTP_USER_AGENT'] ?? '');
            $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            $this->beginTransaction();

            try {
                $credentialId = base64_encode($publicKeyCredentialSource->publicKeyCredentialId);

                $update_data = $this->updateData(
                    "UPDATE {$this->table['authenticator_credentials']} 
                    SET device_name = ?, device_type = ?, user_agent = ?, 
                        aaguid = ?, attestation_format = ?
                    WHERE credential_id = ? AND user_id = ?",
                    [
                        $device_name,
                        $device_type,
                        $user_agent,
                        $publicKeyCredentialSource->aaguid ? $publicKeyCredentialSource->aaguid->toString() : null,
                        $publicKeyCredentialSource->attestationType,
                        $credentialId,
                        $user['id']
                    ]
                );

                if (!$update_data) {
                    throw new Exception('خطا در بروزرسانی اطلاعات');
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

                $this->redis->delete('webauthn_challenge_' . $challenge_token);

                Response::success('اثرانگشت با موفقیت ثبت شد');

            } catch (Exception $e) {
                $this->rollback();
                throw $e;
            }

        } catch (WebauthnException $e) {
            Error::log('WebAuthn Registration', $e->getMessage());
            Response::error('خطا در ثبت اثرانگشت: ' . $e->getMessage());
        } catch (Exception $e) {
            Error::log(e: $e);
            Response::error('خطا در ثبت اثرانگشت: ' . $e->getMessage());
        }
    }

    /**
     * تشخیص نوع دستگاه از User Agent
     */
    private function detect_device_type($user_agent)
    {
        $user_agent = strtolower($user_agent);

        if (strpos($user_agent, 'mobile') !== false) {
            return 'mobile';
        } elseif (strpos($user_agent, 'tablet') !== false) {
            return 'tablet';
        } else {
            return 'desktop';
        }
    }

    /**
     * ثبت تلاش ناموفق
     */
    private function log_failed_attempt($phone, $credential_id = null)
    {
        $key = 'failed_login_' . $phone;
        $attempts = (int) $this->redis->get($key) ?: 0;
        $attempts++;

        $this->redis->set($key, $attempts, self::LOCKOUT_TIME);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
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
        $attempts = (int) $this->redis->get($key) ?: 0;

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
            $this->check_params($params, ['challengeToken', 'credentialData', 'requestOptions']);

            $challenge_token = $params['challengeToken'];
            $credentialData = $params['credentialData'];
            $credentialJson = json_encode($credentialData);

            $challenge_data = $this->redis->get('webauthn_challenge_' . $challenge_token);

            if (!$challenge_data) {
                Response::error('توکن نامعتبر یا منقضی شده است');
            }

            if ($challenge_data['type'] !== 'login') {
                Response::error('نوع چالش نامعتبر است');
            }

            if (time() - $challenge_data['created_at'] > 120) {
                Response::error('چالش منقضی شده است');
            }

            $phone_number = $challenge_data['phone'];

            if (empty($phone_number) || !preg_match('/^09\d{9}$/', $phone_number)) {
                Response::error('شماره موبایل نامعتبر است');
            }

            $this->check_account_lockout($phone_number);

            $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if ($challenge_data['ip'] !== $current_ip) {
                Error::log('webauthn_login', "IP mismatch: challenge={$challenge_data['ip']}, current=$current_ip");
            }

            $user = $this->get_user_by_phone($phone_number);
            if (!$user) {
                $this->log_failed_attempt($phone_number);
                Response::error('کاربر یافت نشد');
            }

            $publicKeyCredential = $this->serializer->deserialize(
                $credentialJson,
                PublicKeyCredential::class,
                'json'
            );
            $authenticatorAssertionResponse = $publicKeyCredential->response;

            if (!($authenticatorAssertionResponse instanceof AuthenticatorAssertionResponse)) {
                Response::error('پاسخ نامعتبر است');
            }

            $requestOptions = $this->serializer->deserialize(
                json_encode($params['requestOptions']),
                PublicKeyCredentialRequestOptions::class,
                'json'
            );

            $publicKeyCredentialSource = $this->findOneByCredentialId($publicKeyCredential->rawId);

            $publicKeyCredentialSource = $this->assertionResponseValidator->check(
                $publicKeyCredentialSource,
                $authenticatorAssertionResponse,
                $requestOptions,
                $current_ip,
                $authenticatorAssertionResponse->userHandle
            );

            $this->saveCredentialSource($publicKeyCredentialSource);

            $this->beginTransaction();

            try {
                $credentialId = base64_encode($publicKeyCredentialSource->publicKeyCredentialId);

                $update_data = $this->updateData(
                    "UPDATE {$this->table['authenticator_credentials']} 
                    SET last_used_at = NOW() 
                    WHERE credential_id = ?",
                    [$credentialId]
                );

                if (!$update_data) {
                    throw new Exception('خطا در بروزرسانی اطلاعات');
                }

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

                $this->redis->delete('failed_login_' . $phone_number);

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

        } catch (WebauthnException $e) {
            Error::log(e: $e);
            $this->log_failed_attempt($phone_number ?? '');
            Response::error('خطا در ورود با اثرانگشت: ' . $e->getMessage());
        } catch (Exception $e) {
            Error::log(e: $e);
            $this->log_failed_attempt($phone_number ?? '');
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

            if (empty($credentialId) || !is_string($credentialId)) {
                Response::error('شناسه نامعتبر است');
            }

            $this->check_rate_limit('fingerprint_delete_' . $user['id'], 10, 300);

            $this->beginTransaction();

            try {
                $result = $this->updateData("
                    UPDATE {$this->table['authenticator_credentials']} 
                    SET is_active = 0, deleted_at = NOW() 
                    WHERE credential_id = ? AND user_id = ? AND is_active = 1
                ", [$credentialId, $user['id']]);

                if ($result) {
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
                       sign_count, last_used_at, created_at, aaguid, attestation_format
                FROM {$this->table['authenticator_credentials']} 
                WHERE user_id = ? AND is_active = 1
                ORDER BY created_at DESC
            ", [$user['id']], true);

            if ($fingerprints) {
                foreach ($fingerprints as &$fp) {
                    $fp['credential_id_masked'] = substr($fp['credential_id'], 0, 8) . '...';
                    if ($fp['aaguid']) {
                        $aaguid = $fp['aaguid'];
                        $fp['aaguid_formatted'] =
                            substr($aaguid, 0, 8) . '-' .
                            substr($aaguid, 8, 4) . '-' .
                            substr($aaguid, 12, 4) . '-' .
                            substr($aaguid, 16, 4) . '-' .
                            substr($aaguid, 20);
                    }
                }
            }

            Response::success('لیست اثرانگشت‌ها', 'fingerprints', $fingerprints ?: []);

        } catch (Exception $e) {
            Error::log(e: $e);
            Response::error('خطا در دریافت لیست اثرانگشت‌ها: ' . $e->getMessage());
        }
    }
}
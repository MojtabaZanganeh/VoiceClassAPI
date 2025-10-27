<?php
namespace Classes\Users;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
use Classes\Base\Response;
use Classes\Users\Users;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Authentication class handles user authentication processes including
 * sending verification codes, verifying codes, and JWT authentication.
 *
 * @package Classes\Base
 */
class Authentication extends Database
{
    use Base, Sanitizer;

    private $expires_time = 120;

    /**
     * Sends a code for authentication.
     *
     * This method generates and sends an authentication code to the user's phone number.
     * The code is stored in the database with the status "pending". 
     *
     * @param array $params Array of input parameters, including:
     *                      - string $params['phone'] User's phone number (required)
     *                      - string $params['page'] The page for which the code is requested (required)
     *                      - string $params['send'] Option to send the SMS or not (optional) Default: true
     * @return void
     */
    public function send_code($params)
    {
        $this->check_params($params, ['phone']);

        $phone = $this->check_input($params['phone'], 'phone', 'شماره همراه');
        $page = $params['page'] ?? 'Login';
        $send_sms = $_ENV['SEND_SMS'] === 'true';

        $sql = "SELECT * FROM {$this->table['otps']} WHERE `phone` = ? AND `is_used` = '0' AND `page` = ? ORDER BY expires_at DESC LIMIT 1";
        $result = $this->getData($sql, [$phone, $page]);

        if ($result && isset($result['expires_at']) && time() < $result['expires_at']) {
            Response::success('زمان باقی مانده است');
        }

        $rand_code = $send_sms ? $this->get_random('int', 5) : '11111';

        $expires_at = time() + $this->expires_time;

        $now = $this->current_time();

        $db = new Database();
        $db->beginTransaction();

        try {

            $sql = "INSERT INTO {$db->table['otps']} (`phone`, `code`, `expires_at`, `page`, `user_ip`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)";
            $execute = [
                $phone,
                $rand_code,
                $expires_at,
                $page,
                $this->get_user_ip(),
                $now
            ];

            $result = $db->insertData($sql, $execute);

            if ($result) {
                $send_result = $this->send_sms(
                    $phone,
                    $_ENV["SEND_CODE_TEMPLATE_ID"],
                    [['name' => 'code', 'value' => $rand_code]],
                    $send_sms
                );
                if ($send_result === true) {
                    $db->commit();
                    Response::success('کد تایید ارسال شد');
                } else {
                    throw new Exception('کد ارسال نشد');
                }
            } else {
                throw new Exception('کد در پایگاه داده وارد نشد');
            }
        } catch (Exception $e) {
            $db->rollback();
            Error::log("send-sms-$phone", ['message' => 'Failed to send sms', 'error_code' => $send_result ?? null]);
            Response::error($e ? $e->getMessage() : 'خطا در ارسال کد تایید');
        }
    }

    /**
     * Verifies the authentication code.
     *
     * This method checks the provided code against the stored code in the database.
     * If the code is valid and hasn't expired, it updates the verification status in the database.
     * 
     * @param array $params Array of input parameters, including:
     *                      - string $params['phone'] User's phone number (required)
     *                      - string $params['code'] The verification code received by the user (required)
     * @return void
     */
    public function verify_code($params)
    {
        $this->check_params($params, ['phone', 'code']);

        $phone = $this->check_input($params['phone'], 'phone', 'شماره همراه');
        $receive_code = $params['code'];
        $get_user_data = $params['user'] ?? null;
        $get_success_response = $params['get_response'] ?? null;
        $register_check = isset($params['register_check']) && $params['register_check'] === true ? '1' : '0';

        $sql = "SELECT * FROM {$this->table['otps']} WHERE phone = ? AND code = ? AND is_used = ? AND user_ip = ? ORDER BY expires_at DESC LIMIT 1";
        $execute = [
            $phone,
            $receive_code,
            $register_check,
            $this->get_user_ip()
        ];
        $row = $this->getData($sql, $execute);

        if ($row) {

            if (time() < $row['expires_at']) {
                $sql = "UPDATE {$this->table['otps']} SET `is_used` = '1' WHERE `phone` = ? AND  `code` = ?";
                $execute = [
                    $phone,
                    $receive_code
                ];
                $update = $this->updateData($sql, $execute);

                if (!$update) {
                    error_log('The code status was not saved in the database: TIME:' . time() . ' PHONE:' . $phone);
                }

                if ($get_user_data) {
                    $user_obj = new Users();
                    $user = $user_obj->get_user_by_phone($phone);

                    if ($user) {
                        $jwt_token = $this->generate_token([
                            'user_id' => $user['id'],
                            'phone' => $user['phone'],
                            'username' => $user['username'],
                            'role' => $user['role']
                        ]);
                        $user['token'] = $jwt_token;

                        if ($get_success_response) {
                            Response::success('کد صحیح است', 'user', $user);
                        }

                    } else {
                        Response::success('کد صحیح است اما کاربری با این شماره یافت نشد', 'user', null);
                    }

                }
                if ($get_success_response) {
                    Response::success('کد صحیح است');
                }
            } else {
                Response::error('کد منقضی شده است', null, 408);
            }
        } else {
            Response::error('کد اشتباه است');
        }
    }

    public function verify_phone($phone): bool
    {
        if (!$phone) {
            return false;
        }

        $sql = "SELECT * FROM {$this->table['otps']} WHERE phone = ? AND is_used = '1' AND user_ip = ? ORDER BY expires_at DESC LIMIT 1";
        $row = $this->getData($sql, [$phone, $this->get_user_ip()]);

        if ($row) {
            if (time() - $row['expires_at'] < 60) {
                return true;
            }
        }

        return false;
    }

    /**
     * 
     * 
     * @param mixed $params Array of input parameters, including:
     *                      - string | int $params['user_id'] User's id (required)
     *                      - string $params['phone'] User's phone number (required)
     *                      - string $params['role'] The verification code received by the user (required)
     * @return string
     */
    public function generate_token($params)
    {
        $this->check_params($params, ['user_id', 'phone', 'username', 'role']);

        $user_id = $params['user_id'];
        $phone = $params['phone'];
        $username = $params['username'];
        $role = $params['role'];

        $exp_day = $role === 'admin' ? 1 : 7;
        $time = $this->get_timestamp($exp_day);

        $payload = [
            'user_id' => $user_id,
            'phone' => $phone,
            'username' => $username,
            'role' => $role,
            'exp' => time() + $time,
        ];
        $jwt_token = JWT::encode($payload, $_ENV['JWT_SECRET_KEY'], 'HS256');

        return 'VCA09' . $jwt_token;
    }

    /**
     * Authenticates the user using a JWT token.
     *
     * This method decodes the JWT token and returns the decoded token payload as an object.
     * If the token is invalid, expired, or malformed, it returns `false`.
     *
     * @param string $token The JWT token sent by the user.
     * @return \stdClass|false Returns the decoded token payload as an object if the token is valid.
     *                         Returns `false` if the token is invalid, expired, or malformed.
     * @property int $user_id The ID of the authenticated user.
     * @property string $phone The phone number of the authenticated user.
     * @property string $roles The role assigned to the authenticated user.
     * @throws \Exception If an unexpected error occurs during token decoding.
     */
    public function check_token($token)
    {
        try {
            if (preg_match('/VCA09(\S+)/', $token, $matches) && isset($matches[1])) {
                $jwt_token = $matches[1];
                $token_decoded = JWT::decode($jwt_token, new Key($_ENV['JWT_SECRET_KEY'], 'HS256'));
                return $token_decoded;
            } else {
                throw new Exception();
            }
        } catch (Exception $e) {
            Response::error('نشست معتبر نیست');
        }
    }

    /**
     * CSRF Token Validation
     * @param string|null $headerToken X-CSRF-Token from Header
     * @param string|null $bodyToken CSRF-TOKEN from Body
     * @return bool Token is Valid?
     */
    public static function csrf_token_validation($bodyToken = null)
    {
        if (empty($headerToken) || empty($bodyToken)) {
            return false;
        }

        $headerParts = explode('.', $headerToken);
        $bodyParts = explode('.', $bodyToken);

        if (count($headerParts) !== 2 || count($bodyParts) !== 2) {
            return false;
        }

        [$headerPayload, $headerSignature] = $headerParts;
        [$bodyPayload, $bodySignature] = $bodyParts;

        if (!hash_equals($headerPayload, $bodyPayload)) {
            return false;
        }

        $payloadParts = explode('|', $headerPayload);
        if (count($payloadParts) !== 2) {
            return false;
        }

        [$random, $expiry] = $payloadParts;

        $currentTime = time();
        if ($expiry < $currentTime) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $headerPayload, $_ENV['CSRF_SECRET_KEY']);

        $isValidHeader = hash_equals($headerSignature, $expectedSignature);
        $isValidbody = hash_equals($bodySignature, $expectedSignature);

        return $isValidHeader && $isValidbody;
    }
}
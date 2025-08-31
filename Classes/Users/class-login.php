<?php
namespace Classes\Users;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Classes\Users\Users;

/**
 * Class Login
 *
 * Manages user registration, login, and user status checks.
 * It handles the logic for checking if a user is registered, registering a new user, 
 * and logging the user in by generating a JWT token.
 *
 * @package Classes\User
 */
class Login extends Users
{
    use Base, Sanitizer;

    public function check_user_registered($params) {
        $this->check_params($params, ['phone']);

        $phone = $params['phone'];

        $user_registered = $this->getData("SELECT id FROM {$this->table['users']} WHERE phone = ?", [$phone]);

        if ($user_registered) {
            Response::success('کاربر ثبت نام کرده است', 'registered', true);
        } else {
            Response::success('کاربر ثبت نام نکرده است', 'registered', false);
        }
    }

    /**
     * Registers a new user.
     *
     * This method registers a new user by inserting the user's details into the database.
     * It also generates a random password and assigns it to the new user.
     * If successful, it returns a success response with the user ID.
     *
     * @param array $params Array of input parameters, including:
     *                      - string $params['phone'] The phone number of the user (required)
     *                      - string $params['type'] The type of user (required)
     *                      - string $params['fname'] The first name of the user (required)
     *                      - string $params['category'] The category of the user (required)
     *                      - string $params['bname'] The business name (optional)
     * @return void
     */
    public function user_register($params)
    {
        $this->check_params($params, ['phone', 'password', 'code']);

        $phone = $this->check_input($params['phone'], 'phone', 'شماره همراه');

        if (mb_strlen($phone, 'UTF-8') !== 11 || !preg_match('/^09\d{9}$/', $phone)) {
            Response::error('شماره همراه صحیح نیست');
        }

        $password = $this->check_input($params['password'], 'password', 'رمز عبور');

        if (mb_strlen($password, 'UTF-8') < 8) {
            Response::error('رمز عبور کوتاه است');
        } elseif (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
            Response::error('رمز عبور باید شامل حداقل یک حرف بزرگ، یک عدد و یک نماد باشد');
        }

        $code = $params['code'];
        $username = "user_$phone";

        $this->verify_code(['phone' => $phone, 'code' => $code, 'response' => false]);

        $user_by_phone = $this->get_user_by_phone($phone);
        if ($user_by_phone) {
            Response::error('شماره قبلاً ثبت شده است.');
        }

        $now = $this->current_time();

        $sql = "INSERT INTO {$this->table['users']} (`username`, `phone`, `password`, `registered_at`) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $execute = [
            $username,
            $phone,
            password_hash($password, PASSWORD_DEFAULT),
            $now
        ];

        $user_id = $this->insertData($sql, $execute);

        if ($user_id) {
            $jwt_token = $this->generate_token([
                'user_id' => $user_id,
                'username' => $username,
                'phone' => $phone,
                'role' => 'user'
            ]);

            $user = [
                'id' => $user_id,
                'username' => $username,
                'phone' => $phone,
                'role' => 'user',
                'token' => $jwt_token
            ];

            Response::success('ثبت نام کاربر انجام شد', 'user', $user);
        } else {
            Response::error('ثبت نام کاربر انجام نشد');
        }
    }


    /**
     * Logs the user in and generates a JWT token.
     *
     * This method creates a JWT token for the user upon successful login. 
     * It also decides whether to set a short or long expiration time based on the "remember" option.
     *
     * @param array $params Array of input parameters, including:
     *                      - int $params['user_id'] The user ID to be logged in (required)
     *                      - bool $params['remember'] If set to true, the session expiration time will be extended (optional)
     * @return void
     */
    public function user_login($params)
    {
        $this->check_params($params, ['phone', ['code', 'password']]);

        $phone = $this->check_input($params['phone'], 'phone', 'شماره همراه');
        $password = $params['password'] ?? null;
        $code = $params['code'] ?? null;
        $remember = $params['remember'] ?? false;

        $user = $this->get_user_by_phone($phone);

        if ($code) {
            $this->verify_code(
                [
                    'phone' => $phone,
                    'code' => $code,
                    'user' => true,
                    'response' => false
                ]
            );
            if (!$user) {
                Response::error('کاربری با این شماره موبایل یافت نشد');
            }
        } else {
            if ($this->check_password($phone, $password) === false) {
                Response::error('شماره موبایل یا رمز عبور اشتباه است');
            }
        }

        $jwt_token = $this->generate_token([
            'user_id' => $user['id'],
            'phone' => $user['phone'],
            'username' => $user['username'],
            'role' => $user['role'],
            'time' => $remember ? 7 : 1
        ]);
        $user['token'] = $jwt_token;

        Response::success('ورود انجام شد', 'user', $user);
    }

    public function user_validate($params)
    {
        $this->check_params($params, ['token']);

        $token = $params['token'];

        $token_decoded = $this->check_token($token);

        $user = $this->get_user_by_phone($token_decoded->phone);

        if ($user) {
            if ($user['role'] == $token_decoded->role) {
                $user['token'] = $token;
                $user['avatar'] = isset($user['avatar']) ? $this->get_full_image_url($user['avatar']) : null;
                Response::success('نشست معتبر است', 'user', $user);
            }
        }

        Response::error('نشست معتبر نیست');
    }

    public function reset_password($params)
    {
        $this->check_params($params, ['phone', 'password']);

        $phone = $this->check_input($params['phone'], 'phone', 'شماره همراه');
        $password = $this->check_input($params['password'], 'password', 'رمز عبور');

        $verify_phone = $this->verify_phone($phone);

        if (!$verify_phone) {
            Response::error('شماره موبایل معتبر نیست');
        }

        $change_password = $this->updateData("UPDATE users SET password = ? WHERE phone = ?", [
            password_hash($password, PASSWORD_DEFAULT),
            $phone
        ]);

        if ($change_password) {
            Response::success('رمز عبور با موفقیت تغییر یافت');
        } else {
            Response::error('کاربری با این شماره موبایل یافت نشد');
        }
    }
}
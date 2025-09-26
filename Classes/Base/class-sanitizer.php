<?php
namespace Classes\Base;

use Classes\Base\Response;

/**
 * Sanitizer trait provides methods for sanitizing input values 
 * to prevent security risks such as XSS and SQL injection.
 * It includes functions for sanitizing individual values or arrays of values.
 *
 * @package Classes\Base
 */
trait Sanitizer
{
    public static function sanitizeInput($input)
    {
        if (is_array($input)) {
            $cleaned = [];

            foreach ($input as $key => $value) {
                $cleaned[$key] = self::sanitizeInput($value);
            }

            return $cleaned;
        } elseif (is_object($input)) {
            $cleaned = new \stdClass();

            foreach ($input as $key => $value) {
                $cleaned->$key = self::sanitizeInput($value);
            }

            return $cleaned;
        } else {
            return self::sanitizeValue($input);
        }
    }

    public static function sanitizeKey($key)
    {
        if (is_string($key)) {
            return trim(strip_tags($key));
        }

        return $key;
    }

    public static function sanitizeValue($value)
    {
        if (is_string($value)) {
            $sanitizedValue = trim($value);
            $sanitizedValue = htmlspecialchars($sanitizedValue, ENT_QUOTES, 'UTF-8');
            return $sanitizedValue;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (is_bool($value) || is_null($value)) {
            return $value;
        }

        return $value;
    }

    /**
     * Checks if all required parameters are present and not empty.
     *
     * This method ensures that all required parameters are passed and not empty. If any 
     * required parameter is missing or empty, it sends an error response with the details.
     *
     * @param array $params The parameters to be checked.
     * @param array $required_params The parameters has be required.
     * @return void
     */
    public static function check_params($params, $required_params)
    {
        if (!is_array($params) || !is_array($required_params)) {
            Response::error('خطا در بررسی داده ها');
        }

        foreach ($required_params as $key) {
            if (is_array($key)) {
                $found = false;
                foreach ($key as $option) {
                    if (!empty($params[$option])) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    Response::not_params(['At Least One Required' => $key, 'Received' => $params]);
                }
            } else {
                if (!isset($params[$key]) || (!is_bool($params[$key]) && $params[$key] != '0' && empty($params[$key]))) {
                    Response::not_params([$key => $params[$key] ?? null, 'Received' => $params]);
                }
            }
        }
    }

    public static function check_input($value, $type, $value_name, $regex = null)
    {

        if (!$type && $regex) {
            if (preg_match($regex, $value)) {
                return $value;
            }
        } else {
            switch ($type) {
                case 'phone':
                    if (preg_match('/^\d{11}$/', $value)) {
                        return $value;
                    }
                    break;

                case 'national_id':
                    $code = preg_replace('/[\s\-]/', '', $value);

                    if (preg_match('/^\d{10}$/', $code)) {

                        if (!preg_match('/^(\d)\1{9}$/', $code)) {

                            $sum = 0;
                            for ($i = 0; $i < 9; $i++) {
                                $sum += ((int) $code[$i]) * (10 - $i);
                            }

                            $remainder = $sum % 11;
                            $control = (int) $code[9];

                            if (
                                ($remainder < 2 && $control === $remainder) ||
                                ($remainder >= 2 && $control === (11 - $remainder))
                            ) {
                                return $value;
                            }
                        }
                    }
                    break;

                case 'postal_code':
                    $code = preg_replace('/[\s\-]/', '', $value); // حذف فاصله و خط تیره

                    if (
                        preg_match('/^\d{10}$/', $code) &&
                        !preg_match('/^(\d)\1{9}$/', $code)
                    ) {
                        return $value;
                    }
                    break;

                case 'password':
                    if (preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $value)) {
                        return $value;
                    }
                    break;

                case 'fa_name':
                    if (preg_match('/^[آ-ی\s]{2,25}$/u', $value)) {
                        return $value;
                    }
                    break;

                case 'fa_full_name':
                    if (preg_match('/^[آ-ی\s]{5,50}$/u', $value)) {
                        return $value;
                    }
                    break;

                case 'en_name':
                    if (preg_match('/^[a-zA-Z\s]{2,25}$/', $value)) {
                        return $value;
                    }
                    break;

                case 'fa_text':
                    if (preg_match('/^[آ-ی\s]+$/u', $value)) {
                        return $value;
                    }
                    break;

                case 'en_text':
                    if (preg_match('/^[a-zA-Z\s]+$/', $value)) {
                        return $value;
                    }
                    break;

                case 'HH:MM':
                    if (preg_match('/^(2[0-3]|[01]?[0-9]):[0-5][0-9]$/', $value)) {
                        return $value;
                    }
                    break;

                case 'YYYY/MM/DD':
                    if (preg_match('/^\d{4}\/(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])$/', $value)) {
                        return $value;
                    }
                    break;

                case 'int':
                    if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                        return (int) $value;
                    }
                    break;

                case 'positive_int':
                    if (filter_var($value, FILTER_VALIDATE_INT) !== false && $value > 0) {
                        return (int) $value;
                    }
                    break;

                case 'float':
                    if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
                        return (float) $value;
                    }
                    break;

                case 'email':
                    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        return $value;
                    }
                    break;

                case 'url':
                case 'link':
                    if (filter_var($value, FILTER_VALIDATE_URL)) {
                        return $value;
                    }
                    break;

                case 'boolean':
                    if (is_bool($value)) {
                        return $value;
                    }
                    break;

                case 'array':
                    if (is_array($value)) {
                        return $value;
                    }
                    break;

                default:
                    break;
            }
        }

        Response::error("مقدار وارد شده برای $value_name معتبر نیست");
    }

    public static function check_input_length($value, $value_name, $min, $max)
    {
        $value_length = mb_strlen($value, 'UTF-8');

        if ($value_length >= $min && $value_length <= $max) {
            return $value;
        }

        Response::error("طول $value_name باید بین $min و $max کاراکتر باشد");
    }

}
<?php
namespace Classes\Base;

/**
 * Response class to handle HTTP responses.
 *
 * This class provides static methods to send structured success and error responses 
 * in JSON format, as well as methods for handling required parameters.
 *
 * @package Classes\Base
 */
class Response
{

    /**
     * @var array The data to be included in the response.
     */
    private $data = [];

    /**
     * @var int The HTTP status code for the response.
     */
    private $httpCode = 200;

    /**
     * Sends a success response with a message and optional data.
     *
     * @param string $message The message to send with the response.
     * @param mixed $data Optional data to include in the response.
     * @param int $code HTTP status code for the response. Default is 200 (OK).
     * @return never This method does not return any value, it will stop execution and send the response.
     */
    public static function success(string $message, $data_name = null, $data = null, int $code = 200): never
    {
        $response = [
            'success' => true,
            'message' => $message
        ];

        if ($data_name !== null && $data !== null) {
            $response[$data_name] = $data;
        }

        self::send($response, $code);
    }

    /**
     * Sends an error response with a message and optional error details.
     *
     * @param string $message The error message to send with the response.
     * @param mixed $errors Optional errors or details about the error.
     * @param int $code HTTP status code for the response. Default is 400 (Bad Request).
     * @return never This method does not return any value, it will stop execution and send the response.
     */
    public static function error(string $message, $errors = null, int $code = 400): never
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        self::send($response, $code);
    }

    /**
     * Sends a response indicating missing required parameters.
     *
     * @param array|null $params Optional list of missing parameters.
     * @return never This method does not return any value, it will stop execution and send the response.
     */
    public static function not_params($params = null): never
    {
        $response = [
            'success' => false,
            'message' => 'اطلاعات به صورت کامل ارسال نشده است'
        ];

        if ($params !== null) {
            $response['params'] = $params;
        }

        self::send($response, 400);

    }

    /**
     * Sends the response in JSON format.
     *
     * @param array $data The response data to send.
     * @param int $code The HTTP status code for the response.
     * @return never This method does not return any value, it will stop execution and send the response.
     */
    private static function send(array $data, int $code): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

}
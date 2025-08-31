<?php
namespace Classes\Base;

use Classes\Base\Response;

class Error
{
    private $log_file;

    public function __construct()
    {
        $this->log_file = 'Logs/log.txt';
        set_error_handler([$this, 'error_handler']);
        set_exception_handler([$this, 'exception_handler']);
    }

    private function format_message(string $type, int $code, string $message, string $file, int $line)
    {
        date_default_timezone_set('Asia/Tehran');
        $date = new \DateTime();
        $log = "---------------------------------------------\n" .
            'Type: ' . $type . "\n" .
            'Date: ' . $date->format('Y/m/d -- H:i:s') . "\n" .
            'Code: ' . $code . "\n" .
            'Message: ' . $message . "\n" .
            'File: ' . $file . "\n" .
            'Line: ' . $line . "\n";

        return $log;
    }

    private function write_log($log)
    {
        file_put_contents($this->log_file, $log, FILE_APPEND);
    }

    private function throw_log($message)
    {
        Response::error($message);
    }

    public function error_handler(int $code, string $message, string $file, int $line)
    {
        $error = $this->format_message('Error', $code, $message, $file, $line);
        $this->write_log($error);
        $this->throw_log($message);
    }

    public function exception_handler(\Throwable $e)
    {
        $exception = $this->format_message('Exception', $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine());
        $this->write_log($exception);
        $this->throw_log($e->getMessage());
    }

    public static function log($file_name, $log, $file_type='json')
    {
        if ($file_type === 'json') {
            file_put_contents("Logs/$file_name.json", json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } elseif ($file_type === 'txt') {
            file_put_contents("Logs/$file_name.txt", $log);
        }
    }
}
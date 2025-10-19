<?php
use Classes\Base\Database;
use Classes\Support\Emails;

if (!isset($db) || !$db instanceof Database) {
    error_log("Cron finish called without valid database instance");
    exit;
}

$date_time = jdate('Y-m-d H:i:s', '', '', 'Asia/Tehran', 'en');

try {
    $db->commit();

    $cron_name = $log_file ? ucwords(str_replace(['-', '.log'], '', $log_file)) : 'Unknown Cron';
    $log_file ??= 'cron.log';
    $log_message ??= 'Cron completed';
    $start_time ??= time();

    file_put_contents(
        __DIR__ . "/../Logs/{$log_file}",
        "[{$date_time}] {$log_message} in " . (time() - $start_time) . " seconds\n",
        FILE_APPEND
    );

    $notifier = new Emails();
    $notifier->send_success_cron_email(
        $cron_name,
        time() - $start_time,
        $log_file,
        $log_message,
        $_ENV['TECHNICAL_SUPPORT_MAIL'],
        'پشتیبانی فنی'
    );
} catch (Exception $e) {
    $db->rollback();

    $log_file ??= 'cron.log';
    $error_message = "[{$date_time}] خطا در اجرای کرون: " . $e->getMessage();

    file_put_contents(
        __DIR__ . "/../Logs/{$log_file}",
        $error_message . "\n",
        FILE_APPEND
    );

    $notifier = new Emails();
    $notifier->send_error_cron_email(
        $cron_name ?? 'unknown_cron',
        time() - ($start_time ?? time()),
        $log_file,
        $error_message,
        $_ENV['TECHINICAL_SUPPORT_MAIL'],
        'پشتیبانی فنی',
        $e->getTraceAsString()
    );

    error_log($error_message);
    exit;
}
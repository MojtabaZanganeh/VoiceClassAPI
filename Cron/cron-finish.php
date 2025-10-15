<?php
use Classes\Base\Database;

if (!isset($db) || !$db instanceof Database) {
    error_log("Cron finish called without valid database instance");
    exit;
}

$date_time = jdate('Y-m-d H:i:s', '', '', 'Asia/Tehran', 'en');

try {
    $db->commit();

    $log_file ??= 'cron.log';
    $log_message ??= 'Cron completed';

    file_put_contents(
        __DIR__ . "/../Logs/{$log_file}",
        "[{$date_time}] {$log_message} in " . (time() - $start_time) . " seconds\n",
        FILE_APPEND
    );
} catch (Exception $e) {
    $db->rollback();
    error_log("[{$date_time}] " . $e->getMessage());
    exit;
}
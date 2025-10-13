<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../load-classes.php';
require_once __DIR__ . '/../jdf.php';

use Classes\Base\Database;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

ini_set('max_execution_time', 0);

$time = jdate("H:i", '', '', 'Asia/Tehran', 'en');

$start_time = time();
$db = new Database();
$db->beginTransaction();

return [
    'db' => $db,
    'time' => $time,
    'cron_times' => [
        'clear-expired-transactions' => '00:00',
        'update-products-statistics' => '00:00',
        'update-instructors-statistics' => '00:00'
    ],
    'start_time' => $start_time
];

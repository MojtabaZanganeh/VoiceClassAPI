<?php

$init = require __DIR__ . '/cron-runner.php';
$db = $init['db'];
$time = $init['time'];
$start_time = $init['start_time'];

if ($time !== '00:10') 
    exit;

try {
    $products = $db->getData("
        SELECT 
            oi.product_id,
            COUNT(oi.id) AS total_students
        FROM {$db->table['order_items']} oi
        WHERE oi.status IN ('completed', 'sending')
        GROUP BY oi.product_id
    ", [], true);

    if ($products && count($products) > 0) {
        foreach ($products as $row) {
            $db->updateData(
                "UPDATE {$db->table['products']} SET students = ? WHERE id = ?",
                [$row['total_students'], $row['product_id']]
            );
        }
    }

    $update_students = $db->updateData("
        UPDATE {$db->table['products']}
        SET students = 0
        WHERE id NOT IN (
            SELECT DISTINCT product_id
            FROM {$db->table['order_items']}
            WHERE status IN ('completed', 'sending')
        )
    ", []);

    if (!$update_students) {
        throw new Exception('Failed to update product students');
    }

    $ratings = $db->getData("
        SELECT 
            r.product_id,
            ROUND(AVG(r.rating), 2) AS avg_rating,
            COUNT(r.id) AS total_ratings
        FROM {$db->table['reviews']} r
        GROUP BY r.product_id
    ", [], true);

    if ($ratings && count($ratings) > 0) {
        foreach ($ratings as $rate) {
            $db->updateData(
                "UPDATE {$db->table['products']} 
                 SET rating_avg = ?, rating_count = ?
                 WHERE id = ?",
                [$rate['avg_rating'], $rate['total_ratings'], $rate['product_id']]
            );
        }
    }

    $update_rating = $db->updateData("
        UPDATE {$db->table['products']}
        SET rating_avg = 0, rating_count = 0
        WHERE id NOT IN (
            SELECT DISTINCT product_id FROM {$db->table['reviews']}
        )
    ", []);

    if (!$update_rating) {
        throw new Exception('Failed to update product ratings');
    }
    
    $log_file = 'get_product_stats.log';
    $log_message = 'Product stats updated successfully';

} catch (Exception $e) {
    $db->rollback();
    error_log("[" . jdate('Y-m-d H:i:s') . "] " . $e->getMessage());
    exit;
}

require __DIR__ . '/cron-finish.php';
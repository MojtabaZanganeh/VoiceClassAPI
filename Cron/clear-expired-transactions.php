<?php

$init = require __DIR__ . '/cron-runner.php';
$db = $init['db'];
$time = $init['time'];
$start_time = $init['start_time'];
$log_file = 'get_product_stats.log';

if ($time !== '00:00')
    exit;

try {
    $expired_transactions = $db->getData(
        "SELECT id FROM {$db->table['transactions']}
                WHERE `status` = 'pending-pay'
                AND updated_at < (NOW() - INTERVAL 24 HOUR)
            ",
        [],
        true
    );

    if (!$expired_transactions) {
        $log_message = 'No expired orders';
        require __DIR__ . '/cron-finish.php';
        exit;
    }

    foreach ($expired_transactions as $transaction) {
        $update_transaction = $db->updateData(
            "UPDATE {$db->table['transactions']} SET `status` = ? WHERE id = ?",
            [
                'canceled',
                $transaction['id']
            ]
        );

        if (!$update_transaction) {
            throw new Exception('Failed to change transaction status');
        }

        $order = $db->getData(
            "SELECT order_id FROM {$db->table['transactions']} WHERE id = ?",
            [
                $transaction['id']
            ]
        );

        if (!$order) {
            throw new Exception('Failed to get transaction order');
        }

        $items = $db->getData(
            "SELECT id, product_id, access_type, price, `status` FROM {$db->table['order_items']} WHERE order_id = ?",
            [$order['order_id']],
            true
        );

        if (!$items) {
            throw new Exception('Not found item for order');
        }

        foreach ($items as $item) {
            $update_item_status = $db->updateData(
                "UPDATE {$db->table['order_items']} SET `status` = ? WHERE id = ?",
                ['canceled', $item['id']]
            );

            if ($update_item_status === false) {
                throw new Exception('Failed to change order item status');
            }
        }
    }

    $log_file = 'get_product_stats.log';
    $log_message = 'Product stats updated successfully';

} catch (Exception $e) {
    $db->rollback();
    error_log("[" . jdate('Y-m-d H:i:s') . "] " . $e->getMessage());
    exit;
}

require __DIR__ . '/cron-finish.php';
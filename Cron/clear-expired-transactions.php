<?php
require_once __DIR__ . '/cron-require.php';

use Classes\Base\Cron;

class ClearExpiredTransactionsCron extends Cron
{
    protected function run(): void
    {
        if ($this->currentTime !== $this->cronTimes['clear-expired-transactions']) {
            self::log($this->logFile, "Skipped: time mismatch (current={$this->currentTime}, expected={$this->cronTimes['clear-expired-transactions']})");
            return;
        }

        $this->logMessage = 'Clear expired transactions successfully';

        $expired = $this->db->getData("
            SELECT id FROM {$this->db->table['transactions']}
            WHERE `status` = 'pending-pay'
            AND updated_at < (NOW() - INTERVAL 24 HOUR)
        ", [], true);

        if (!$expired) {
            $this->logMessage = 'No expired orders';
            return;
        }

        foreach ($expired as $transaction) {
            $ok = $this->db->updateData(
                "UPDATE {$this->db->table['transactions']} SET `status` = ? WHERE id = ?",
                ['canceled', $transaction['id']]
            );
            if (!$ok) {
                throw new \Exception('Failed to change transaction status');
            }

            $order = $this->db->getData(
                "SELECT order_id FROM {$this->db->table['transactions']} WHERE id = ?",
                [$transaction['id']]
            );
            if (!$order) {
                throw new \Exception('Failed to get transaction order');
            }

            $items = $this->db->getData(
                "SELECT id FROM {$this->db->table['order_items']} WHERE order_id = ?",
                [$order['order_id']],
                true
            );
            foreach ($items ?? [] as $item) {
                $ok = $this->db->updateData(
                    "UPDATE {$this->db->table['order_items']} SET `status` = ? WHERE id = ?",
                    ['canceled', $item['id']]
                );
                if ($ok === false) {
                    throw new \Exception('Failed to change order item status');
                }
            }
        }
    }
}

$cron = new ClearExpiredTransactionsCron('clear-expired-transactions.log', 'حذف تراکنش‌های منقضی شده');
$cron->execute();

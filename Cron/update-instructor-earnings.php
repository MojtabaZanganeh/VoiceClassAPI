<?php
require_once __DIR__ . '/cron-require.php';

use Classes\Base\Cron;

class UpdateInstructorEarningsCron extends Cron
{
    protected function run(): void
    {
        // if ($this->currentTime !== $this->cronTimes['update-instructor-earnings']) {
        //     self::log($this->logFile, "Skipped: time mismatch (current={$this->currentTime}, expected={$this->cronTimes['update-instructor-earnings']})");
        //     return;
        // }

        $this->logMessage = 'Instructor earnings updated successfully';

        $newItems = $this->db->getData("
            SELECT oi.id AS order_item_id, p.instructor_id, oi.price, i.share_percent
            FROM {$this->db->table['order_items']} oi
            INNER JOIN {$this->db->table['products']} p ON p.id = oi.product_id
            INNER JOIN {$this->db->table['instructors']} i ON i.id = p.instructor_id
            LEFT JOIN {$this->db->table['instructor_earnings']} ie ON ie.order_item_id = oi.id
            WHERE (oi.status = 'completed' OR oi.status = 'sending' OR oi.status = 'pending-review') AND ie.id IS NULL
        ", [], true) ?? [];

        foreach ($newItems as $item) {
            $amount = (int) floor($item['price'] * $item['share_percent'] / 100);
            $commission = (int) ($item['price'] - $amount);

            $ok = $this->db->insertData("
                INSERT INTO {$this->db->table['instructor_earnings']}
                (uuid, instructor_id, order_item_id, amount, site_commission, total_price)
                VALUES (UUID(), ?, ?, ?, ?, ?)
            ", [
                $item['instructor_id'],
                $item['order_item_id'],
                $amount,
                $commission,
                $item['price']
            ]);

            if (!$ok) {
                throw new Exception("Failed to insert earning for order_item_id {$item['order_item_id']}");
            }
        }

        $canceledItems = $this->db->getData("
            SELECT ie.id
            FROM {$this->db->table['instructor_earnings']} ie
            INNER JOIN {$this->db->table['order_items']} oi ON oi.id = ie.order_item_id
            WHERE (oi.status != 'completed' AND oi.status != 'sending' AND oi.status != 'pending-review') AND ie.status != 'paid'
        ", [], true) ?? [];

        foreach ($canceledItems as $row) {
            $ok = $this->db->updateData("
                UPDATE {$this->db->table['instructor_earnings']}
                SET status = 'canceled'
                WHERE id = ?
            ", [$row['id']]);

            if (!$ok) {
                throw new Exception("Failed to cancel earning ID {$row['id']}");
            }
        }

        $instructors = $this->db->getData("
            SELECT id FROM {$this->db->table['instructors']}
        ", [], true);

        if (!$instructors) {
            throw new Exception('Failed to get instructors');
        }

        foreach ($instructors as $inst) {
            $id = $inst['id'];

            $totals = $this->db->getData("
                SELECT 
                    SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS total_paid,
                    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS total_unpaid
                FROM {$this->db->table['instructor_earnings']}
                WHERE instructor_id = ?
            ", [$id], false);

            $ok = $this->db->updateData("
                UPDATE {$this->db->table['instructors']}
                SET total_earnings = ?, unpaid_earnings = ?
                WHERE id = ?
            ", [
                (int)($totals['total_paid'] ?? 0),
                (int)($totals['total_unpaid'] ?? 0),
                $id
            ]);

            if (!$ok) {
                throw new Exception("Failed to update earnings for instructor ID {$id}");
            }
        }
    }
}

$cron = new UpdateInstructorEarningsCron('update-instructors-earnings.log', 'بروزرسانی درآمد مدرسین');
$cron->execute();

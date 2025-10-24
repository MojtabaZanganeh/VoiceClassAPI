<?php
require_once __DIR__ . '/cron-require.php';

use Classes\Base\Cron;

class UpdateProductStatsCron extends Cron
{
    protected function run(): void
    {
        if ($this->currentTime !== $this->cronTimes['update-products-statistics']) {
            self::log($this->logFile, "Skipped: time mismatch (current={$this->currentTime}, expected={$this->cronTimes['update-products-statistics']})");
            return;
        }

        $this->logMessage = 'Product stats updated successfully';

        $products = $this->db->getData("
            SELECT 
                oi.product_id,
                COUNT(oi.id) AS total_students
            FROM {$this->db->table['order_items']} oi
            WHERE oi.status IN ('completed', 'sending', 'pending-review')
            GROUP BY oi.product_id
        ", [], true) ?? [];

        foreach ($products as $row) {
            $this->db->updateData(
                "UPDATE {$this->db->table['products']} SET students = ? WHERE id = ?",
                [(int) $row['total_students'], (int) $row['product_id']]
            );
        }

        $okStudents = $this->db->updateData("
            UPDATE {$this->db->table['products']}
            SET students = 0
            WHERE id NOT IN (
                SELECT DISTINCT product_id
                FROM {$this->db->table['order_items']}
                WHERE status IN ('completed', 'sending')
            )
        ", []);
        if (!$okStudents) {
            throw new Exception('Failed to update product students');
        }

        $ratings = $this->db->getData("
            SELECT 
                r.product_id,
                ROUND(AVG(r.rating), 2) AS avg_rating,
                COUNT(r.id) AS total_ratings
            FROM {$this->db->table['reviews']} r
            GROUP BY r.product_id
        ", [], true) ?? [];

        foreach ($ratings as $rate) {
            $this->db->updateData(
                "UPDATE {$this->db->table['products']} 
                 SET rating_avg = ?, rating_count = ?
                 WHERE id = ?",
                [(float) $rate['avg_rating'], (int) $rate['total_ratings'], (int) $rate['product_id']]
            );
        }

        $okRatings = $this->db->updateData("
            UPDATE {$this->db->table['products']}
            SET rating_avg = 0, rating_count = 0
            WHERE id NOT IN (
                SELECT DISTINCT product_id FROM {$this->db->table['reviews']}
            )
        ", []);
        if (!$okRatings) {
            throw new Exception('Failed to update product ratings');
        }
    }
}

$cron = new UpdateProductStatsCron('update-products-statistics.log', 'بروزرسانی آمار محصولات');
$cron->execute();
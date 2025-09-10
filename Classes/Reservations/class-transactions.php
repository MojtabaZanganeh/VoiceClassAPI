<?php
namespace Classes\Reservations;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Reservations\Reservations;

class Transactions extends Reservations
{
    use Base, Sanitizer;

    public function get_user_transactions()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    JSON_OBJECT(
                        'title', p.title,
                        'slug', p.slug,
                        'type', p.type
                    ) AS `product`,
                    t.amount,
                    t.status,
                    t.authority,
                    t.card_hash,
                    t.card_pan,
                    t.ref_id,
                    t.paid_at,
                    t.created_at,
                    t.updated_at
                FROM {$this->table['transactions']} t
                LEFT JOIN  {$this->table['reservations']} r ON t.reservation_id = r.id
                LEFT JOIN  {$this->table['products']} p ON r.product_id = p.id
            WHERE r.user_id = ? AND t.amount > 0";

        $transactions = $this->getData($sql, [$user['id']], true);

        if (!$transactions) {
            Response::success('تراکنشی یافت نشد');
        }

        foreach ($transactions as &$transaction) {
            $transaction['product'] = json_decode($transaction['product'], true);
        }

        Response::success('تراکنش های شما دریافت شد', 'userTransactions', $transactions);
    }
}
<?php
namespace Classes\Orders;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Orders\Orders;

class Transactions extends Orders
{
    use Base, Sanitizer;

    public function get_user_transactions()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    JSON_OBJECT(
                        'id', o.id,
                        'code', o.code,
                        'total_amount', o.total_amount,
                        'discount_amount', o.discount_amount
                    ) AS `order`,
                    t.id AS transaction_id,
                    t.amount,
                    t.status,
                    t.ref_id,
                    t.paid_at,
                    t.created_at,
                    t.updated_at,
                    CONCAT('[', GROUP_CONCAT(
                        JSON_OBJECT(
                            'id', p.id,
                            'title', p.title,
                            'slug', p.slug,
                            'type', p.type,
                            'quantity', oi.quantity,
                            'price', oi.price
                        )
                    ), ']') AS products
                FROM transactions t
                LEFT JOIN orders o ON t.order_id = o.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE o.user_id = ?
                GROUP BY o.id, t.id
                ORDER BY o.id;
        ";

        $transactions = $this->getData($sql, [$user['id']], true);

        if (!$transactions) {
            Response::success('تراکنشی یافت نشد');
        }

        foreach ($transactions as &$transaction) {
            $transaction['order'] = json_decode($transaction['order'], true);
            $transaction['products'] = json_decode($transaction['products'], true);
        }

        Response::success('تراکنش های شما دریافت شد', 'userTransactions', $transactions);
    }
}
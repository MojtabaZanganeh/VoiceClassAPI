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

    public function card_pay_order($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['method', 'order_id']);

        $method = $params['method'];
        $order_uuid = $params['order_id'];

        if ($params['method'] === 'receipt') {
            $this->check_params($params, ['file_name', 'file_type', 'file_size']);

            if ($params['file_size'] > 204800) {
                Response::error('حداکثر اندازه مجاز ۲۰۰ کیلوبایت است');
            }

            $upload_dir = 'Uploads/Receipts/';
            $uuid = $this->generate_uuid();
            $receipt_url = (isset($_FILES['receipt']) && $_FILES['receipt']['size'] > 0) ? $this->handle_file_upload($_FILES['receipt'], $upload_dir, $uuid) : null;

            if (!$receipt_url) {
                Response::error('خطا در ذخیره تصویر رسید');
            }
        } else {
            $this->check_params($params, ['lastFourDigits', 'referenceNumber']);

            $card_pan = $params['lastFourDigits'];
            $ref_id = $params['referenceNumber'];
        }

        $order = $this->getData(
            "SELECT * FROM {$this->table['orders']} WHERE uuid = ? AND `status` = 'pending-pay'",
            [$order_uuid]
        );

        if (!$order) {
            Response::error('سفارش یافت نشد');
        }

        $current_time = $this->current_time();

        $update_transaction = $this->updateData(
            "UPDATE {$this->table['transactions']} SET `status` = ?, card_pan = ?, ref_id = ?, receipt = ?, updated_at = ?, paid_at = ? WHERE order_id = ?",
            [
                'need-approval',
                $card_pan ?? null,
                $ref_id ?? null,
                $receipt_url ?? null,
                $current_time,
                $current_time,
                $order['id']
            ]
        );

        if (!$update_transaction) {
            Response::error('خطا در ثبت اطلاعات پرداخت');
        }

        $update_order = $this->updateData(
            "UPDATE {$this->table['orders']} SET `status` = ?, updated_at = ? WHERE id = ?",
            [
                'need-approval',
                $current_time,
                $order['id']
            ]
        );

        if (!$update_order) {
            Response::error('خطا در تغییر وضعیت سفارش');
        }

        Response::success('اطلاعات پرداخت ثبت شد');
    }

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
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
                        'discount_amount', o.discount_amount,
                        'products', CONCAT('[', GROUP_CONCAT(
                                        JSON_OBJECT(
                                            'id', p.id,
                                            'title', p.title,
                                            'slug', p.slug,
                                            'type', p.type,
                                            'quantity', oi.quantity,
                                            'price', oi.price,
                                            'access_type', oi.access_type
                                        )
                                    ), ']')
                    ) AS `order`,
                    t.uuid,
                    t.type,
                    t.amount,
                    t.status,
                    t.card_pan,
                    t.ref_id,
                    t.receipt,
                    t.paid_at,
                    t.created_at,
                    t.updated_at
                FROM transactions t
                LEFT JOIN orders o ON t.order_id = o.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN products p ON oi.product_id = p.id
                LEFT JOIN {$this->table['users']} u ON o.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id
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
            $transaction['order']['products'] = json_decode($transaction['order']['products'], true);
            $transaction['receipt'] = $transaction['receipt'] ? $this->get_full_image_url($transaction['receipt']) : null;
        }

        Response::success('تراکنش های شما دریافت شد', 'userTransactions', $transactions);
    }

    public function get_all_transactions($params)
    {
        $admin = $this->check_role(['admin']);

        $statsSql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending-pay' THEN 1 ELSE 0 END) as pending_pay,
                        SUM(CASE WHEN status = 'need-approval' THEN 1 ELSE 0 END) as need_approval,
                        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                        SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as canceled
                    FROM {$this->table['transactions']}";

        $stats = $this->getData($statsSql, []);

        $where_condition = '';
        $bind_params = [];

        if (!empty($params['status'])) {
            $where_condition .= ' WHERE t.status = ? ';
            $bind_params[] = $params['status'];
        }

        if (!empty($params['q'])) {
            $query = $params['q'];
            $condition = '(u.phone LIKE ? OR CONCAT(up.first_name_fa, " ", up.last_name_fa) LIKE ?)';
            if ($where_condition === '') {
                $where_condition .= " WHERE $condition";
            } else {
                $where_condition .= " AND $condition";
            }
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
        }

        $current_page = isset($params['current_page']) ? max(((int) $params['current_page'] - 1), 0) : 0;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 50)
            ? (int) $params['per_page_count']
            : 5;

        $offset = $current_page * $per_page_count;

        $idsSql = "SELECT t.id
                FROM {$this->table['transactions']} t
                LEFT JOIN {$this->table['orders']} o ON t.order_id = o.id
                LEFT JOIN {$this->table['users']} u ON o.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id
                $where_condition
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?";

        $bind_params_ids = array_merge($bind_params, [$per_page_count, $offset]);

        $transactionIds = $this->getData($idsSql, $bind_params_ids, true);

        if (!$transactionIds) {
            Response::success('تراکنشی یافت نشد', 'transactionsData', [
                'transactions' => [],
                'stats' => $stats
            ]);
        }

        $ids = array_column($transactionIds, 'id');
        $in_placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT
                JSON_OBJECT(
                    'id', o.id,
                    'code', o.code,
                    'user_full_name',
                        CASE
                            WHEN up.first_name_fa IS NULL OR up.first_name_fa = '' 
                                THEN u.phone
                            ELSE CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                        END,
                    'total_amount', o.total_amount,
                    'discount_amount', o.discount_amount,
                    'products', CONCAT('[', GROUP_CONCAT(
                                    JSON_OBJECT(
                                        'id', p.id,
                                        'title', p.title,
                                        'slug', p.slug,
                                        'type', p.type,
                                        'quantity', oi.quantity,
                                        'price', oi.price,
                                        'access_type', oi.access_type
                                    )
                                ), ']')
                ) AS `order`,
                t.uuid,
                t.type,
                t.amount,
                t.status,
                t.card_pan,
                t.ref_id,
                t.receipt,
                t.paid_at,
                t.created_at,
                t.updated_at
            FROM {$this->table['transactions']} t
            LEFT JOIN {$this->table['orders']} o ON t.order_id = o.id
            LEFT JOIN {$this->table['order_items']} oi ON o.id = oi.order_id
            LEFT JOIN {$this->table['products']} p ON oi.product_id = p.id
            LEFT JOIN {$this->table['users']} u ON o.user_id = u.id
            LEFT JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id
            WHERE t.id IN ($in_placeholders)
            GROUP BY t.id
            ORDER BY t.created_at DESC";

        $transactions = $this->getData($sql, $ids, true);

        foreach ($transactions as &$transaction) {
            $transaction['order'] = json_decode($transaction['order'], true);
            $transaction['order']['products'] = json_decode($transaction['order']['products'], true);
            $transaction['receipt'] = $transaction['receipt'] ? $this->get_full_image_url($transaction['receipt']) : null;
        }

        Response::success('تراکنش های شما دریافت شد', 'transactionsData', [
            'transactions' => $transactions,
            'stats' => $stats
        ]);
    }


    public function admin_action($params)
    {
        $admin = $this->check_role(['admin']);

        $this->check_params($params, ['transaction_id', 'action']);

        $new_status = $params['action'] === 'approve' ? 'paid' : 'rejected';

        $update_transaction = $this->updateData(
            "UPDATE {$this->table['transactions']} SET `status`=? WHERE uuid = ?",
            [
                $new_status,
                $params['transaction_id']
            ]
        );

        if (!$update_transaction) {
            Response::error('خطا در تغییر وضعیت تراکنش');
        }

        Response::success('وضعیت تراکنش به روز شد');
    }
}
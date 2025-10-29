<?php
namespace Classes\Orders;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Orders\Orders;
use Exception;

class Transactions extends Orders
{
    use Base, Sanitizer;

    public function card_pay_order($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['method', 'order_id']);

        $method = $params['method'];
        $order_uuid = $params['order_id'];

        $attachments = [];
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

            $attachments = [$_FILES['receipt']['name'] => "/../../$receipt_url"];
        } else {
            $this->check_params($params, ['lastFourDigits', 'referenceNumber']);

            $card_pan = $params['lastFourDigits'];
            $ref_id = $params['referenceNumber'];
        }

        $order = $this->getData(
            "SELECT 
                    o.*, 
                    u.phone, 
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS buyer_name 
                FROM {$this->table['orders']} o  
                INNER JOIN {$this->table['users']} u ON o.user_id = u.id  
                LEFT JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id  
                WHERE o.uuid = ?",
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

        $purchase_time = jdate("Y/m/d H:i", '', '', 'Asia/Tehran', 'en');

        $total_amount = $order['total_amount'];
        $discount_amount = $order['discount_amount'] ?? 0;
        $paid_amount = $total_amount - $discount_amount;

        $this->send_email(
            $_ENV['ADMIN_MAIL'],
            'مدیریت محترم',
            'بررسی رسید پرداخت خرید از آکادمی وویس کلاس',
            null,
            null,
            $attachments,
            $_ENV['SENDPULSE_NEW_TRANSACTION_TEMPLATE_ID'],
            [
                "buyer_name" => $order['buyer_name'],
                "phone_number" => $order['phone'],
                "purchase_time" => $purchase_time,
                "order_amount" => number_format($total_amount),
                "discount_amount" => number_format($discount_amount),
                "paid_amount" => number_format($paid_amount),
                "tracking_code" => $ref_id ?? '',
                "last_four_digits" => $card_pan ?? '',
            ]
        );

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
                ORDER BY t.created_at DESC;
        ";

        $transactions = $this->getData($sql, [$user['id']], true);

        if (!$transactions) {
            Response::success('تراکنشی یافت نشد');
        }

        foreach ($transactions as &$transaction) {
            $transaction['order'] = json_decode($transaction['order'], true);
            $transaction['order']['products'] = $this->get_order_items($transaction['order']['id']);
            unset($transaction['order']['id']);
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

        if (!empty($params['status']) && in_array($params['status'], ['pending-pay', 'need-approval', 'paid', 'rejected', 'failed', 'canceled'])) {
            $where_condition .= ' WHERE t.status = ? ';
            $bind_params[] = $params['status'];
        }

        if (!empty($params['q'])) {
            $query = $params['q'];
            $condition = '(u.phone LIKE ? OR CONCAT(up.first_name_fa, " ", up.last_name_fa) LIKE ?) OR UPPER(t.ref_id) LIKE UPPER(?)';
            if ($where_condition === '') {
                $where_condition .= " WHERE $condition";
            } else {
                $where_condition .= " AND $condition";
            }
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
        }

        $current_page = isset($params['current_page']) ? max((int) $params['current_page'], 1) : 1;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 20)
            ? (int) $params['per_page_count']
            : 20;

        $offset = ($current_page - 1) * $per_page_count;

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
                'stats' => $stats,
                'total_pages' => 1
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
                    'discount_amount', o.discount_amount
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
            $transaction['order']['products'] = $this->get_order_items($transaction['order']['id']);
            unset($transaction['order']['id']);
            $transaction['receipt'] = $transaction['receipt'] ? $this->get_full_image_url($transaction['receipt']) : null;
        }

        $total_pages = ceil($stats['total'] / $per_page_count);

        Response::success('تراکنش های شما دریافت شد', 'transactionsData', [
            'transactions' => $transactions,
            'stats' => $stats,
            'total_pages' => $total_pages
        ]);
    }


    public function update_status($params)
    {
        $admin = $this->check_role(['admin']);

        $this->check_params($params, ['transaction_uuid', 'status']);

        $new_status = $params['status'];
        if (!in_array($new_status, ['pending-pay', 'need-approval', 'paid', 'rejected', 'canceled', 'failed'])) {
            Response::error('وضعیت جدید معتبر نیست');
        }

        $db = new Database();
        try {
            $db->beginTransaction();

            $update_transaction = $db->updateData(
                "UPDATE {$db->table['transactions']} SET `status` = ? WHERE uuid = ?",
                [
                    $new_status,
                    $params['transaction_uuid']
                ]
            );

            if (!$update_transaction) {
                throw new Exception('خطا در تغییر وضعیت تراکنش');
            }

            $order = $db->getData(
                "SELECT order_id FROM {$db->table['transactions']} WHERE uuid = ?",
                [
                    $params['transaction_uuid']
                ]
            );

            if (!$order) {
                throw new Exception('خطا در دریافت سفارش');
            }

            $items = $db->getData(
                "SELECT 
                        oi.id,
                        oi.uuid,
                        oi.product_id,
                        oi.access_type,
                        oi.price,
                        oi.status,
                        p.instructor_id,
                        p.instructor_share_percent
                    FROM {$db->table['order_items']} oi
                    JOIN {$db->table['products']} p ON oi.product_id = p.id
                    WHERE order_id = ?",
                [$order['order_id']],
                true
            );

            if (!$items) {
                throw new Exception('هیچ آیتمی برای این سفارش پیدا نشد');
            }

            foreach ($items as $item) {
                switch ($new_status) {
                    case 'pending-pay':
                    case 'need-approval':
                        $item_status = 'pending-pay';
                        break;

                    case 'paid':
                        $item_status = $item['access_type'] === 'printed' ? 'pending-review' : 'completed';
                        break;

                    case 'failed':
                    case 'rejected':
                        $item_status = 'rejected';
                        break;

                    case 'canceled':
                        $item_status = 'canceled';
                        break;

                    default:
                        $item_status = 'pending-pay';
                        break;
                }

                $update_item_status = $this->update_item_status(
                    [
                        'item_uuid' => $item['uuid'],
                        'status' => $item_status,
                        'access_type' => $item['access_type']
                    ],
                    false,
                    $db
                );

                if (!$update_item_status) {
                    throw new Exception('خطا در بروزرسانی وضعیت سفارش');
                }

                // $earning_uuid = $this->generate_uuid();

                // $instructor_earning_amount = $item['price'] * ($item['instructor_share_percent'] / 100);

                // $site_commission = $item['price'] - $instructor_earning_amount;

                // $current_time = $this->current_time();

                // $earning = $db->getData(
                //     "SELECT id, `status` FROM {$db->table['instructor_earnings']} WHERE order_item_id = ?",
                //     [$item['id']]
                // );

                // $update_instructor_earning = true;
                // if ($earning) {
                //     if ($earning['status'] === 'canceled' && $new_status === 'paid') {
                //         $update_instructor_earning = $db->updateData(
                //             "UPDATE {$db->table['instructor_earnings']}
                //                         SET `status` = 'pending', updated_at = ?
                //                     WHERE id = ?",
                //             [$current_time, $earning['id']]
                //         );
                //     } elseif ($earning['status'] !== 'paid' && $new_status !== 'paid') {
                //         $update_instructor_earning = $db->updateData(
                //             "UPDATE {$db->table['instructor_earnings']}
                //                         SET `status` = 'canceled', updated_at = ?
                //                     WHERE id = ?",
                //             [$current_time, $earning['id']]
                //         );
                //     }
                // } else {
                //     if ($new_status === 'paid') {
                //         $update_instructor_earning = $db->insertData(
                //             "INSERT INTO {$db->table['instructor_earnings']}
                //                         (uuid, instructor_id, order_item_id, amount, site_commission, total_price, status, created_at, updated_at)
                //                     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
                //             [
                //                 $earning_uuid,
                //                 $item['instructor_id'],
                //                 $item['id'],
                //                 $instructor_earning_amount,
                //                 $site_commission,
                //                 $item['price'],
                //                 $current_time,
                //                 $current_time
                //             ]
                //         );
                //     }
                // }

                // if (!$update_instructor_earning) {
                //     throw new Exception('خطا در ثبت سهم مدرس');
                // }
            }

            $db->commit();
            Response::success('وضعیت سفارش به‌روزرسانی شد');

        } catch (Exception $e) {
            $db->rollback();
            Response::error($e ? $e->getMessage() : 'خطا در بروزرسانی وضعیت تراکنش', null, 400, $db);
        }
    }
}
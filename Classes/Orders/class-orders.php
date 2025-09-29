<?php
namespace Classes\Orders;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Error;
use Classes\Users\Users;
use DateTime;

class Orders extends Carts
{
    use Base, Sanitizer;

    public function add_order($params)
    {
        $user = $this->check_role();

        $address = $params['address'] ?? null;

        $order_items = $this->get_cart_items(['return' => true]);
        if (!$order_items) {
            Response::error('خطا در دریافت محصولات سبد خرید');
        }

        $order_uuid = $this->generate_uuid();

        $current_time = $this->current_time();

        $order_code = 'ORD_' . $this->get_random('int', 6, $this->table['orders'], 'code');

        $total_amount = 0;
        $discout_amount = 0;
        foreach ($order_items as $item) {
            $total_amount += $item['price'];
            $discout_amount += $item['discount_amount'];
        }

        $db = new Database();
        $db->beginTransaction();

        $order_id = $db->insertData(
            "INSERT INTO {$db->table['orders']} (`uuid`, `user_id`, `code`, `discount_code_id`, `discount_amount`, `total_amount`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $order_uuid,
                $user['id'],
                $order_code,
                NULL,
                $discout_amount,
                $total_amount,
                $current_time,
                $current_time
            ]
        );

        if (!$order_id) {
            Response::error('خطا در ثبت سفارش', null, 400, $db);
        }

        if ($address) {
            [$province, $city] = $this->check_input(['province' => $address['province'], 'city' => $address['city']], 'province_city', 'استان و شهر');
            $postal_code = $this->check_input($address['postal_code'], 'postal_code', 'کد پستی');
            $full_address = $this->check_input_length($address['full_address'], 'آدرس دقیق', 30);
            $receiver_name = $this->check_input($address['receiver_name'], 'fa_full_name', 'نام و نام خانوادگی گیرنده');
            $receiver_phone = $this->check_input($address['receiver_phone'], 'phone', 'شماره تماس گیرنده');
            $notes = $address['notes'] ?? null;
            $insert_address = $db->insertData(
                "INSERT INTO {$db->table['order_addresses']} (`order_id`, `province`, `city`, `postal_code`, `full_address`, `receiver_name`, `receiver_phone`, `notes`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $order_id,
                    $province,
                    $city,
                    $postal_code,
                    $full_address,
                    $receiver_name,
                    $receiver_phone,
                    $notes
                ]
            );

            if (!$insert_address) {
                Response::error('خطا در ثبت آدرس سفارش', null, 400, $db);
            }
        }

        foreach ($order_items as $item) {
            $item_uuid = $this->generate_uuid();
            $order_item_id[] = $db->insertData(
                "INSERT INTO {$db->table['order_items']} (`uuid`, `order_id`, `product_id`, `status`, `access_type`, `quantity`, `price`) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $item_uuid,
                    $order_id,
                    $item['id'],
                    'pending-pay',
                    $item['access_type'],
                    $item['quantity'],
                    ($item['price'] - $item['discount_amount'])
                ]
            );
        }

        if (in_array(false, $order_item_id)) {
            Response::error('خطا در ثبت آیتم های سفارش', null, 400, $db);
        }

        $transaction_uuid = $this->generate_uuid();

        $transaction_id = $db->insertData(
            "INSERT INTO {$db->table['transactions']} (`uuid`, `order_id`, `type`, `amount`, `status`) VALUES (?, ?, ?, ?, ?)",
            [
                $transaction_uuid,
                $order_id,
                'card',
                ($total_amount - $discout_amount),
                'pending-pay'
            ]
        );

        if (!$transaction_id) {
            Response::error('خطا در ثبت تراکنش', null, 400, $db);
        }

        $this->clear_cart_items(['return' => true]);

        $db->commit();
        Response::success('سفارش با موفقیت ثبت شد', 'orderId', $order_uuid);
    }

    protected function get_order_items($order_id)
    {
        $sql = "SELECT
                    oi.uuid,
                    oi.status,
                    oi.access_type,
                    oi.quantity,
                    oi.price,
                    p.title,
                    p.slug,
                    p.type,
                    p.price AS product_price,
                    p.discount_amount AS product_discount_amount,
                    p.thumbnail
                FROM {$this->table['order_items']} oi
                LEFT JOIN {$this->table['products']} p ON oi.product_id = p.id
                WHERE oi.order_id = ?
                GROUP BY oi.id
        ";

        $order_items = $this->getData($sql, [$order_id], true);

        if ($order_items) {
            foreach ($order_items as &$item) {
                $item['thumbnail'] = $this->get_full_image_url($item['thumbnail']);
            }
        }

        return $order_items ?? [];
    }

    public function get_unpaid_order($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['order_id']);

        $sql = "SELECT
                    o.id,
                    o.code,
                    o.discount_amount,
                    o.total_amount,
                    o.created_at,
                    o.updated_at
                FROM {$this->table['orders']} o
                LEFT JOIN {$this->table['transactions']} t ON o.id = t.order_id
                WHERE o.uuid = ? AND o.user_id = ? AND t.status = 'pending-pay'
                LIMIT 1
        ";
        $unpaid_order = $this->getData($sql, [$params['order_id'], $user['id']]);

        if (!$unpaid_order) {
            Response::success('سفارش یافت نشد', 'unpaidOrders', []);
        }

        $unpaid_order['products'] = $this->get_order_items($unpaid_order['id']);
        unset($unpaid_order['id']);

        Response::success('سفارش پرداخت نشده دریافت شد', 'unpaidOrder', $unpaid_order);
    }

    public function get_all_order_items($params)
    {
        $admin = $this->check_role(['admin']);

        $statsSql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending-pay' THEN 1 ELSE 0 END) as pending_pay,
                        SUM(CASE WHEN status = 'pending-review' THEN 1 ELSE 0 END) as pending_review,
                        SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) as sending,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as canceled
                    FROM {$this->table['order_items']}";

        $stats = $this->getData($statsSql, []);

        $where_condition = '';
        $bind_params = [];

        if (!empty($params['status']) && in_array($params['status'], ['pending-pay', 'pending-review', 'sending', 'completed', 'rejected', 'canceled'])) {
            $where_condition .= ' WHERE oi.status = ? ';
            $bind_params[] = $params['status'];
        }

        if (!empty($params['q'])) {
            $query = $params['q'];
            $condition = '(u.phone LIKE ? OR CONCAT(up.first_name_fa, " ", up.last_name_fa) LIKE ?) OR UPPER(o.code) LIKE UPPER(?) OR UPPER(p.title) LIKE UPPER(?)';
            if ($where_condition === '') {
                $where_condition .= " WHERE $condition";
            } else {
                $where_condition .= " AND $condition";
            }
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
        }

        $current_page = isset($params['current_page']) ? max(((int) $params['current_page'] - 1), 0) : 0;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 20)
            ? (int) $params['per_page_count']
            : 20;

        $offset = $current_page * $per_page_count;
        $bind_params = array_merge($bind_params, [$per_page_count, $offset]);

        $sql = "SELECT
                    oi.uuid,
                    p.title,
                    oi.status,
                    oi.access_type,
                    oi.price,
                    oi.updated_at,
                    JSON_OBJECT(
                        'uuid', o.uuid,
                        'code', o.code,
                        'user_full_name', CASE
                                                WHEN up.first_name_fa IS NULL OR up.first_name_fa = '' 
                                                    THEN u.phone
                                                ELSE CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                                            END,
                        'total_amount', o.total_amount,
                        'discount_amount', o.discount_amount,
                        'created_at', o.created_at,
                        'updated_at', o.updated_at,
                        'address', JSON_OBJECT(
                                        'province', oa.province,
                                        'city', oa.city,
                                        'full_address', oa.full_address,
                                        'postal_code', oa.postal_code,
                                        'receiver_name', oa.receiver_name,
                                        'receiver_phone', oa.receiver_phone,
                                        'notes', oa.notes
                                    )
                    ) AS `order`
                FROM {$this->table['order_items']} oi
                LEFT JOIN {$this->table['orders']} o ON oi.order_id = o.id
                LEFT JOIN {$this->table['products']} p ON oi.product_id = p.id
                LEFT JOIN {$this->table['order_addresses']} oa ON o.id = oa.order_id
                LEFT JOIN {$this->table['users']} u ON o.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id
                $where_condition
                GROUP BY oi.id
                ORDER BY o.created_at DESC
                LIMIT ? OFFSET ?
        ";


        $all_orders = $this->getData($sql, $bind_params, true);

        if (!$all_orders) {
            Response::success('سفارشی یافت نشد', 'ordersData', [
                'orders' => [],
                'stats' => $stats
            ]);
        }

        foreach ($all_orders as &$order) {
            $order['order'] = json_decode($order['order'], true);
            // $order['order']['address'] = json_decode($order['order']['address'], true);
        }

        Response::success('سفارشات دریافت شد', 'ordersData', [
            'orders' => $all_orders,
            'stats' => $stats
        ]);
    }

    public function update_item_status($params)
    {
        $admin = $this->check_role(['admin']);

        $this->check_params($params, ['item_uuid', 'status']);

        $update_transaction = $this->updateData(
            "UPDATE {$this->table['order_items']} SET `status` = ? WHERE uuid = ?",
            [
                $params['status'],
                $params['item_uuid']
            ]
        );

        if (!$update_transaction) {
            Response::error('خطا در تغییر وضعیت سفارش');
        }

        Response::success('وضعیت سفارش به روز شد');
    }
}
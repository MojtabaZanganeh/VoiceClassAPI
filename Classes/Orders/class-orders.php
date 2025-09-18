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

    public function add_order()
    {
        $user = $this->check_role();

        $order_items = $this->get_cart_items();
        if (!$order_items) {
            Response::error('خطا در دریافت محصولات سبد خرید');
        }

        $current_time = $this->current_time();

        $order_code = 'ORD_' . $this->get_random('int', 6, $this->table['orders'], 'code');

        $total_amount = 0;
        $discout_amount = 0;
        foreach ($order_items as $item) {
            $total_amount += $item['price'];
            $discout_amount += $item['discount_amount'];
        }

        $this->beginTransaction();

        $order_id = $this->insertData(
            "INSERT INTO {$this->table['orders']} (`user_id`, `code`, `status`, `discount_code_id`, `discount_amount`, `total_amount`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $user['id'],
                $order_code,
                'pending-pay',
                NULL,
                $discout_amount,
                $total_amount,
                $current_time,
                $current_time
            ]
        );

        if (!$order_id) {
            Response::error('خطا در ثبت سفارش');
        }

        foreach ($order_items as $item) {
            $order_item_id = $this->insertData(
                "INSERT INTO {$this->table['order_items']} (`order_id`, `product_id`, `access_type`, `quantity`, `price`) VALUES (?, ?, ?, ?, ?)",
                [
                    $order_id,
                    $item['id'],
                    $item['access_type'],
                    $item['quantity'],
                    ($item['price'] - $item['discount_amount'])
                ]
            );

            if (!$order_item_id) {
                Response::error('خطا در ثبت آیتم های سفارش');
            }
        }

        $this->commit();
        Response::success('سفارش با موفقیت ثبت شد', 'orderCode', $order_code);
    }

    public function get_order_items($order_id)
    {
        $user = $this->check_role();

        $sql = "SELECT
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
        ";

        $order_items = $this->getData($sql, [$order_id], true);

        if ($order_items) {
            foreach ($order_items as &$item) {
                $item['thumbnail'] = $this->get_full_image_url($item['thumbnail']);
            }
        }

        return $order_items ?? [];
    }

    public function get_unpaid_orders()
    {
        $user = $this->check_role();

        $unpaid_orders = $this->getData(
            "SELECT id, code, discount_amount, total_amount, created_at, updated_at FROM {$this->table['orders']} WHERE `status` = 'pending-pay'",
            [],
            true
        );

        if (!$unpaid_orders) {
            Response::success('شما هیچ سفارش پرداخت نشده ای ندارید', 'unpaidOrders', []);
        }

        foreach ($unpaid_orders as &$order) {
            $order['products'] = $this->get_order_items($order['id']);
        }

        Response::success('سفارشات پرداخت نشده دریافت شد', 'unpaidOrders', $unpaid_orders);
    }
}
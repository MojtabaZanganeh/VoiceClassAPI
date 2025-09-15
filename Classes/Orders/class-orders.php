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
}
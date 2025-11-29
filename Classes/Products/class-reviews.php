<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Database;
use Classes\Users\Users;

class Reviews extends Products
{
    use Base, Sanitizer;

    public function get_product_reviews($params)
    {
        $this->check_params($params, ['product_uuid']);

        $product = $this->getData("SELECT id FROM {$this->table['products']} WHERE uuid = ?", [$params['product_uuid']]);

        $sql = "SELECT
                    r.id,
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS student,
                    u.avatar,
                    r.rating,
                    r.comment,
                    r.created_at
                FROM {$this->table['reviews']} r
                LEFT JOIN {$this->table['users']} u ON r.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                WHERE r.product_id = ?
                ORDER BY r.created_at DESC
                LIMIT 10
        ";
        $product_reviews = $this->getData($sql, [$product['id']], true);

        if (!$product_reviews) {
            Response::success('نظری یافت نشد', 'productReviews', $product_reviews);
        }

        foreach ($product_reviews as &$review) {
            $review['avatar'] = isset($review['avatar']) ? $this->get_full_image_url($review['avatar']) : null;
        }
        Response::success('نظرات دریافت شد', 'productReviews', $product_reviews);
    }

    public function check_user_reviewed_product($product_id)
    {
        $user = $this->check_role();

        $user_reviewed = $this->getData(
            "SELECT EXISTS (
                SELECT 1
                FROM reviews
                WHERE user_id = ? AND product_id = ?
            ) AS has_review;",
            [$user['id'], $product_id]
        );

        return !empty($user_reviewed['has_review']) ? 1 : 0;
    }

    public function add_product_review($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['product_uuid', 'item_uuid', 'rating', 'comment']);

        $product_uuid = $params['product_uuid'];
        $item_uuid = $params['item_uuid'];
        $rating = $params['rating'];
        $comment = trim($params['comment']);

        if (!in_array((int) $rating, [1, 2, 3, 4, 5])) {
            Response::error('امتیاز نامعتبر است');
        }

        $product = $this->getData(
            "SELECT id FROM {$this->table['products']} WHERE uuid = ?",
            [$product_uuid]
        );

        $order_item = $this->getData(
            "SELECT oi.id, oi.status 
                    FROM {$this->table['order_items']} oi
                    JOIN {$this->table['orders']} o ON oi.order_id = o.id
                    WHERE oi.uuid = ? AND o.user_id = ?",
            [$item_uuid, $user['id']]
        );

        if (empty($product) || empty($order_item)) {
            Response::error('محصول یا آیتم سفارش یافت نشد');
        }

        $product_id = $product['id'];
        $order_item_id = $order_item['id'];

        $already_reviewed = $this->check_user_reviewed_product($product_id);
        if ($already_reviewed) {
            Response::error('شما قبلاً برای این محصول نظر ثبت کرده‌اید');
        }

        $insert_review = $this->insertData(
            "INSERT INTO {$this->table['reviews']} (`product_id`, `order_item_id`, `user_id`, `avatar`, `rating`, `comment`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $product_id,
                $order_item_id,
                $user['id'],
                $user['avatar'],
                $rating,
                $comment,
                $this->current_time()
            ]
        );

        if (!$insert_review) {
            Response::error('خطا در ثبت نظر');
        }

        Response::success('نظر شما با موفقیت ثبت شد');
    }
}
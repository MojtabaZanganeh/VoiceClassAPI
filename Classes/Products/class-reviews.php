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
                    r.uuid,
                    r.student,
                    r.avatar,
                    r.rating,
                    r.comment,
                    r.created_at,
                    oi.access_type AS product_access_type
                FROM {$this->table['reviews']} r
                LEFT JOIN {$this->table['order_items']} oi ON r.order_item_id = oi.id
                WHERE r.product_id = ? AND r.status = 'verified'
                ORDER BY r.created_at DESC
                LIMIT 20
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

        $review_uuid = $this->generate_uuid();

        $user_profile = $this->getData("SELECT CONCAT(first_name_fa, ' ', last_name_fa) AS full_name FROM {$this->table['user_profiles']} WHERE user_id = ?", [$user['id']]);

        if (!$user_profile || empty($user_profile['full_name'])) {
            Response::error('لطفا ابتدا نام و نام خوانوادگی خود را در بخش پروفایل وارد کنید');
        }

        $insert_review = $this->insertData(
            "INSERT INTO {$this->table['reviews']} (`uuid`, `product_id`, `order_item_id`, `user_id`, `student`, `avatar`, `rating`, `comment`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $review_uuid,
                $product_id,
                $order_item_id,
                $user['id'],
                $user_profile['full_name'],
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

    public function get_all_reviews($params)
    {
        $admin = $this->check_role(['admin']);

        $statsSql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending-review' THEN 1 ELSE 0 END) as pending_review,
                        SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                    FROM {$this->table['reviews']}";

        $stats = $this->getData($statsSql, []);

        $where_condition = '';
        $bind_params = [];

        if (!empty($params['status']) && in_array($params['status'], ['pending-review', 'verified', 'rejected'])) {
            $where_condition .= ' WHERE r.status = ? ';
            $bind_params[] = $params['status'];
        }

        if (!empty($params['q'])) {
            $query = $params['q'];
            $condition = 'UPPER(r.student) LIKE UPPER(?) OR UPPER((r.comment) LIKE UPPER(?)';
            if ($where_condition === '') {
                $where_condition .= " WHERE $condition";
            } else {
                $where_condition .= " AND $condition";
            }
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
        }

        $current_page = isset($params['current_page']) ? max((int) $params['current_page'], 1) : 1;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 20)
            ? (int) $params['per_page_count']
            : 20;

        $offset = ($current_page - 1) * $per_page_count;
        $bind_params = array_merge($bind_params, [$per_page_count, $offset]);

        $sql = "SELECT
                    r.uuid,
                    r.student,
                    r.avatar,
                    r.rating,
                    r.created_at,
                    r.comment,
                    r.status,
                    p.title AS product_title,
                    oi.access_type AS product_access_type,
                    oi.updated_at AS ordered_at
                FROM {$this->table['reviews']} r
                LEFT JOIN {$this->table['order_items']} oi ON r.order_item_id = oi.id
                LEFT JOIN {$this->table['products']} p ON r.product_id = p.id
                $where_condition
                GROUP BY r.uuid
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?
        ";

        $all_reviews = $this->getData($sql, $bind_params, true);

        if (!$all_reviews) {
            Response::success('نظرات یافت نشد', 'reviewsData', [
                'reviews' => [],
                'stats' => $stats,
                'total_pages' => 1
            ]);
        }

        foreach ($all_reviews as &$review) {
            $review['avatar'] = isset($review['avatar']) ? $this->get_full_image_url($review['avatar']) : null;
        }

        $total_pages = ceil($stats['total'] / $per_page_count);

        Response::success('نظرات دریافت شد', 'reviewsData', [
            'reviews' => $all_reviews,
            'stats' => $stats,
            'total_pages' => $total_pages
        ]);
    }

    public function update_review_status($params)
    {
        $admin = $this->check_role(['admin']);

        $this->check_params($params, ['review_uuid', 'status']);

        $new_status = $params['status'];
        if (!\in_array($new_status, ['pending-review', 'verified', 'rejected'])) {
            Response::error('وضعیت جدید معتبر نیست');
        }

        $update_review = $this->updateData(
            "UPDATE {$this->table['reviews']} SET `status` = ? WHERE uuid = ?",
            [
                $new_status,
                $params['review_uuid']
            ]
        );

        if (!$update_review) {
            Response::error('خطا در تغییر وضعیت نظر');
        }

        Response::success('وضعیت نظر به روز شد');
    }
}
<?php
namespace Classes\Products;

use Classes\Base\Base;
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
}
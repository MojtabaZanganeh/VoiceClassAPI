<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;

class Books extends Products
{
    use Base, Sanitizer;

    public function get_user_books()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    p.title,
                    p.thumbnail,
                    JSON_OBJECT(
                    'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                    ) AS instructor,
                    bd.pages,
                    bd.size,
                    bd.format,
                    p.level,
                    o.status
                FROM {$this->table['order_items']} oi
                LEFT JOIN {$this->table['products']} p ON oi.product_id = p.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                LEFT JOIN {$this->table['orders']} o ON oi.order_id = o.id
                LEFT JOIN {$this->table['book_details']} bd ON p.id = bd.product_id
                    WHERE o.user_id = ? AND p.type = 'book'
                GROUP BY oi.id, p.id, i.id, up.id, o.id
                ORDER BY o.created_at DESC
        ";
        $user_books = $this->getData($sql, [$user['id']], true);

        if (!$user_books) {
            Response::error('خطا در دریافت جزوات کاربر');
        }

        foreach ($user_books as &$user_book) {
            $user_book['instructor'] = json_decode($user_book['instructor']);
            $user_book['thumbnail'] = $this->get_full_image_url($user_book['thumbnail']);
        }

        Response::success('جزوات کاربر دریافت شد', 'userBooks', $user_books);
    }
}
<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;

class Books extends Products
{
    use Base, Sanitizer;

    public function get_all_books() {
        $sql = "SELECT
                    p.id,
                    p.uuid,
                    p.slug,
                    pc.name AS category,
                    p.thumbnail,
                    p.title,
                    JSON_OBJECT(
                        'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa),
                        'avatar', u.avatar,
                        'professional_title', i.professional_title
                    ) AS instructor,
                    p.introduction,
                    p.level,
                    p.price,
                    p.discount_amount,
                    p.rating_avg,
                    p.rating_count,
                    p.students,
                    bd.access_type,
                    bd.pages,
                    bd.format
                FROM {$this->table['products']} p
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                LEFT JOIN {$this->table['book_details']} bd ON p.id = bd.product_id
                WHERE p.type = 'book'
                ORDER BY p.created_at DESC
        ";
        $all_books = $this->getData($sql,[], true);

        if (!$all_books) {
            Response::error('خطا در دریافت جزوات');
        }

        foreach ($all_books as &$book) {
            $book['thumbnail'] = $this->get_full_image_url($book['thumbnail']);
            $book['instructor'] = json_decode($book['instructor'], true);
            $book['instructor']['avatar'] = $this->get_full_image_url($book['instructor']['avatar']);
        }

        Response::success('جزوات دریافت شد', 'allBooks', $all_books);
    }

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
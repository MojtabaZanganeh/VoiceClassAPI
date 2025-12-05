<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;

class Books extends Products
{
    use Base, Sanitizer;

    public function get_all_books($params)
    {
        $this->get_products(
            params: $params,
            type: 'book',
            details_table: 'book_details',
            select_fields: 'dt.access_type, dt.pages, dt.format',
            access_types: ['printed', 'digital'],
            not_found_message: 'جزوه ای یافت نشد',
            success_message: 'جزوات دریافت شد',
            response_key: 'allBooks'
        );
    }

    public function get_book_by_slug($params)
    {
        $this->get_product_by_slug($params, [
            'details_table' => 'book_details',
            'select_fields' => "
                dt.access_type,
                dt.pages,
                dt.format,
                dt.size,
                dt.all_lessons_count,
                dt.printed_price,
                dt.printed_discount_amount,
                dt.demo_link
            ",
            'instructor_stats_field' => 'books_written',
            'special_processing' => function (&$product) {
                $product['demo_link'] = $this->get_full_image_url($product['demo_link']);
            },
            'messages' => [
                'not_found' => 'جزوه ای یافت نشد',
                'success' => 'جزوه دریافت شد',
                'response_key' => 'book'
            ]
        ]);
    }

    public function get_user_books()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    p.id,
                    p.uuid,
                    p.title,
                    p.thumbnail,
                    JSON_OBJECT(
                    'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                    ) AS instructor,
                    bd.pages,
                    bd.size,
                    bd.format,
                    CASE 
                        WHEN oi.access_type = 'digital' AND oi.status = 'completed'
                            THEN bd.digital_link 
                        ELSE NULL 
                    END AS digital_link,
                    p.level,
                    o.uuid AS order_uuid,
                    oi.uuid AS item_uuid,
                    oi.status AS order_status,
                    oi.access_type,
                    oi.updated_at
                FROM {$this->table['order_items']} oi
                LEFT JOIN {$this->table['products']} p ON oi.product_id = p.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                LEFT JOIN {$this->table['orders']} o ON oi.order_id = o.id
                LEFT JOIN {$this->table['transactions']} t ON o.id = t.order_id
                LEFT JOIN {$this->table['book_details']} bd ON p.id = bd.product_id
                WHERE o.user_id = ? AND p.type = 'book'
                GROUP BY o.id
                ORDER BY o.created_at DESC
        ";

        $user_books = $this->getData($sql, [$user['id']], true);

        if (!$user_books) {
            Response::success('جزوه ای یافت نشد', 'userBooks', []);
        }

        $review_obj = new Reviews();
        $links_obj = new Links();

        foreach ($user_books as &$user_book) {
            $user_book['instructor'] = json_decode($user_book['instructor']);
            $user_book['thumbnail'] = $this->get_full_image_url($user_book['thumbnail']);
            $user_book['digital_link'] = $user_book['digital_link'] ? $this->get_full_image_url($user_book['digital_link']) : null;
            $user_book['reviewed'] = $review_obj->check_user_reviewed_product($user_book['id']);
            $user_book['related_links'] = $links_obj->get_product_links($user_book['id']);
            unset($user_book['id']);
        }

        Response::success('جزوات کاربر دریافت شد', 'userBooks', $user_books);
    }
}
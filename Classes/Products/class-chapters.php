<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Database;
use Classes\Users\Users;

class Chapters extends Products
{
    use Base, Sanitizer;

    public function get_product_chapters($params)
    {
        $this->check_params($params, ['product_uuid']);

        $product = $this->getData("SELECT id FROM {$this->table['products']} WHERE uuid = ?", [$params['product_uuid']]);

        if (!$product) {
            Response::error('شما به این دوره دسترسی ندارید');
        }

        $course_student = false;
        if (isset($params['student']) && $params['student'] === 'true') {
            $user = $this->check_role();

            $user_order = $this->getData("SELECT id, order_id FROM {$this->table['course_students']} WHERE course_id = ? AND user_id = ?", [$product['id'], $user['id']]);
            if ($user_order) {
                $order_details = $this->getData("SELECT id, `status` FROM {$this->table['orders']} WHERE id = ?", [$user_order['id']]);
                if ($order_details) {
                    if ($order_details['status'] === 'sending' || $order_details['status'] === 'finished') {
                        $course_student = true;
                    }
                }
            }
            if ($course_student === false) {
                Response::error('شما به این دوره دسترسی ندارید');
            }
        }

        $chapters_sql = "SELECT id, title, lessons_count, chapter_length 
                     FROM {$this->table['chapters']} 
                     WHERE product_id = ?";
        $chapters = $this->getData($chapters_sql, [$product['id']], true);

        if (!$chapters) {
            Response::error('خطا در دریافت سرفصل ها');
        }

        $get_link = $course_student ? ', link, size ' : '';
        foreach ($chapters as &$chapter) {
            $lessons_sql = "SELECT id, title, `length`, free $get_link
                        FROM {$this->table['chapter_lessons']} 
                        WHERE chapter_id = ?";
            $chapter['lessons_detail'] = $this->getData($lessons_sql, [$chapter['id']], true) ?: [];
        }

        Response::success('سرفصل ها دریافت شد', 'productChapters', $chapters);
    }
}
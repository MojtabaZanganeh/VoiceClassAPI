<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
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
                    if ($order_details['status'] === 'sending' || $order_details['status'] === 'completed') {
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

    public function add_chapters(array $chapters, int $product_id, $product_type = 'course' | 'book', Database $db)
    {
        $this->check_role(['instructor', 'admin']);

        $total_length = 0;
        $total_count = 0;

        foreach ($chapters as $chapter) {
            $title = $this->check_input($chapter['title'], null, 'عنوان فصل', '/^.{3,25}$/us');

            $lessons_detail = $this->check_input($chapter['lessons_detail'], 'array', 'درس ها');

            $lesson_coout = count($lessons_detail);

            $chapter_length = array_sum(array_column($lessons_detail, 'length'));

            $chapter_id = $db->insertData(
                "INSERT INTO {$db->table['chapters']} (`product_id`, `title`, `lessons_count`, `chapter_length`) VALUES (?, ?, ?, ?)",
                [
                    $product_id,
                    $title,
                    $lesson_coout,
                    $chapter_length
                ]
            );

            if (!$chapter_id) {
                Response::error('خطا در افزودن سرفصل');
            }

            foreach ($lessons_detail as $lesson) {
                $lesson_title = $this->check_input($lesson['title'], null, 'عنوان درس', '/^.{3,50}$/us');
                $lesson_length = $this->check_input($lesson['length'], 'positive_int', 'طول درس');
                $lesson_free = $this->check_input($lesson['free'], 'boolean', 'درس رایگان');
                $lesson_link = $product_type === 'course' ? $this->check_input($lesson['link'], null, 'لینک درس', '/^https:\/\/drive\.google\.com\/file\/d\//') : null;

                $lesson_id = $db->insertData(
                    "INSERT INTO {$db->table['chapter_lessons']} (`chapter_id`, `title`, `length`, `free`, `link`) VALUES (?, ?, ?, ?, ?)",
                    [
                        $chapter_id,
                        $lesson_title,
                        $lesson_length,
                        $lesson_free,
                        $lesson_link
                    ]
                );

                if (!$lesson_id) {
                    Response::error('خطا در افزودن درس');
                }

            }

            $total_count += $lesson_coout;
            $total_length += $chapter_length;
        }

        return ['lessons_count' => $total_count, 'total_length' => $total_length];
    }
}
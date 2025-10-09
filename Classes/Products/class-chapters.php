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

            $user_order = $this->getData(
                "SELECT 
                        o.id AS order_id,
                        oi.id AS order_item_id,
                        oi.status
                    FROM {$this->table['orders']} o
                    JOIN {$this->table['order_items']} oi ON o.id = oi.order_id
                        WHERE o.user_id = ? AND oi.product_id = ?
                    LIMIT 1",
                [$user['id'], $product['id']]
            );

            if ($user_order) {
                if ($user_order['status'] === 'completed') {
                    $course_student = true;
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

        foreach ($chapters as &$chapter) {
            $lessons_sql = "SELECT 
                                id, title, `length`, free,
                                CASE 
                                    WHEN free = 1 OR ? = 1 THEN link 
                                    ELSE NULL 
                                END AS link,
                                CASE 
                                    WHEN free = 1 OR ? = 1 THEN size 
                                    ELSE NULL 
                                END AS size
                            FROM {$this->table['chapter_lessons']} 
                            WHERE chapter_id = ?
                        ";

            $chapter['lessons_detail'] = $this->getData($lessons_sql, [$course_student ? 1 : 0, $course_student ? 1 : 0, $chapter['id']], true) ?: [];
        }


        Response::success('سرفصل ها دریافت شد', 'productChapters', $chapters);
    }

    public function add_chapters(array $chapters, int $product_id, string $product_type, Database $db)
    {
        $this->check_role(['instructor', 'admin']);

        if (!in_array($product_type, ['course', 'book'])) {
            Response::error('نوع محصول معتبر نیست', null, 400, $db);
        }

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
                Response::error('خطا در افزودن سرفصل', null, 400, $db);
            }

            foreach ($lessons_detail as $lesson) {
                $lesson_title = $this->check_input($lesson['title'], null, 'عنوان درس', '/^.{3,50}$/us');
                $lesson_length = $this->check_input($lesson['length'], 'positive_int', 'طول درس');
                $lesson_free = $this->check_input($lesson['free'], 'boolean', 'درس رایگان');
                $lesson_link = $product_type === 'course' && !empty($lesson['link']) ? $this->convert_link_to_preview_embed($lesson['link']) : null;

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
                    Response::error('خطا در افزودن درس', null, 400, $db);
                }

            }

            $total_count += $lesson_coout;
            $total_length += $chapter_length;
        }

        return ['lessons_count' => $total_count, 'total_length' => $total_length];
    }
}
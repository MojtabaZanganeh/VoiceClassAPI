<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
use Classes\Instructors\Instructors;
use Classes\Users\Users;
use Exception;

class Chapters extends Products
{
    use Base, Sanitizer;

    public function get_product_chapters($params)
    {
        $this->check_params($params, ['product_uuid']);

        $product = $this->getData("SELECT id, slug FROM {$this->table['products']} WHERE uuid = ?", [$params['product_uuid']]);

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
                        WHERE o.user_id = ? AND oi.product_id = ? AND oi.status = ?
                    LIMIT 1",
                [$user['id'], $product['id'], 'completed']
            );

            if ($user_order || $user['role'] === 'instructor' || $user['role'] === 'admin') {
                if ($user['role'] === 'instructor') {
                    $instructor_obj = new Instructors();
                    $instructor = $instructor_obj->get_instructor_by_user_id($user['id']);
                    $instructor_obj->check_instructor_permission($instructor['id'], $product['slug']);
                }
                $course_student = true;
            }

            if ($course_student === false) {
                Response::error('شما به این دوره دسترسی ندارید');
            }
        }

        $chapters_sql = "SELECT id, title, lessons_count, chapter_length 
                     FROM {$this->table['chapters']} 
                     WHERE product_id = ?
                     ORDER BY id ASC";
        $chapters = $this->getData($chapters_sql, [$product['id']], true);

        if (!$chapters) {
            Response::error('خطا در دریافت سرفصل‌ها');
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

        if (!empty($params['return'])) {
            return $chapters;
        } else {
            Response::success('سرفصل‌ها دریافت شد', 'productChapters', $chapters);
        }
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
                $lesson_free = $this->check_input($lesson['free'], 'boolean', 'درس رایگان') ? 1 : 0;
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

    public function update_chapters($params, $product_id, $product_type, $db)
    {
        $existing_chapters = $db->getData(
            "SELECT * FROM {$db->table['chapters']} WHERE product_id = ?",
            [$product_id],
            true
        );

        if (!$existing_chapters) {
            throw new Exception('خطا در دریافت فصل ها');
        }

        $existing_lessons = [];
        foreach ($existing_chapters as $chapter) {
            $lessons = $db->getData(
                "SELECT * FROM {$db->table['chapter_lessons']} WHERE chapter_id = ?",
                [$chapter['id']],
                true
            );
            if (!$lessons) {
                throw new Exception('خطا در دریافت درس ها');
            }
            $existing_lessons[$chapter['id']] = $lessons;
        }

        $processed_chapter_ids = [];

        foreach ($params['chapters'] as $chapter_data) {
            $chapter_id = $chapter_data['id'] ?? null;
            $title = $this->check_input($chapter_data['title'], null, 'عنوان فصل', '/^.{3,25}$/us');
            $lessons_detail = $this->check_input($chapter_data['lessons_detail'], 'array', 'درس ها');

            $lesson_count = count($lessons_detail);
            $chapter_length = array_sum(array_column($lessons_detail, 'length'));

            if ($chapter_id && in_array($chapter_id, array_column($existing_chapters, 'id'))) {
                $update_chapter = $db->updateData(
                    "UPDATE {$db->table['chapters']} SET
                            `title` = ?, `lessons_count` = ?, `chapter_length` = ?
                        WHERE id = ?",
                    [
                        $title,
                        $lesson_count,
                        $chapter_length,
                        $chapter_id
                    ]
                );

                if (!$update_chapter) {
                    throw new Exception('خطا در بروزرسانی فصل');
                }

                $processed_chapter_ids[] = $chapter_id;
            } else {
                $chapter_id = $db->insertData(
                    "INSERT INTO {$db->table['chapters']} 
                    (`product_id`, `title`, `lessons_count`, `chapter_length`) 
                 VALUES (?, ?, ?, ?)",
                    [
                        $product_id,
                        $title,
                        $lesson_count,
                        $chapter_length
                    ]
                );

                if (!$chapter_id) {
                    throw new Exception('خطا در ثبت فصل');
                }

                $processed_chapter_ids[] = $chapter_id;
            }

            $processed_lesson_ids = [];

            foreach ($lessons_detail as $lesson_data) {
                $lesson_id = $lesson_data['id'] ?? null;
                $title = $this->check_input($lesson_data['title'], null, 'عنوان درس', '/^.{3,50}$/us');
                $length = $this->check_input($lesson_data['length'], 'positive_int', 'طول درس');
                $free = $this->check_input($lesson_data['free'], 'boolean', 'درس رایگان') ? 1 : 0;
                $link = null;

                if ($product_type === 'course' && !empty($lesson_data['link'])) {
                    $link = $this->convert_link_to_preview_embed($lesson_data['link']);
                }

                if ($lesson_id && in_array($lesson_id, array_column($existing_lessons[$chapter_id] ?? [], 'id'))) {
                    $update_lesson = $db->updateData(
                        "UPDATE {$db->table['chapter_lessons']} SET
                        `title` = ?, `length` = ?, `free` = ?, `link` = ?
                     WHERE id = ?",
                        [
                            $title,
                            $length,
                            $free,
                            $link,
                            $lesson_id
                        ]
                    );

                    if (!$update_lesson) {
                        throw new Exception('خطا در بروزرسانی درس');
                    }

                    $processed_lesson_ids[] = $lesson_id;
                } else {
                    $lesson_id = $db->insertData(
                        "INSERT INTO {$db->table['chapter_lessons']} 
                        (`chapter_id`, `title`, `length`, `free`, `link`) 
                     VALUES (?, ?, ?, ?, ?)",
                        [
                            $chapter_id,
                            $title,
                            $length,
                            $free,
                            $link
                        ]
                    );

                    if (!$lesson_id) {
                        throw new Exception('خطا در ثبت درس');
                    }

                    $processed_lesson_ids[] = $lesson_id;
                }
            }

            $lessons_to_delete = array_diff(array_column($existing_lessons[$chapter_id] ?? [], 'id'), $processed_lesson_ids);
            foreach ($lessons_to_delete as $lesson_id) {
                $delete_lesson = $db->deleteData(
                    "DELETE FROM {$db->table['chapter_lessons']} WHERE id = ?",
                    [$lesson_id]
                );

                if (!$delete_lesson) {
                    throw new Exception('خطا در حذف درس');
                }
            }
        }

        $chapters_to_delete = array_diff(array_column($existing_chapters, 'id'), $processed_chapter_ids);
        foreach ($chapters_to_delete as $chapter_id) {
            $delete_chapter = $db->deleteData(
                "DELETE FROM {$db->table['chapters']} WHERE id = ?",
                [$chapter_id]
            );

            if (!$delete_chapter) {
                throw new Exception('خطا در حذف فصل');
            }
        }
    }
}
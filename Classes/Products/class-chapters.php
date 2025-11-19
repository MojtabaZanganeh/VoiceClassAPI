<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
use Classes\Users\Users;
use Exception;

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

            if ($user_order && $user_order['status'] === 'completed') {
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

        $completed_assessments = [];
        if ($course_student) {
            $submissions_sql = "SELECT 
                                asub.assessment_id,
                                asub.status,
                                asub.final_score,
                                a.max_score
                            FROM {$this->table['assessment_submissions']} asub
                            JOIN {$this->table['assessments']} a ON asub.assessment_id = a.id
                            WHERE asub.user_id = ? AND a.product_id = ? AND asub.status = 'completed'";
            $submissions = $this->getData($submissions_sql, [$user['id'], $product['id']], true);

            if ($submissions) {
                foreach ($submissions as $sub) {
                    $completed_assessments[$sub['assessment_id']] = true;
                }
            }
        }

        $assessments_sql = "SELECT 
                            id,
                            uuid, 
                            chapter_id, 
                            lesson_id, 
                            type,
                            title, 
                            description, 
                            format, 
                            max_score, 
                            duration,
                            is_required,
                            unlock_next
                        FROM {$this->table['assessments']} 
                        WHERE product_id = ?
                        ORDER BY id ASC";
        $all_assessments = $this->getData($assessments_sql, [$product['id']], true);

        $chapter_assessments = [];
        $lesson_assessments = [];

        if ($all_assessments) {
            foreach ($all_assessments as $assessment) {
                $assessment_data = [
                    'id' => (int) $assessment['id'],
                    'uuid' => $assessment['uuid'],
                    'type' => $assessment['type'],
                    'title' => $assessment['title'],
                    'description' => $assessment['description'],
                    'format' => $assessment['format'],
                    'max_score' => (int) $assessment['max_score'],
                    'duration' => $assessment['duration'] ? (int) $assessment['duration'] : null,
                    'is_required' => (bool) $assessment['is_required'],
                    'unlock_next' => (bool) $assessment['unlock_next']
                ];

                if (in_array($assessment['format'], ['test', 'essay', 'mixed'])) {
                    $questions_sql = "SELECT id, type, question, score 
                                 FROM {$this->table['assessment_questions']} 
                                 WHERE assessment_id = ?";
                    $questions = $this->getData($questions_sql, [$assessment['id']], true);

                    if ($questions) {
                        foreach ($questions as &$question) {
                            $question['id'] = (int) $question['id'];
                            $question['score'] = (int) $question['score'];

                            if ($question['type'] === 'test') {
                                $options_sql = "SELECT id, option_text, is_correct 
                                          FROM {$this->table['assessment_question_options']} 
                                          WHERE question_id = ?";
                                $options = $this->getData($options_sql, [$question['id']], true);

                                if ($options) {
                                    $question['options'] = array_map(function ($opt) {
                                        return [
                                            'id' => (int) $opt['id'],
                                            'text' => $opt['option_text'],
                                            'is_correct' => (bool) $opt['is_correct']
                                        ];
                                    }, $options);
                                }
                            }
                        }
                        $assessment_data['questions'] = $questions;
                    }
                }

                if ($assessment['chapter_id'] && !$assessment['lesson_id']) {
                    $chapter_id = $assessment['chapter_id'];
                    if (!isset($chapter_assessments[$chapter_id])) {
                        $chapter_assessments[$chapter_id] = ['exercise' => [], 'exam' => []];
                    }
                    $chapter_assessments[$chapter_id][$assessment['type']][] = $assessment_data;
                }

                if ($assessment['lesson_id']) {
                    $lesson_id = $assessment['lesson_id'];
                    if (!isset($lesson_assessments[$lesson_id])) {
                        $lesson_assessments[$lesson_id] = ['exercise' => [], 'exam' => []];
                    }
                    $lesson_assessments[$lesson_id][$assessment['type']][] = $assessment_data;
                }
            }
        }

        $first_blocking_chapter_id = null;
        $first_blocking_lesson_id = null;
        $blocking_chapter_id = null;
        $found_blocker = false;

        foreach ($chapters as $chapter) {
            if ($found_blocker)
                break;

            if (isset($chapter_assessments[$chapter['id']])) {
                foreach (['exercise', 'exam'] as $type) {
                    if ($found_blocker)
                        break;
                    foreach ($chapter_assessments[$chapter['id']][$type] as $assessment) {
                        if ($assessment['unlock_next'] && !isset($completed_assessments[$assessment['id']])) {
                            $first_blocking_chapter_id = $chapter['id'];
                            $found_blocker = true;
                            break;
                        }
                    }
                }
            }

            $lessons_sql = "SELECT id 
                       FROM {$this->table['chapter_lessons']} 
                       WHERE chapter_id = ?
                       ORDER BY id ASC";
            $chapter_lessons = $this->getData($lessons_sql, [$chapter['id']], true);

            if ($chapter_lessons) {
                foreach ($chapter_lessons as $lesson) {
                    if ($found_blocker)
                        break;

                    if (isset($lesson_assessments[$lesson['id']])) {
                        foreach (['exercise', 'exam'] as $type) {
                            if ($found_blocker)
                                break;
                            foreach ($lesson_assessments[$lesson['id']][$type] as $assessment) {
                                if ($assessment['unlock_next'] && !isset($completed_assessments[$assessment['id']])) {
                                    $first_blocking_lesson_id = $lesson['id'];
                                    $blocking_chapter_id = $chapter['id'];
                                    $found_blocker = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($chapters as &$chapter) {
            $chapter_locked = false;

            if ($first_blocking_chapter_id && $chapter['id'] > $first_blocking_chapter_id) {
                $chapter_locked = true;
            }

            if ($blocking_chapter_id && $chapter['id'] > $blocking_chapter_id) {
                $chapter_locked = true;
            }

            $lessons_sql = "SELECT 
                            id, title, `length`, free
                        FROM {$this->table['chapter_lessons']} 
                        WHERE chapter_id = ?
                        ORDER BY id ASC";
            $lessons = $this->getData($lessons_sql, [$chapter['id']], true);

            $chapter['lessons_detail'] = [];

            if ($lessons) {
                foreach ($lessons as $lesson) {
                    $lesson_locked = $chapter_locked;

                    if (
                        $blocking_chapter_id && $chapter['id'] == $blocking_chapter_id &&
                        $first_blocking_lesson_id && $lesson['id'] > $first_blocking_lesson_id
                    ) {
                        $lesson_locked = true;
                    }

                    $lesson_data = [
                        'id' => (int) $lesson['id'],
                        'title' => $lesson['title'],
                        'length' => (int) $lesson['length'],
                        'free' => (bool) $lesson['free']
                    ];

                    if (($lesson['free'] || $course_student) && !$lesson_locked) {
                        $link_size_sql = "SELECT link, size 
                                     FROM {$this->table['chapter_lessons']} 
                                     WHERE id = ?";
                        $link_size = $this->getData($link_size_sql, [$lesson['id']]);
                        if ($link_size) {
                            $lesson_data['link'] = $link_size['link'];
                            $lesson_data['size'] = $link_size['size'] ? (int) $link_size['size'] : null;
                        }
                    }

                    if (isset($lesson_assessments[$lesson['id']])) {
                        foreach (['exercise', 'exam'] as $type) {
                            if (!empty($lesson_assessments[$lesson['id']][$type])) {
                                $lesson_data[$type] = array_map(function ($assessment) use ($lesson_locked, $first_blocking_lesson_id, $lesson) {
                                    if ($first_blocking_lesson_id && $lesson['id'] > $first_blocking_lesson_id) {
                                        $assessment['lock'] = true;
                                    } else {
                                        $assessment['lock'] = $lesson_locked;
                                    }
                                    return $assessment;
                                }, $lesson_assessments[$lesson['id']][$type]);
                            }
                        }
                    }

                    if ($lesson_locked) {
                        $lesson_data['lock'] = true;
                    }

                    $chapter['lessons_detail'][] = $lesson_data;
                }
            }

            if (isset($chapter_assessments[$chapter['id']])) {
                foreach (['exercise', 'exam'] as $type) {
                    if (!empty($chapter_assessments[$chapter['id']][$type])) {
                        $chapter[$type] = array_map(function ($assessment) use ($chapter_locked, $blocking_chapter_id, $chapter) {
                            if ($blocking_chapter_id && $chapter['id'] == $blocking_chapter_id) {
                                $assessment['lock'] = true;
                            } else {
                                $assessment['lock'] = $chapter_locked;
                            }
                            return $assessment;
                        }, $chapter_assessments[$chapter['id']][$type]);
                    }
                }
            }

            if ($chapter_locked) {
                $chapter['lock'] = true;
            }

            $chapter['id'] = (int) $chapter['id'];
            $chapter['lessons_count'] = (int) $chapter['lessons_count'];
            $chapter['chapter_length'] = (int) $chapter['chapter_length'];
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
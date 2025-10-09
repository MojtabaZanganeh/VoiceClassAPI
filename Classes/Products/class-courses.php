<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Users\Users;

class Courses extends Products
{
    use Base, Sanitizer;

    public function get_all_courses($params)
    {
        $query = $params['query'] ?? null;
        $category = $params['category'] ?? null;
        $level = $params['level'] ?? null;
        $access_type = $params['access_type'] ?? null;
        $sort = $params['sort'] ?? 'newest';
        $current_page = $params['current_page'] ?? 0;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 12) ? $params['per_page_count'] : 12;

        $where_condition = '';
        $bindParams = [];

        if ($query) {
            $where_condition .= " AND p.title LIKE ?";
            $bindParams[] = "%{$query}%";
        }

        if ($category && is_numeric($category) && $category > 0) {
            $where_condition .= " AND p.category_id = ?";
            $bindParams[] = $category;
        }

        if ($level && in_array($level, ['beginner', 'intermediate', 'advanced', 'expert'])) {
            $where_condition .= " AND p.level = ?";
            $bindParams[] = $level;
        }

        if ($access_type && in_array($access_type, ['online', 'recorded'])) {
            $where_condition .= " AND cd.access_type = ?";
            $bindParams[] = $access_type;
        }

        $bindParams[] = $per_page_count;
        $bindParams[] = $current_page * $per_page_count;

        switch ($sort) {
            case 'newest':
                $sort_condition = 'p.created_at DESC';
                break;

            case 'rating':
                $sort_condition = 'p.rating_avg DESC';
                break;

            case 'students':
                $sort_condition = 'p.students DESC';
                break;

            case 'price_asc':
                $sort_condition = '(p.price - p.discount_amount) ASC';
                break;

            case 'price_desc':
                $sort_condition = '(p.price - p.discount_amount) DESC';
                break;

            default:
                $sort_condition = 'p.created_at DESC';
                break;
        }

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
                    cd.access_type,
                    cd.duration
                FROM {$this->table['products']} p
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                LEFT JOIN {$this->table['course_details']} cd ON p.id = cd.product_id
                WHERE p.type = 'course' AND p.status = 'verified' AND p.instructor_active = 1 $where_condition
                GROUP BY p.id
                ORDER BY $sort_condition
                LIMIT ? OFFSET ?
        ";
        $all_courses = $this->getData($sql, $bindParams, true);

        if (!$all_courses) {
            Response::success('دوره ای یافت نشد', 'allCourses', []);
        }

        foreach ($all_courses as &$course) {
            $course['thumbnail'] = $this->get_full_image_url($course['thumbnail']);
            $course['instructor'] = json_decode($course['instructor'], true);
            $course['instructor']['avatar'] = $this->get_full_image_url($course['instructor']['avatar']);
        }
        Response::success('دوره ها دریافت شد', 'allCourses', $all_courses);
    }

    public function get_course_by_slug($params)
    {
        $this->check_params($params, ['slug']);

        $where_condition = $this->check_role(['admin'], false) === false ? " AND p.status = 'verified' AND p.instructor_active = 1 " : '';

        $sql = "SELECT
                    p.uuid,
                    p.status,
                    p.instructor_active,
                    p.category_id,
                    pc.name AS category,
                    p.thumbnail,
                    p.title,
                    JSON_OBJECT(
                        'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa),
                        'avatar', u.avatar,
                        'professional_title', i.professional_title,
                        'bio', i.bio,
                        'rating_avg', i.rating_avg,
                        'rating_count', i.rating_count,
                        'students', i.students,
                        'courses_taught', i.courses_taught
                    ) AS instructor,
                    p.introduction,
                    p.description,
                    p.what_you_learn,
                    p.requirements,
                    p.level,
                    p.price,
                    p.discount_amount,
                    p.rating_avg,
                    p.rating_count,
                    p.students,
                    cd.access_type,
                    cd.duration,
                    cd.all_lessons_count,
                    (
                        SELECT COUNT(*)
                        FROM {$this->table['chapter_lessons']} cl
                        INNER JOIN {$this->table['chapters']} c ON cl.chapter_id = c.id
                        WHERE c.product_id = p.id AND cl.link IS NOT NULL
                    ) as record_progress,
                    cd.online_price,
                    cd.online_discount_amount
                FROM {$this->table['products']} p
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                LEFT JOIN {$this->table['course_details']} cd ON p.id = cd.product_id
                WHERE p.slug = ? $where_condition
                ORDER BY p.created_at DESC
                LIMIT 1;
        ";
        $course = $this->getData($sql, [$params['slug']]);

        if (!$course) {
            Response::error('دوره ای یافت نشد');
        }

        $course['thumbnail'] = $this->get_full_image_url($course['thumbnail']);
        $course['instructor'] = json_decode($course['instructor'], true);
        $course['instructor']['avatar'] = $this->get_full_image_url($course['instructor']['avatar']);
        $course['what_you_learn'] = json_decode($course['what_you_learn'], true);
        $course['requirements'] = json_decode($course['requirements'], true);

        Response::success('دوره دریافت شد', 'course', $course);
    }

    public function get_user_courses()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    p.uuid,
                    p.level,
                    p.title,
                    p.thumbnail,
                    JSON_OBJECT(
                    'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                    ) AS instructor,
                    cd.duration,
                    o.uuid AS order_uuid,
                    oi.uuid AS item_uuid,
                    oi.access_type,
                    oi.status,
                    oi.updated_at
                FROM {$this->table['orders']} o
                LEFT JOIN {$this->table['order_items']} oi ON o.id = oi.order_id
                LEFT JOIN {$this->table['products']} p ON oi.product_id = p.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['course_details']} cd ON p.id = cd.product_id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                    WHERE o.user_id = ? AND p.type = 'course'
                GROUP BY o.id, oi.id
                ORDER BY oi.updated_at DESC
        ";
        $user_courses = $this->getData($sql, [$user['id']], true);

        if (!$user_courses) {
            Response::success('دوره ای یافت نشد', 'userCourses', []);
        }

        foreach ($user_courses as &$user_course) {
            $user_course['instructor'] = json_decode($user_course['instructor']);
            $user_course['thumbnail'] = $this->get_full_image_url($user_course['thumbnail']);
        }

        Response::success('دوره های کاربر دریافت شد', 'userCourses', $user_courses);
    }
}
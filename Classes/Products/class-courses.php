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
        $this->get_products(
            params: $params,
            type: 'course',
            details_table: 'course_details',
            select_fields: 'dt.access_type, dt.duration',
            access_types: ['online', 'recorded'],
            not_found_message: 'دوره ای یافت نشد',
            success_message: 'دوره ها دریافت شد',
            response_key: 'allCourses'
        );
    }

    public function get_course_by_slug($params)
    {
        $this->get_product_by_slug($params, [
            'details_table' => 'course_details',
            'select_fields' => "
                dt.access_type,
                dt.duration,
                dt.all_lessons_count,
                (
                    SELECT COUNT(*)
                    FROM {$this->table['chapter_lessons']} cl
                    INNER JOIN {$this->table['chapters']} c ON cl.chapter_id = c.id
                    WHERE c.product_id = p.id AND cl.link IS NOT NULL
                ) AS record_progress,
                dt.online_price,
                dt.online_discount_amount
            ",
            'instructor_stats_field' => 'courses_taught',
            'special_processing' => null,
            'messages' => [
                'not_found' => 'دوره ای یافت نشد',
                'success' => 'دوره دریافت شد',
                'response_key' => 'course'
            ]
        ]);
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
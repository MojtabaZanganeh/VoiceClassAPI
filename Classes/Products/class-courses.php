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

    private function generate_slug($input, $timestamp = '')
    {
        $output = preg_replace('/[^a-zA-Z0-9\s\-_\x{0600}-\x{06FF}]/u', '', $input);
        $output .= jdate(' d F Y', $timestamp, '', '', 'en');
        $output = preg_replace('/\s+/', '-', $output);
        $output = strtolower($output);
        $output = trim($output, '-');
        return $output;
    }

    public function get_user_courses()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    p.title,
                    p.thumbnail,
                    JSON_OBJECT(
                    'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                    ) AS instructor,
                    cd.duration,
                    p.level,
                    cs.progress AS user_progress
                FROM {$this->table['course_students']} cs
                LEFT JOIN {$this->table['products']} p ON cs.course_id = p.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                LEFT JOIN {$this->table['course_details']} cd ON p.id = cd.product_id
                    WHERE cs.user_id = ?
                GROUP BY cs.id, p.id, i.id, up.id, cd.id
                ORDER BY cs.enrolled_at DESC
        ";
        $user_courses = $this->getData($sql, [$user['id']], true);

        if (!$user_courses) {
            Response::error('خطا در دریافت دوره های کاربر');
        }
        
        foreach ($user_courses as &$user_course) {
            $user_course['instructor'] = json_decode($user_course['instructor']);
            $user_course['thumbnail'] = $this->get_full_image_url($user_course['thumbnail']);
        }

        Response::success('دوره های کاربر دریافت شد', 'userCourses', $user_courses);
    }
}
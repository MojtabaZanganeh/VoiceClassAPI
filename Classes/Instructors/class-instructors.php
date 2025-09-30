<?php
namespace Classes\Instructors;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Products\Categories;
use Classes\Users\Users;

class Instructors extends Users
{
    use Base, Sanitizer;

    public function get_instructors($params)
    {
        $columns = isset($params['select']) && $params['select'] === 'true' 
        ? 'i.id, i.professional_title, i.professional_title, ' 
        : "i.*, ";
        $sql = "SELECT
                    $columns
                    u.avatar,
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS name
                FROM {$this->table['instructors']} i
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                GROUP BY i.id
        ";
        $all_instructors = $this->getData($sql, [], true);

        if (!$all_instructors) {
            Response::error('خطا در دریافت مدرسین');
        }
        
        $category_obj = new Categories();
        
        foreach ($all_instructors as &$instructor) {
            $instructor['avatar'] = $this->get_full_image_url($instructor['avatar']);
            $instructor['categories'] =  $category_obj->get_categories_by_id(json_decode($instructor['categories_id'], true));
        }
        
        Response::success('مدرسین دریافت شدند', 'allInstructors', $all_instructors);
    }
}

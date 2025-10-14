<?php
namespace Classes\Instructors;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Products\Categories;
use Classes\Users\Users;

class Instructors extends Users
{
    use Base, Sanitizer;

    public function get_instructor_by_user_id($user_id, $columns = '*')
    {
        $instructor = $this->getData(
            "SELECT $columns FROM {$this->table['instructors']} WHERE user_id = ?",
            [$user_id]
        );

        if (!$instructor) {
            Response::error('مدرس یافت نشد');
        }
        return $instructor;
    }

    public function add_new_instructor($params)
    {
        $admin = $this->check_role(['admin']);

        $this->check_params($params, ['professional_title', 'bio', 'categories', ['user_uuid', 'first_name']]);

        $current_time = $this->current_time();

        $db = new Database();
        $db->beginTransaction();

        if (!empty($params['user_uuid'])) {
            $user = $db->getData(
                "SELECT * FROM {$db->table['users']} WHERE uuid = ?",
                [$params['user_uuid']]
            );

            if (!$user) {
                Response::error('کاربر یافت نشد', null, 400, $db);
            }

            $user_id = $user['id'];
            $full_name = $user['first_name'] . ' ' . $user['last_name'];
            $phone = $user['phone'];
        } else {
            $first_name = $this->check_input($params['first_name'], 'fa_name', 'نام');
            $last_name = $this->check_input($params['last_name'], 'fa_name', 'نام خانوادگی');

            $phone = !empty($params['phone']) ? $this->check_input($params['phone'], 'phone', 'شماره تماس') : null;
            $user_by_phone = $this->get_user_by_phone($phone);
            if ($user_by_phone) {
                Response::error('شماره قبلاً ثبت شده است.', null, 400, $db);
            }

            $email = !empty($params['email']) ? $this->check_input($params['email'], 'email', 'ایمیل') : null;
            $user_by_email = $this->get_user_by_email($email);
            if ($user_by_email) {
                Response::error('ایمیل قبلاً ثبت شده است.', null, 400, $db);
            }

            if (empty($_FILES['avatar'])) {
                Response::error('پروفایل ارسال نشده است', null, 400, $db);
            }
            $avatar = $this->handle_file_upload($_FILES['avatar'], 'Uploads/Avatars/');
            if (!$avatar) {
                Response::error('خطا در ذخیره پروفایل', null, 500, $db);
            }

            $user_uuid = $this->generate_uuid();
            $username = $phone ? "user-$phone" : "guest_user";
            $password = $this->get_random('pass', 12);
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $user_id = $db->insertData(
                "INSERT INTO {$db->table['users']} 
                        (`uuid`, `username`, `phone`, `email`, `password`, `role`, `avatar`, `is_active`, `registered_at`)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $user_uuid,
                    $username,
                    $phone,
                    $email,
                    $password_hash,
                    'user',
                    $avatar,
                    true,
                    $current_time
                ]
            );

            if (!$user_id) {
                Response::error('خطا در افزودن کاربر', null, 500, $db);
            }

            $user_profile = $db->insertData(
                "INSERT INTO {$db->table['user_profiles']} (`user_id`, `first_name_fa`, `last_name_fa`) VALUES (?, ?, ?)",
                [
                    $user_id,
                    $first_name,
                    $last_name
                ]
            );

            if (!$user_profile) {
                Response::error('خطا در افزودن اطلاعات کاربر', null, 500, $db);
            }

            $full_name = $first_name . ' ' . $last_name;
        }

        $professional_title = $this->check_input($params['professional_title'], null, 'عنوان تخصصی', '/^.{1,100}$/u');
        $bio = $this->check_input($params['bio'], null, 'بایو', '/^.{50,}$/us');
        $categories_name = $this->check_input(json_decode($params['categories'], true), 'array', 'دسته بندی');
        $categoris_id = [];
        foreach ($categories_name as $category) {
            $category_id = $this->getData(
                "SELECT id FROM {$this->table['categories']} WHERE `name` = ?",
                [$category]
            );

            if (!$category_id) {
                Response::error("دسته بندی $category یافت نشد", null, 400, $db);
            }

            $categoris_id[] = $category_id['id'];
        }

        $instructor_uuid = $this->generate_uuid();

        $random_sku = $this->get_random('int', 4, $this->table['instructors'], 'slug');
        $slug = $this->generate_slug("$full_name $professional_title", $random_sku);

        $instructor_id = $db->insertData(
            "INSERT INTO {$db->table['instructors']} 
                    (`uuid`, `user_id`, `status`, `slug`, `professional_title`, `bio`, `categories_id`, `registered_as_instructor`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $instructor_uuid,
                $user_id,
                'active',
                $slug,
                $professional_title,
                $bio,
                json_encode($categoris_id),
                $current_time
            ]
        );

        if (!$instructor_id) {
            Response::error('خطا در افزودن مدرس', null, 500, $db);
        }

        $update_user_role = $db->updateData(
            "UPDATE {$db->table['users']} SET `role` = 'instructor' WHERE id = ?",
            [$user_id]
        );

        if (!$update_user_role) {
            Response::error('خطا در تغییر نقش کاربر', null, 500, $db);
        }

        $db->commit();

        $send_sms_result = $this->send_sms(
            $phone,
            $_ENV["ADD_INSTRUCTOR_TEMPLATE_ID"],
            [
                ['name' => 'username', 'value' => $phone],
                ['name' => 'password', 'value' => $password]
            ]
        );

        Response::success('مدرس افزوده شد' . ($send_sms_result ? '' : ' اما پیامک ارسال نشد'));
    }

    public function update_instructor_info($params)
    {
        $this->check_role(['admin']);
        $this->check_params($params, ['uuid', 'professional_title', 'bio', 'categories', 'status']);

        $instructor = $this->getData(
            "SELECT id FROM {$this->table['instructors']} WHERE uuid = ?",
            [$params['uuid']]
        );

        if (!$instructor) {
            Response::error('مدرس یافت نشد');
        }

        $professional_title = $this->check_input($params['professional_title'], null, 'عنوان تخصصی', '/^.{1,100}$/u');
        $bio = $this->check_input($params['bio'], null, 'بایو', '/^.{50,}$/us');

        $categories_name = $this->check_input($params['categories'], 'array', 'دسته بندی');
        $categoris_id = [];
        foreach ($categories_name as $category) {
            $category_id = $this->getData(
                "SELECT id FROM {$this->table['categories']} WHERE `name` = ?",
                [$category]
            );

            if (!$category_id) {
                Response::error("دسته بندی $category یافت نشد");
            }

            $categoris_id[] = $category_id['id'];
        }

        $status = $params['status'];
        if (!in_array($status, ['active', 'inactive', 'suspended'])) {
            Response::error('وضعیت معتبر نیست');
        }

        $db = new Database();
        $db->beginTransaction();

        $update_info = $db->updateData(
            "UPDATE {$db->table['instructors']} SET `professional_title` = ?, `bio` = ?, `categories_id` = ?, `status` = ? WHERE id = ?",
            [
                $professional_title,
                $bio,
                json_encode($categoris_id),
                $status,
                $instructor['id']
            ]
        );

        if (!$update_info) {
            Response::error('خطا در بروزرسانی اطلاعات مدرس', null, 500, $db);
        }

        $instructor_products = $db->getData(
            "SELECT `id`, `status` FROM {$db->table['products']} WHERE instructor_id = ?",
            [$instructor['id']],
            true
        );

        if (!$instructor_products) {
            Response::error('خطا در دریافت محصولات مدرس', null, 500, $db);
        }

        $instructor_active = $status === 'active' ? 1 : 0;

        foreach ($instructor_products as $product) {
            $update_instructor_active = $db->updateData(
                "UPDATE {$db->table['products']} SET `instructor_active` = ? WHERE `instructor_id` = ?",
                [
                    $instructor_active,
                    $instructor['id']
                ]
            );

            if (!$update_instructor_active) {
                Response::error('خطا در بروزرسانی وضعیت محصول', null, 500, $db);
            }
        }

        $db->commit();

        Response::success('اطلاعات مدرس بروز شد');
    }
    public function get_instructors($params)
    {

        $is_admin = false;
        if (!empty($params['admin']) && $params['admin'] === 'true') {
            $admin = $this->check_role(['admin']);
            $is_admin = true;
            $statsSql = "SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
                        FROM {$this->table['instructors']}";

            $stats = $this->getData($statsSql, []);
        }

        $columns = isset($params['select']) && $params['select'] === 'true'
            ? 'i.uuid, i.professional_title, i.professional_title, '
            : "i.*, ";
        $admin_columns = $is_admin ? "u.phone, u.email," : "";

        $where = !$is_admin ? " WHERE i.status = 'active' " : "";
        $sql = "SELECT
                    $columns
                    $admin_columns
                    u.avatar,
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS name
                FROM {$this->table['instructors']} i
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                $where
                GROUP BY i.id
        ";
        $all_instructors = $this->getData($sql, [], true);

        if (!$all_instructors) {
            Response::success('خطا در دریافت مدرسین', 'instructorsData', [
                'instructors' => [],
                'stats' => $stats
            ]);
        }

        $category_obj = new Categories();

        foreach ($all_instructors as &$instructor) {
            $instructor['avatar'] = $this->get_full_image_url($instructor['avatar']);
            $instructor['categories_id'] = !empty($instructor['categories_id']) ? json_decode($instructor['categories_id'], true) : [];
            $instructor['categories'] = !empty($instructor['categories_id']) ? $category_obj->get_categories_by_id($instructor['categories_id']) : '';
            if (!$is_admin) {
                unset($instructor['share_percent']);
                unset($instructor['total_earnings']);
                unset($instructor['unpaid_earnings']);
                unset($instructor['paid_earnings']);
            } else {
                $instructor['paid_earnings'] = $instructor['total_earnings'] - $instructor['unpaid_earnings'];
            }
        }

        if ($is_admin) {
            Response::success('مدرسین دریافت شدند', 'instructorsData', [
                'instructors' => $all_instructors,
                'stats' => $stats
            ]);
        }

        Response::success('مدرسین دریافت شدند', 'allInstructors', $all_instructors);
    }

}

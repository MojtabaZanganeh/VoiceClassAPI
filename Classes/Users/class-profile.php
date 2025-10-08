<?php
namespace Classes\Users;

use Classes\Base\Error;
use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Classes\Leaders\Leaders;

/**
 * Class User
 *
 * Manages user-related operations such as retrieving user details by phone number and username.
 *
 * @package Classes\User
 */
class Profile extends Users
{
    use Sanitizer;

    public function get_shipping_info()
    {
        $user = $this->check_role();

        $sql = "SELECT
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS receiver_name,
                    u.phone AS receiver_phone,
                    ua.province,
                    ua.city,
                    ua.full_address,
                    ua.postal_code
                FROM {$this->table['users']} u
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                LEFT JOIN {$this->table['user_addresses']} ua ON u.id = ua.user_id
                WHERE u.id = ?
        ";
        $user_shipping_info = $this->getData($sql, [$user['id']]);

        if (!$user_shipping_info) {
            Response::error('خطا در دریافت اطلاعات کاربر');
        }

        Response::success('اطلاعات دریافت شد', 'userShippingInfo', $user_shipping_info);
    }

    public function update_user_profile($params)
    {
        $user = $this->check_role();
        $this->check_params($params, [['profile', 'certificate', 'address']]);

        $current_time = $this->current_time();

        $provinces = json_decode(file_get_contents('Data/provinces.json'), true);
        $cities = json_decode(file_get_contents('Data/cities.json'), true);

        $getProvinceCity = function ($provinceName, $cityName) use ($provinces, $cities) {
            $provinceList = array_filter($provinces, fn($p) => $p['name'] === $provinceName);
            $province = !empty($provinceList) ? array_values($provinceList)[0] : null;

            $provinceId = $province['id'] ?? null;
            $cityList = !empty($provinceId)
                ? array_filter($cities, fn($c) => $c['name'] === $cityName && $c['province_id'] === $provinceId)
                : [];

            $city = !empty($cityList) ? array_values($cityList)[0] : null;

            return [$province['name'] ?? null, $city['name'] ?? null];
        };

        if (isset($params['profile'])) {
            $profile = $params['profile'];

            $first_name_fa = $this->check_input($profile['first_name_fa'], 'fa_name', 'نام فارسی');
            $last_name_fa = $this->check_input($profile['last_name_fa'], 'fa_name', 'نام خانوادگی فارسی');

            $gender = $profile['gender'];
            if (!in_array($gender, ['male', 'female'])) {
                Response::error('جنسیت معتبر نیست');
            }

            $birth_date = $this->convert_jalali_to_miladi($profile['birth_date']);
            [$province, $city] = $getProvinceCity($profile['province'], $profile['city']);

            $sql = "INSERT INTO {$this->table['user_profiles']}
                (user_id, first_name_fa, last_name_fa, gender, birth_date, province, city, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                first_name_fa = VALUES(first_name_fa),
                last_name_fa  = VALUES(last_name_fa),
                gender        = VALUES(gender),
                birth_date    = VALUES(birth_date),
                province      = VALUES(province),
                city          = VALUES(city),
                updated_at    = VALUES(updated_at)";

            $update_data = $this->insertData($sql, [
                $user['id'],
                $first_name_fa,
                $last_name_fa,
                $gender,
                $birth_date,
                $province,
                $city,
                $current_time,
                $current_time
            ]);
        }

        elseif (isset($params['certificate'])) {
            $certificate = $params['certificate'];

            $first_name_en = $this->check_input($certificate['first_name_en'], 'en_name', 'نام انگلیسی');
            $last_name_en = $this->check_input($certificate['last_name_en'], 'en_name', 'نام خانوادگی انگلیسی');
            $father_name = $this->check_input($certificate['father_name'], 'fa_text', 'نام پدر');
            $national_id = $this->check_input($certificate['national_id'], 'national_id', 'کد ملی');

            $sql = "INSERT INTO {$this->table['user_certificates']}
                (user_id, first_name_en, last_name_en, father_name, national_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                first_name_en = VALUES(first_name_en),
                last_name_en  = VALUES(last_name_en),
                father_name   = VALUES(father_name),
                national_id   = VALUES(national_id),
                updated_at    = VALUES(updated_at)";

            $update_data = $this->insertData($sql, [
                $user['id'],
                $first_name_en,
                $last_name_en,
                $father_name,
                $national_id,
                $current_time,
                $current_time
            ]);
        }

        elseif (isset($params['address'])) {
            $address = $params['address'];

            [$province, $city] = $getProvinceCity($address['province'], $address['city']);

            $full_address = $this->check_input($address['full_address'], null, 'آدرس دقیق', '/^[\x{0600}-\x{06FF}\s\x{200c}\-\x{060C},._\/#()\"\'’\d\x{06F0}-\x{06F9}]+$/u');
            $postal_code = $this->check_input($address['postal_code'], 'postal_code', 'کد پستی');
            $receiver_phone = $this->check_input($address['receiver_phone'], 'phone', 'شماره تماس گیرنده');

            $sql = "INSERT INTO {$this->table['user_addresses']}
                (user_id, province, city, full_address, postal_code, receiver_phone, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                province       = VALUES(province),
                city           = VALUES(city),
                full_address   = VALUES(full_address),
                postal_code    = VALUES(postal_code),
                receiver_phone = VALUES(receiver_phone),
                updated_at     = VALUES(updated_at)";

            $update_data = $this->insertData($sql, [
                $user['id'],
                $province,
                $city,
                $full_address,
                $postal_code,
                $receiver_phone,
                $current_time,
                $current_time
            ]);
        }

        if (!$update_data) {
            Response::error('خطا در بروزرسانی پروفایل');
        }

        Response::success('اطلاعات پروفایل بروز شد');
    }

    public function update_user_avatar()
    {
        $user = $this->check_role();

        if ($_FILES['avatar']['size'] > 512 * 1024) {
            Response::error('حجم تصویر نباید بیشتر از ۵۱۲ کیلوبایت باشد');
        }

        $upload_dir = 'Uploads/Avatars/';
        $uuid = $this->generate_uuid();
        $avatar_url = (isset($_FILES['avatar']) && $_FILES['avatar']['size'] > 0) ? $this->handle_file_upload($_FILES['avatar'], $upload_dir, $uuid) : null;

        if (!$avatar_url) {
            Response::error('خطا در ذخیره تصویر');
        }

        $this->beginTransaction();

        $get_previous_avatar = $this->getData("SELECT avatar FROM {$this->table['users']} WHERE id = ?", [$user['id']]);
        $previous_avatar = $get_previous_avatar['avatar'];

        if ($previous_avatar !== null && is_file($previous_avatar)) {
            if (!unlink($previous_avatar)) {
                error_log("Failed to Delete Previous User Avatar. Avatar: " . $previous_avatar);
            }
        }

        $update_avatar = $this->updateData(
            "UPDATE {$this->table['users']} SET `avatar` = ? WHERE `id` = ?",
            [$avatar_url, $user['id']]
        );

        if (!$update_avatar) {
            Response::error('خطا در ثبت تصویر');
        }

        $this->commit();

        $avatar_full_url = $this->get_full_image_url($avatar_url);

        Response::success('تصویر آپلود شد', 'avatarUrl', $avatar_full_url);
    }
}
<?php
namespace Classes\Users;

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

    public function get_profile($params)
    {
        $user = $this->check_role();

        unset($user['id']);
        unset($user['password']);

        Response::success('اطلاعات پروفایل دریافت شد', 'profileData', $user);
    }

    public function update_user_profile($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['profileData']);

        $profile_data = $params['profileData'];
        $birth_date = $profile_data['birth_date'] ? $this->convert_jalali_to_miladi($profile_data['birth_date']) : null;
        $gender = $profile_data['gender'] ?? null;

        $update_profile = $this->updateData(
            "UPDATE {$this->table['users']} SET `birth_date` = ?, `gender` = ? WHERE `id` = ?",
            [$birth_date, $gender, $user['id']]
        );

        if ($update_profile) {

            if (isset($params['leaderData']) && $user['role'] === 'leader') {
                $leader_obj = new Leaders();
                $leader_obj->update_leader_profile($params['leaderData']);
            }

            Response::success('پروفایل بروزرسانی شد');
        }

        Response::error('خطا در بروزرسانی پروفایل');
    }

    public function update_user_avatar()
    {
        $user = $this->check_role();

        $upload_dir = 'Uploads/Avatars/';
        $uuid = $this->generate_uuid();
        $avatar_url = (isset($_FILES['avatar']) && $_FILES['avatar']['size'] > 0) ? $this->handle_file_upload($_FILES['avatar'], $upload_dir, $uuid) : null;

        if (!$avatar_url) {
            Response::error('خطا در ذخیره تصویر');
        }

        $this->beginTransaction();

        $get_previous_avatar = $this->getData("SELECT avatar FROM {$this->table['users']} WHERE id = ?", [$user['id']]);
        $previous_avatar = $get_previous_avatar['avatar'];

        if (is_file($previous_avatar)) {
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
<?php
namespace Classes\Notifications;

use Classes\Users\Users;
use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;

class Notifications extends Users
{
    use Base, Sanitizer;

    public function get_user_notifications()
    {
        $user = $this->check_role();

        $sql = "SELECT * FROM {$this->table['notifications']} WHERE user_id = ?";

        $notifications = $this->getData($sql, [$user['id']], true);

        if (!$notifications) {
            Response::success('تراکنشی یافت نشد');
        }

        Response::success('اعلان ها دریافت شد', 'userNotifications', $notifications);
    }

    public function read_notification($params)
    {
        $user = $this->check_role();

        $this->check_params($params, [['notification_id', 'read_all']]);

        $sql = "UPDATE {$this->table['notifications']} SET `read` = 1 WHERE user_id = ?";
        $execute = [$user['id']];

        if (isset($params['notification_id'])) {
            $sql .= " AND id = ?";
            $execute[] = $params['notification_id'];
        }

        $read_notification = $this->updateData($sql, $execute);

        if ($read_notification) {
            Response::success('اعلان به عنوان خوانده شده علامت گذاری شد');
        }

        Response::error('خطا در علامت گذاری اعلان به عنوان خوانده شده');
    }
}
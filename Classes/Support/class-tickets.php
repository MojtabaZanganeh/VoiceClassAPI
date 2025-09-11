<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Users\Users;

class Tickets extends Users
{
    use Base, Sanitizer;

    public function get_user_tickets()
    {
        $user = $this->check_role();

        $sql = "SELECT * FROM {$this->table['support_tickets']} WHERE user_id = ?";

        $tickets = $this->getData($sql, [$user['id']], true);

        if (!$tickets) {
            Response::success('تیکتی یافت نشد');
        }

        Response::success('تیکت های پشتیبانی دریافت شد', 'userSupportTickets', $tickets);
    }
}
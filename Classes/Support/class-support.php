<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Users\Users;

class Support extends Users
{
    use Base, Sanitizer;

    public function join_us_request($params)
    {
        $full_name = $this->check_input($params['full_name'], 'fa_full_name', 'نام و نام خانوادگی');
        $phone = $this->check_input($params['phone_number'], 'phone', 'شماره تماس');
        $email = $this->check_input($params['email'], 'email', 'ایمیل');
        $resume = $this->check_input($params['resume'], null, 'رزومه', '/^.{50,}$/us');
        $demo_course_link = $this->check_input($params['sample_video'], 'url', 'لینک نمونه ویدیو تدریس');
        $demo_book_link = $params['sample_document'] ? $this->check_input($params['sample_document'], 'url', 'لینک نمونه جزوه') : null;

        $current_time = $this->current_time();

        $exists = $this->getData(
            "SELECT id FROM {$this->table['join_us_requests']} WHERE phone = ? OR email = ? LIMIT 1",
            [$phone, $email]
        );

        if ($exists) {
            Response::error('این شماره تماس یا ایمیل قبلاً ثبت شده است');
        }

        $add_request = $this->insertData(
            "INSERT INTO {$this->table['join_us_requests']}
                    (`name`, `phone`, `email`, `resume`, `demo_course_link`, `demo_book_link`, `status`, `created_at`, `updated_at`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $full_name,
                $phone,
                $email,
                $resume,
                $demo_course_link,
                $demo_book_link,
                'pending',
                $current_time,
                $current_time
            ]
        );

        if (!$add_request) {
            Response::error('خطا در ثبت درخواست همکاری');
        }

        Response::success('درخواست همکاری ثبت شد');
    }
}
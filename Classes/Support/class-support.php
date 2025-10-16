<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Users\Users;
use Exception;

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

        $uuid = $this->generate_uuid();

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
                    (`uuid`, `name`, `phone`, `email`, `resume`, `demo_course_link`, `demo_book_link`, `status`, `created_at`, `updated_at`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
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

    public function get_all_join_us_requests($params)
    {
        $admin = $this->check_role(['admin']);

        $statsSql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'interview' THEN 1 ELSE 0 END) as interview,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                    FROM {$this->table['join_us_requests']}
        ";

        $stats = $this->getData($statsSql, []);

        $where_condition = '';
        $bind_params = [];

        if (!empty($params['status']) && in_array($params['status'], ['pending', 'interview', 'approved', 'rejected'])) {
            $where_condition .= ' WHERE status = ? ';
            $bind_params[] = $params['status'];
        }

        if (!empty($params['q'])) {
            $query = $params['q'];
            $condition = 'phone LIKE ? OR name LIKE ? OR UPPER(email) LIKE UPPER(?) OR UPPER(resume) LIKE UPPER(?)';
            if ($where_condition === '') {
                $where_condition .= " WHERE $condition";
            } else {
                $where_condition .= " AND $condition";
            }
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
            $bind_params[] = "%$query%";
        }

        $current_page = isset($params['current_page']) ? max(((int) $params['current_page'] - 1), 0) : 0;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 20)
            ? (int) $params['per_page_count']
            : 20;

        $offset = $current_page * $per_page_count;

        $bind_params_ids = array_merge($bind_params, [$per_page_count, $offset]);

        $requests = $this->getData(
            "SELECT * FROM {$this->table['join_us_requests']} 
                    $where_condition
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?",
            $bind_params_ids,
            true
        );

        if (!$requests) {
            Response::success('درخواستی یافت نشد', 'requestsData', [
                'requests' => [],
                'stats' => $stats
            ]);
        }

        Response::success('درخواستی یافت نشد', 'requestsData', [
            'requests' => $requests,
            'stats' => $stats
        ]);
    }

    public function update_join_us_request_status($params)
    {
        $admin = $this->check_role(['admin']);

        $this->check_params($params, ['request_uuid', 'status']);

        $request = $this->getData(
            "SELECT `name`, `email`, `status` FROM {$this->table['join_us_requests']} WHERE uuid = ?",
            [$params['request_uuid']]
        );

        if (empty($request)) {
            Response::error('درخواست یافت نشد');
        }

        if ($request['status'] === 'approved') {
            Response::error('درخواست قبلاً تایید شده است و امکان تغییر وجود ندارد');
        }

        $db = new Database();
        $db->beginTransaction();

        $update_transaction = $db->updateData(
            "UPDATE {$db->table['join_us_requests']} SET `status` = ? WHERE uuid = ?",
            [
                $params['status'],
                $params['request_uuid']
            ]
        );

        if (!$update_transaction) {
            Response::error('خطا در تغییر وضعیت درخواست همکاری');
        }

        if ($params['status'] === 'approved') {

            $userName = $request['name'];
            $userEmail = $request['email'];

            try {
                $templateId = (int) $_ENV['SENDPULSE_TEMPLATE_ID'];
                if (empty($templateId)) {
                    throw new Exception('خطا در دریافت اطلاعات');
                }

                $result = $this->send_email(
                    $userEmail,
                    $userName,
                    'قرارداد همکاری در آکادمی وویس کلاس',
                    null,
                    null,
                    ['قرارداد.docx' => '/../../Data/contract.docx'],
                    $templateId,
                    ["current_year" => 2025]
                );

                if ($result !== true) {
                    throw new Exception('ارسال ایمیل قرارداد موفقیت آمیز نبود');
                }

            } catch (Exception $e) {
                $db->rollback();
                Response::error($e->getMessage());
            }
        }

        $db->commit();
        Response::success('وضعیت درخواست همکاری به روز شد');
    }
}
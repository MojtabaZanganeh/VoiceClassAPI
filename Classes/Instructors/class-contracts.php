<?php
namespace Classes\Instructors;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Products\Categories;
use Classes\Users\Users;
use Exception;

class Contracts extends Instructors
{
    use Base, Sanitizer;

    public function upload_contract($params, $files)
    {
        $instructor_user = $this->check_role(['instructor']);
        $instructor = $this->get_instructor_by_user_id($instructor_user['id']);

        $upload_dir = 'Uploads/Contracts/';

        $file_ext = pathinfo($files['contract']['name'], PATHINFO_EXTENSION);

        $uuid = $this->generate_uuid();

        $file_path = $upload_dir . $uuid . '.' . $file_ext;

        $current_time = $this->current_time();

        $db = new Database();
        $db->beginTransaction();

        try {
            $contract_exist = $db->getData(
                "SELECT id FROM {$db->table['instructor_contracts']} WHERE instructor_id = ? AND `status` != 'rejected'",
                [$instructor['id']]
            );

            if ($contract_exist) {
                throw new Exception('قرارداد قبلاً ثبت شده است');
            }

            $insert_id = $db->insertData(
                "INSERT INTO {$db->table['instructor_contracts']} (`uuid`, `instructor_id`, `status`, `file`, `expires_at`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $uuid,
                    $instructor['id'],
                    'pending-review',
                    $file_path,
                    $this->current_time(modify: '+1 week'),
                    $current_time,
                    $current_time
                ]
            );

            if (!$insert_id) {
                throw new Exception('خطا در ثبت قرارداد');
            }

            $uploaded_file_path = $this->handle_file_upload($files['contract'], $upload_dir, $uuid);

            if (!$uploaded_file_path) {
                throw new Exception('خطا در بارگذاری فایل');
            }

            $db->commit();
            Response::success('قرارداد بارگذاری شد');
        } catch (Exception $e) {
            $db->rollback();
            Response::error($e ? $e->getMessage() : 'خطا در بارگذاری فایل');
        }
    }

    public function get_all_contracts($params)
    {
        $this->check_role(['admin']);

        $query = $params['query'] ?? null;
        $current_page = $params['current_page'] ?? 1;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 12)
            ? $params['per_page_count']
            : 12;

        $statsSql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending-review' THEN 1 ELSE 0 END) as pending_review,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
                    FROM {$this->table['instructor_contracts']}";
        $stats = $this->getData($statsSql, []);

        $where_condition = '';
        $bind_params = [];

        if (!empty($params['status']) && in_array($params['status'], ['pending-review', 'approved', 'rejected', 'expired'])) {
            $where_condition .= ' WHERE ic.status = ? ';
            $bind_params[] = $params['status'];
        }

        if ($query) {
            $where_condition .= ($where_condition === '' ? 'WHERE' : 'AND') . " CONCAT(up.first_name_fa, ' ', up.last_name_fa) LIKE ? OR UPPER(u.email) LIKE UPPER(?) OR u.phone LIKE ? ";
            $bind_params[] = "%{$query}%";
        }

        $offset = ($current_page - 1) * $per_page_count;
        $bind_params[] = $per_page_count;
        $bind_params[] = $offset;

        $sql = "SELECT
                    ic.uuid,
                    ic.file,
                    ic.status,
                    ic.expires_at,
                    ic.created_at,
                    ic.updated_at,
                    JSON_OBJECT (
                        'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa),
                        'avatar', u.avatar,
                        'email', u.email
                    ) AS instructor
                FROM {$this->table['instructor_contracts']} ic
                JOIN {$this->table['instructors']} i ON ic.instructor_id = i.id
                JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                $where_condition
                GROUP BY ic.id
                ORDER BY ic.created_at DESC
                LIMIT ? OFFSET ?
        ";

        $contracts = $this->getData($sql, $bind_params, true);

        if (!$contracts) {
            Response::success(
                'هیچ قراردادی یافت نشد',
                'contractsData',
                [
                    'contracts' => [],
                    'stats' => $stats,
                    'total_pages' => 1
                ]
            );
        }

        $category_obj = new Categories();
        foreach ($contracts as &$contract) {
            $contract['instructor'] = json_decode($contract['instructor'], true);
            $contract['instructor']['avatar'] = $this->get_full_image_url($contract['instructor']['avatar']);
        }

        $total_pages = ceil($stats['total'] / $per_page_count);

        Response::success(
            'قراردادها دریافت شد',
            'contractsData',
            [
                'contracts' => $contracts,
                'stats' => $stats,
                'total_pages' => $total_pages
            ]
        );
    }

    public function update_contract_status($params)
    {
        $this->check_role(['admin']);

        $this->check_params($params, ['contract_uuid', 'status']);

        $contract_uuid = $params['contract_uuid'];

        $new_status = $params['status'];
        if (!in_array($new_status, ['pending-review', 'approved', 'rejected', 'expired'])) {
            Response::error('وضعیت معتبر نیست');
        }

        switch ($new_status) {
            case 'pending-review':
                $expires_time = '+1 week';
                break;

            case 'approved':
                $expires_time = '+1 year';
                break;

            case 'rejected':
            case 'expired':
                $expires_time = '';
                break;

            default:
                $expires_time = '+1 week';
                break;
        }
        $expires_at = $this->current_time(modify: $expires_time);

        $update_status = $this->updateData(
            "UPDATE {$this->table['instructor_contracts']} SET `status` = ?, expires_at = ? WHERE uuid = ?",
            [
                $new_status,
                $expires_at,
                $contract_uuid,
            ]
        );

        if (!$update_status) {
            Response::error('خطا در بروزرسانی وضعیت قرارداد');
        }

        Response::success('وضعیت قرارداد بروز شد', 'expires_at', $expires_at);
    }
}
 
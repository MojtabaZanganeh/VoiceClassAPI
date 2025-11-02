<?php
require_once __DIR__ . '/cron-require.php';

use Classes\Base\Cron;

class DeactivateInstructorsWithoutContractCron extends Cron
{
    protected function run(): void
    {
        if ($this->currentTime !== $this->cronTimes['deactivate-instructors-without-contract']) {
            self::log($this->logFile, "Skipped: time mismatch (current={$this->currentTime}, expected={$this->cronTimes['deactivate-instructors-without-contract']})");
            return;
        }

        $this->logMessage = 'Deactivate instructors without contract successfully';

        $threshold = $this->current_time('Y-m-d H:i:s', false, 'en', '-7 days');

        $instructors = $this->db->getData(
            "SELECT i.id, i.user_id, u.email, CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS `name`
                    FROM {$this->db->table['instructors']} i
                    JOIN {$this->db->table['users']} u ON i.user_id = u.id
                    LEFT JOIN {$this->db->table['user_profiles']} up ON i.user_id = up.user_id
                        WHERE i.status = 'active'
                        AND registered_as_instructor <= ?
                        AND NOT EXISTS (
                            SELECT 1 FROM {$this->db->table['instructor_contracts']} ic
                            WHERE ic.instructor_id = i.id AND (ic.status = 'pending-review' OR ic.status = 'approved')
                        )
        ", [$threshold], true);

        if (!$instructors) {
            $this->logMessage = 'No instructors to deactivate';
            return;
        }

        foreach ($instructors as $instructor) {
            $ok = $this->db->updateData(
                "UPDATE {$this->db->table['instructors']} SET status = ? WHERE id = ?",
                ['inactive', $instructor['id']]
            );
            if (!$ok) {
                throw new Exception("Failed to deactivate instructor #{$instructor['id']}");
            }

            $product_ok = $this->db->updateData(
                "UPDATE {$this->db->table['products']} SET instructor_active = ? WHERE instructor_id = ?",
                [0, $instructor['id']]
            );
            if (!$product_ok) {
                throw new Exception("Failed to deactivate instructor products #{$instructor['id']}");
            }

            if ($instructor['email']) {
                $this->send_email(
                    $instructor['email'],
                    $instructor['name'] ?? 'مدرس',
                    'پایان مهلت ارسال قرارداد آکادمی وویس کلاس',
                    null,
                    null,
                    [],
                    $_ENV['SENDPULSE_CONTRACT_SUBMISSION_DEADLINE_TEMPLATE_ID'],
                    ["current_year" => $this->current_time('Y', true, 'en')]
                );
            }
        }
    }
}

$cron = new DeactivateInstructorsWithoutContractCron('deactivate-instructors-without-contract.log', 'غیرفعال‌سازی مدرس‌های بدون قرارداد');
$cron->execute();

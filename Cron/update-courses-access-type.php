<?php
require_once __DIR__ . '/cron-require.php';

use Classes\Base\Cron;

class UpdateCoursesAccessTypeCron extends Cron
{
    protected function run(): void
    {
        $timeParts = explode(':', $this->currentTime);
        $minute = (int) $timeParts[1];

        if ($minute % 15 !== 0) {
            self::log($this->logFile, "Skipped: not a 15-minute mark (current={$this->currentTime})");
            return;
        }

        $this->logMessage = 'Checked online courses and updated to recorded if needed';

        $courses = $this->db->getData("
            SELECT cd.id AS course_detail_id, cd.product_id, cd.access_type,
                   ocs.type, ocs.start_date, ocs.webinar_date, ocs.start_time
            FROM {$this->db->table['course_details']} cd
            INNER JOIN {$this->db->table['online_course_schedules']} ocs ON ocs.product_id = cd.product_id
            WHERE cd.access_type = 'online'
        ", [], true) ?? [];

        $now = new DateTime();

        foreach ($courses as $course) {
            if ($course['type'] === 'recurring') {
                $dateStr = $course['start_date'];
            } else {
                $dateStr = $course['webinar_date'];
            }

            if (!$dateStr || !$course['start_time']) {
                continue;
            }

            $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $course['start_time']);
            if (!$startDateTime) {
                continue;
            }

            $diff = $startDateTime->getTimestamp() - $now->getTimestamp();

            if ($diff <= 3600 && $diff > 0) {
                $ok = $this->db->updateData("
                    UPDATE {$this->db->table['course_details']}
                    SET access_type = 'recorded'
                    WHERE id = ?
                ", [$course['course_detail_id']]);

                if (!$ok) {
                    throw new Exception("Failed to update course_detail ID {$course['course_detail_id']} to recorded");
                }

                self::log($this->logFile, "Course {$course['product_id']} changed to recorded");
            }
        }
    }
}

$cron = new UpdateCoursesAccessTypeCron('update-courses-access-type.log', 'تبدیل دسترسی دوره‌ها');
$cron->execute();

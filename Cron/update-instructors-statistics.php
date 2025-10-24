<?php
require_once __DIR__ . '/cron-require.php';

use Classes\Base\Cron;

class UpdateInstructorStatsCron extends Cron
{
    protected function run(): void
    {
        if ($this->currentTime !== $this->cronTimes['update-instructors-statistics']) {
            self::log($this->logFile, "Skipped: time mismatch (current={$this->currentTime}, expected={$this->cronTimes['update-instructors-statistics']})");
            return;
        }

        $this->logMessage = 'Instructor stats updated successfully';

        $courses = $this->db->getData("
            SELECT instructor_id, COUNT(id) AS total_courses
            FROM {$this->db->table['products']}
            WHERE type = 'course' AND status = 'verified'
            GROUP BY instructor_id
        ", [], true) ?? [];

        $books = $this->db->getData("
            SELECT instructor_id, COUNT(id) AS total_books
            FROM {$this->db->table['products']}
            WHERE type = 'book' AND status = 'verified'
            GROUP BY instructor_id
        ", [], true) ?? [];

        $ratings = $this->db->getData("
            SELECT 
                p.instructor_id,
                ROUND(AVG(r.rating), 2) AS avg_rating,
                COUNT(r.id) AS rating_count
            FROM {$this->db->table['reviews']} r
            INNER JOIN {$this->db->table['products']} p ON p.id = r.product_id
            WHERE p.status = 'verified'
            GROUP BY p.instructor_id
        ", [], true) ?? [];

        $students = $this->db->getData("
            SELECT p.instructor_id, COUNT(oi.id) AS total_students
            FROM {$this->db->table['order_items']} oi
            INNER JOIN {$this->db->table['products']} p ON p.id = oi.product_id
            WHERE oi.status = 'completed'
            GROUP BY p.instructor_id
        ", [], true) ?? [];

        $coursesMap = [];
        foreach ($courses as $row) {
            $coursesMap[$row['instructor_id']] = (int)$row['total_courses'];
        }

        $booksMap = [];
        foreach ($books as $row) {
            $booksMap[$row['instructor_id']] = (int)$row['total_books'];
        }

        $ratingsMap = [];
        foreach ($ratings as $row) {
            $ratingsMap[$row['instructor_id']] = [
                'avg'   => (float)$row['avg_rating'],
                'count' => (int)$row['rating_count']
            ];
        }

        $studentsMap = [];
        foreach ($students as $row) {
            $studentsMap[$row['instructor_id']] = (int)$row['total_students'];
        }

        $instructors = $this->db->getData("
            SELECT id FROM {$this->db->table['instructors']}
        ", [], true);

        if (!$instructors) {
            throw new Exception('Failed to get instructors');
        }

        foreach ($instructors as $inst) {
            $id = $inst['id'];
            $totalCourses = $coursesMap[$id] ?? 0;
            $totalBooks   = $booksMap[$id] ?? 0;
            $avgRating    = $ratingsMap[$id]['avg'] ?? 0.0;
            $ratingCount  = $ratingsMap[$id]['count'] ?? 0;
            $totalStudents = $studentsMap[$id] ?? 0;

            $ok = $this->db->updateData("
                UPDATE {$this->db->table['instructors']}
                SET courses_taught = ?, books_written = ?, rating_avg = ?, rating_count = ?, students = ?
                WHERE id = ?
            ", [$totalCourses, $totalBooks, $avgRating, $ratingCount, $totalStudents, $id]);

            if (!$ok) {
                throw new Exception("Failed to update instructor statistics for ID {$id}");
            }
        }
    }
}

$cron = new UpdateInstructorStatsCron('update-instructors-statistics.log', 'بروزرسانی آمار مدرسین');
$cron->execute();
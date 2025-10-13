<?php

$init = require __DIR__ . '/cron-runner.php';
$db = $init['db'];
$time = $init['time'];
$cron_time = $init['cron_times']['update-instructors-statistics'];
$start_time = $init['start_time'];
$log_file = 'update-instructors-statistics.log';
$log_message = 'Instructor stats updated successfully';

if ($time !== $cron_time)
    exit;

try {
    $db->beginTransaction();

    $courses = $db->getData("
        SELECT instructor_id, COUNT(id) AS total_courses
        FROM {$db->table['products']}
        WHERE type = 'course' AND status = 'verified'
        GROUP BY instructor_id
    ", [], true);

    $books = $db->getData("
        SELECT instructor_id, COUNT(id) AS total_books
        FROM {$db->table['products']}
        WHERE type = 'book' AND status = 'verified'
        GROUP BY instructor_id
    ", [], true);

    $ratings = $db->getData("
        SELECT 
            p.instructor_id,
            ROUND(AVG(r.rating), 2) AS avg_rating,
            COUNT(r.id) AS rating_count
        FROM {$db->table['reviews']} r
        INNER JOIN {$db->table['products']} p ON p.id = r.product_id
        WHERE p.status = 'verified'
        GROUP BY p.instructor_id
    ", [], true);

    $courses_map = [];
    foreach ($courses ?? [] as $row)
        $courses_map[$row['instructor_id']] = (int) $row['total_courses'];

    $books_map = [];
    foreach ($books ?? [] as $row)
        $books_map[$row['instructor_id']] = (int) $row['total_books'];

    $ratings_map = [];
    foreach ($ratings ?? [] as $row)
        $ratings_map[$row['instructor_id']] = [
            'avg' => (float) $row['avg_rating'],
            'count' => (int) $row['rating_count']
        ];

    $instructors = $db->getData("
        SELECT id FROM {$db->table['instructors']}
    ", [], true);

    if (!$instructors) {
        throw new Exception('Failed to get instructors');
    }

    foreach ($instructors as $inst) {
        $id = $inst['id'];
        $total_courses = $courses_map[$id] ?? 0;
        $total_books = $books_map[$id] ?? 0;
        $avg_rating = $ratings_map[$id]['avg'] ?? 0;
        $rating_count = $ratings_map[$id]['count'] ?? 0;

        $update_instructor = $db->updateData("
            UPDATE {$db->table['instructors']}
            SET courses_taught = ?, books_written = ?, rating_avg = ?, rating_count = ?
            WHERE id = ?
        ", [$total_courses, $total_books, $avg_rating, $rating_count, $id]);

        if (!$update_instructor) {
            throw new Exception('Failed to update instructor statistics');
        }
    }
} catch (Exception $e) {
    $db->rollback();
    error_log("[" . jdate('Y-m-d H:i:s') . "] " . $e->getMessage());
    exit;
}

require __DIR__ . '/cron-finish.php';
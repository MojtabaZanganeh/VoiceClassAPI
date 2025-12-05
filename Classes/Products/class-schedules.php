<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Products\Products;
use Exception;

class Schedules extends Products
{
    use Base, Sanitizer;

    public function add_schedules($product_id, $schedules, Database $db)
    {
        if (empty($schedules) || !is_array($schedules)) {
            Response::error('هیچ زمان‌بندی‌ برای افزودن وجود ندارد');
        }

        try {
            foreach ($schedules as $schedule) {
                $scheduleType = $schedule['type'];
                if (!in_array($scheduleType, ['recurring', 'webinar'])) {
                    throw new Exception('نوع زمان‌بندی معتبر نیست');
                }

                $daysOfWeek = null;
                if ($scheduleType === 'recurring' && isset($schedule['days_of_week'])) {
                    $validDays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];
                    $validDaysOfWeek = array_intersect($schedule['days_of_week'], $validDays);

                    if (empty($validDaysOfWeek)) {
                        throw new Exception('حداقل یک روز هفته باید برای زمان‌بندی تکراری انتخاب شود');
                    }

                    $daysOfWeek = implode(',', $validDaysOfWeek);
                }

                $startDate = $endDate = null;
                if ($scheduleType === 'recurring') {
                    $startDate = $this->check_input($schedule['start_date'], 'YYYY/MM/DD', 'تاریخ شروع');
                    $endDate = $this->check_input($schedule['end_date'], 'YYYY/MM/DD', 'تاریخ پایان');

                    if (strtotime($endDate) < strtotime($startDate)) {
                        throw new Exception('تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد');
                    }
                }

                $webinarDate = null;
                if ($scheduleType === 'webinar' && !empty($schedule['webinar_date'])) {
                    $webinarDate = $this->check_input($schedule['webinar_date'], 'YYYY/MM/DD', 'تاریخ وبینار');
                }

                $startTime = $this->check_input($schedule['start_time'], 'HH:MM', 'زمان شروع');
                $endTime = $this->check_input($schedule['end_time'], 'HH:MM', 'زمان پایان');

                if (strtotime($endTime) <= strtotime($startTime)) {
                    throw new Exception('زمان پایان باید بعد از زمان شروع باشد');
                }

                $url = $this->check_input($schedule['url'], 'url', 'لینک کلاس');

                $result = $db->insertData(
                    "INSERT INTO {$db->table['online_course_schedules']} 
                            (`product_id`, `type`, `days_of_week`, `start_date`, `end_date`, `webinar_date`, `start_time`, `end_time`, `url`) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $product_id,
                        $scheduleType,
                        $daysOfWeek,
                        $startDate,
                        $endDate,
                        $webinarDate,
                        $startTime,
                        $endTime,
                        $url
                    ]
                );

                if (!$result) {
                    throw new Exception('خطا در افزودن زمان‌بندی');
                }
            }

            return true;
        } catch (Exception $e) {
            $db->rollback();
            Response::error($e->getMessage() ?? 'خطا در افزودن زمان‌بندی');
        }
    }

    public function update_schedules($product_id, $schedules, Database $db)
    {
        try {
            $db->deleteData(
                "DELETE FROM {$db->table['online_course_schedules']} WHERE product_id = ?",
                [$product_id]
            );

            if (empty($schedules) || !is_array($schedules)) {
                return true;
            }

            foreach ($schedules as $schedule) {
                $scheduleType = $schedule['type'];
                if (!in_array($scheduleType, ['recurring', 'webinar'])) {
                    throw new Exception('نوع زمان‌بندی معتبر نیست');
                }

                $daysOfWeek = null;
                if ($scheduleType === 'recurring' && isset($schedule['days_of_week'])) {
                    $validDays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];
                    $validDaysOfWeek = array_intersect($schedule['days_of_week'], $validDays);

                    if (empty($validDaysOfWeek)) {
                        throw new Exception('حداقل یک روز هفته باید برای زمان‌بندی تکراری انتخاب شود');
                    }

                    $daysOfWeek = implode(',', $validDaysOfWeek);
                }

                $startDate = $endDate = null;
                if ($scheduleType === 'recurring') {
                    $startDate = $this->check_input($schedule['start_date'], 'YYYY/MM/DD', 'تاریخ شروع');
                    $endDate = $this->check_input($schedule['end_date'], 'YYYY/MM/DD', 'تاریخ پایان');

                    $startDate = $this->convert_jalali_to_miladi($startDate);
                    $endDate = $this->convert_jalali_to_miladi($endDate);

                    if (strtotime($endDate) < strtotime($startDate)) {
                        throw new Exception('تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد');
                    }
                }

                $webinarDate = null;
                if ($scheduleType === 'webinar' && !empty($schedule['webinar_date'])) {
                    $webinarDate = $this->check_input($schedule['webinar_date'], 'YYYY/MM/DD', 'تاریخ وبینار');
                    $webinarDate = $this->convert_jalali_to_miladi($webinarDate);
                }

                $startTime = $this->check_input($schedule['start_time'], 'HH:MM', 'زمان شروع');
                $endTime = $this->check_input($schedule['end_time'], 'HH:MM', 'زمان پایان');

                if (strtotime($endTime) <= strtotime($startTime)) {
                    throw new Exception('زمان پایان باید بعد از زمان شروع باشد');
                }

                $url = $this->check_input($schedule['url'], 'url', 'لینک کلاس');

                $result = $db->insertData(
                    "INSERT INTO {$db->table['online_course_schedules']} 
                            (`product_id`, `type`, `days_of_week`, `start_date`, `end_date`, `webinar_date`, `start_time`, `end_time`, `url`) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $product_id,
                        $scheduleType,
                        $daysOfWeek,
                        $startDate,
                        $endDate,
                        $webinarDate,
                        $startTime,
                        $endTime,
                        $url
                    ]
                );

                if (!$result) {
                    throw new Exception('خطا در آپدیت زمان‌بندی');
                }
            }

            return true;
        } catch (Exception $e) {
            $db->rollback();
            Response::error($e->getMessage() ?? 'خطا در آپدیت زمان‌بندی');
        }
    }

    public function get_schedules($product_id, $is_dashboard)
    {
        $url_field = $is_dashboard ? ', url' : '';

        $schedules = $this->getData(
            "SELECT 
                        id, type, days_of_week, start_date, end_date, webinar_date, start_time, end_time $url_field
                    FROM {$this->table['online_course_schedules']}
                    WHERE product_id = ?",
            [$product_id],
            true
        );

        if (!$schedules) {
            Response::error('خطا در دریافت زمان‌بندی');
        }

        foreach ($schedules as &$schedule) {
            $schedule['start_date'] = !empty($schedule['start_date']) ? $this->convert_miladi_to_jalali($schedule['start_date']) : null;
            $schedule['end_date'] = !empty($schedule['end_date']) ? $this->convert_miladi_to_jalali($schedule['end_date']) : null;
            $schedule['webinar_date'] = !empty($schedule['webinar_date']) ? $this->convert_miladi_to_jalali($schedule['webinar_date']) : null;
            $schedule['days_of_week'] = explode(',', $schedule['days_of_week']);
        }

        return $schedules;
    }
}
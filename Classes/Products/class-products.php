<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
use Classes\Users\Users;

class Products extends Users
{
    use Base, Sanitizer;

    private function generate_slug($input, $sku)
    {
        $output = preg_replace('/[^a-zA-Z0-9\s\-_\x{0600}-\x{06FF}]/u', '', $input);
        $output .= "-vc$sku";
        $output = preg_replace('/\s+/', '-', $output);
        $output = strtolower($output);
        $output = trim($output, '-');
        return $output;
    }

    public function add_new_product($params)
    {
        $instructor = $this->check_role(['instructor', 'admin']);

        if ($instructor['role'] === 'instructor') {
            $instructor_id = $instructor['id'];
            $creator_id = $instructor_id;
        } else {
            $instructor_data = $this->getData(
                "SELECT id FROM {$this->table['instructors']} WHERE id = ?",
                [$params['instructor']['id']]
            );

            if (!$instructor_data) {
                Response::error('مدرس یافت نشد');
            }

            $instructor_id = $instructor_data['id'];
            $creator_id = $instructor['id'];
        }

        $this->check_params(
            $params,
            ["category", "level", "type", "access_type", "title", "introduction", "description", "what_you_learn", "price", "discount_amount", "chapters"]
        );

        $category = $this->getData("SELECT id FROM {$this->table['categories']} WHERE id = ?", [$params['category']]);
        if (!$category) {
            Response::error('دسته بندی یافت نشد');
        }
        $category_id = $category['id'];

        $level = $params['level'];
        if (!in_array($level, ['beginner', 'intermediate', 'advanced', 'expert'])) {
            Response::error('سطح دشواری معتبر نیست');

        }

        $type = $params['type'];
        if (!in_array($type, ['course', 'book'])) {
            Response::error('نوع محصول معتبر نیست');
        }

        $access_type = $params['access_type'];
        if (($type === 'course' && !in_array($access_type, ['online', 'recorded'])) || ($type === 'book' && !in_array($params['access_type'], ['printed', 'digital']))) {
            Response::error('نوع دسترسی به محصول معتبر نیست');
        }

        $title = $this->check_input($params['title'], null, 'عنوان محصول', '/^[\p{L}\p{N}\p{M}\p{Extended_Pictographic}\s]{3,75}$/u');

        $introduction = $this->check_input($params['introduction'], null, 'معرفی کوتاه', '/^[\p{L}\p{N}\p{M}\p{Extended_Pictographic}\s]{7,150}$/u');

        $description = $this->check_input($params['description'], null, 'توضیحات محصول', '/^.{150,}$/us');

        $what_you_learn = $this->check_input($params['what_you_learn'], 'array', 'نکات یادگیری');

        $requirements = !empty($params['requirements']) ? $this->check_input($params['requirements'], 'array', 'پیش نیازها') : null;

        $price = $this->check_input($params['price'], 'positive_int', 'قیمت اصلی');
        $discount_amount = $this->check_input($params['discount_amount'], 'int', 'مقدار تخفیف');
        if ($discount_amount > $price) {
            Response::error('مقدار تخفیف نمی تواند از قیمت اصلی بیشتر باشد');
        }

        $access_type_price = 0;
        $access_type_discount_amount = 0;
        if (isset($params['online_price']) && $params['online_price']) {
            $access_type_price = $this->check_input($params['online_price'], 'positive_int', 'قیمت دوره آنلاین');
            $access_type_discount_amount = $this->check_input($params['online_discount_amount'], 'int', 'مقدار تخفیف دوره آنلاین');
            if ($access_type_discount_amount > $access_type_price) {
                Response::error('مقدار تخفیف نمی تواند از قیمت اصلی دوره آنلاین بیشتر باشد');
            }
        } elseif (isset($params['printed_price']) && $params['printed_price']) {
            $access_type_price = $this->check_input($params['printed_price'], 'positive_int', 'قیمت جزوه چاپی');
            $access_type_discount_amount = $this->check_input($params['printed_discount_amount'], 'int', 'مقدار تخفیف جزوه چاپی');
            if ($access_type_discount_amount > $access_type_price) {
                Response::error('مقدار تخفیف نمی تواند از قیمت اصلی جزوه چاپی بیشتر باشد');
            }
        }

        if ($type === 'book') {
            $pages = $this->check_input($params['pages'], 'positive_int', 'تعداد صفحات');
            $size = $this->check_input($params['size'], 'positive_int', 'حجم فایل');
        }

        $uuid = $this->generate_uuid();

        $random_sku = $this->get_random('int', 4, $this->table['products'], 'slug');

        $slug = $this->generate_slug($title, $random_sku);

        $temp_path = 'Uploads/Temp/';
        $thumbnail_path = 'Uploads/Thumbnails/';
        $book_path = 'Uploads/Books/';

        $thumbnail = $this->check_input($params['thumbnail'], null, 'تصویر', '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/i');
        $thumbnail_url = $thumbnail_path . $thumbnail;
        $current_time = $this->current_time();

        $demo_book = $type === 'book' ? $this->check_input($params['demo_link'], null, 'فایل دمو جزوه', '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/i') : null;
        $full_book = $type === 'book' && $access_type === 'digital' ? $this->check_input($params['digital_link'], null, 'فایل جزوه', '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/i') : null;

        $db = new Database();
        $db->beginTransaction();

        $product_id = $db->insertData(
            "INSERT INTO {$db->table['products']}
                        (`uuid`, `status`, `slug`, `category_id`, `instructor_id`, `type`, `thumbnail`, `title`, `introduction`, `description`, `what_you_learn`, `requirements`, `level`, `price`, `discount_amount`, `creator_id`, `created_at`, `updated_at`)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                'need-approval',
                $slug,
                $category_id,
                $instructor_id,
                $type,
                $thumbnail_url,
                $title,
                $introduction,
                $description,
                json_encode($what_you_learn),
                json_encode($requirements),
                $level,
                $price,
                $discount_amount,
                $creator_id,
                $current_time,
                $current_time
            ]
        );

        if (!$product_id) {
            Response::error('خطا در ثبت محصول', null, 400, $db);
        }

        $chapter_obj = new Chapters();
        $chapter_data = $chapter_obj->add_chapters($params['chapters'], $product_id, $type, $db);

        if ($type === 'course') {
            $product_details = $db->insertData(
                "INSERT INTO {$db->table['course_details']} 
                        (`product_id`, `access_type`, `all_lessons_count`, `duration`, `online_price`, `online_discount_amount`) 
                            VALUES (?, ?, ?, ?, ?, ?)",
                [$product_id, $access_type, $chapter_data['lessons_count'], $chapter_data['total_length'], $access_type_price, $access_type_discount_amount]
            );
        } else {
            $format = strtoupper($params['format']);
            $full_book_format = strtoupper(explode('.', $full_book)[1]);

            $demo_book_url = $book_path . $demo_book;
            $full_book_url = $book_path . $full_book;

            if (!in_array($format, ['PDF', 'POWERPOINT', 'EPUB']) || ($full_book !== null && $format !== $full_book_format)) {
                Response::error('فرمت جزوه معتبر نیست', null, 400, $db);
            }

            $product_details = $db->insertData(
                "INSERT INTO {$db->table['book_details']}
                        (`product_id`, `access_type`, `pages`, `format`, `size`, `all_lessons_count`, `printed_price`, `printed_discount_amount`, `demo_link`, `digital_link`)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $product_id,
                    $access_type,
                    $pages,
                    $format,
                    $size,
                    $chapter_data['lessons_count'],
                    $access_type_price,
                    $access_type_discount_amount,
                    $demo_book_url,
                    $full_book_url
                ]
            );
        }

        if (!$product_details) {
            Response::error('خطا در افزودن جزئیات محصول', null, 400, $db);
        }

        $thumbnail_uuid = explode('.', $thumbnail)[0];
        $this->move_file_by_uuid($thumbnail_uuid, $temp_path, $thumbnail_path, $db, 'تصویری بارگذاری نشده است');

        if ($type === 'book') {
            $demo_book_uuid = explode('.', $demo_book)[0];
            $this->move_file_by_uuid($demo_book_uuid, $temp_path, $book_path, $db, 'دمو جزوه بارگذاری نشده است');

            if ($access_type === 'digital') {
                $full_book_uuid = explode('.', $full_book)[0];
                $this->move_file_by_uuid($full_book_uuid, $temp_path, $book_path, $db, 'جزوه بارگذاری نشده است');
            }
        }

        $db->commit();

        Response::success('محصول ثبت شد و پس از تأیید در سایت نمایش داده خواهد شد');
    }

    public function upload_product_file()
    {
        $instructor = $this->check_role(['instructor', 'admin']);

        $upload_dir = 'Uploads/Temp/';
        $uuid = $this->generate_uuid();
        $time = time();
        $thumbnail_url = (isset($_FILES['product_file']) && $_FILES['product_file']['size'] > 0) ? $this->handle_file_upload($_FILES['product_file'], $upload_dir, $uuid, $time) : null;

        if (!$thumbnail_url) {
            Response::error('خطا در ذخیره فایل');
        }

        Response::success('فایل ذخیره شد', 'fileName', $uuid . '.' . pathinfo($_FILES['product_file']['name'], PATHINFO_EXTENSION));
    }

    public function move_file_by_uuid(string $uuid, string $temp_path, string $targetDir, Database $db, string $error_message): string
    {
        $files = glob("{$temp_path}{$uuid}-*.*");
        if (empty($files)) {
            Response::error($error_message, null, 400, $db);
        }

        $source = $files[0];
        $ext = pathinfo($source, PATHINFO_EXTENSION);
        $target = $targetDir . $uuid . '.' . $ext;

        if (!rename($source, $target)) {
            Response::error("انتقال فایل با خطا مواجه شد: {$error_message}", null, 400, $db);
        }

        return $target;
    }
}
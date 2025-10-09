<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
use Classes\Users\Users;
use setasign\Fpdi\Fpdi;

class Products extends Users
{
    use Base, Sanitizer;

    public function add_new_product($params)
    {
        $instructor = $this->check_role(['instructor', 'admin']);

        if ($instructor['role'] === 'instructor') {
            $creator_id = $instructor['id'];
            $instructor_data = $this->getData(
                "SELECT id FROM {$this->table['instructors']} WHERE user_id = ?",
                [$instructor['id']]
            );
            if (!$instructor_data) {
                Response::error('خطا در یافتن مدرس');
            }
            $instructor_id = $instructor_data['id'];
        } else {
            $instructor_data = $this->getData(
                "SELECT id FROM {$this->table['instructors']} WHERE uuid = ?",
                [$params['instructor']['uuid']]
            );

            if (!$instructor_data) {
                Response::error('مدرس یافت نشد');
            }

            $instructor_id = $instructor_data['id'];
            $creator_id = $instructor['id'];
            $product_status = 'verified';
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

        $title = $this->check_input($params['title'], null, 'عنوان محصول', '/^.{3,75}$/us');

        $introduction = $this->check_input($params['introduction'], null, 'معرفی کوتاه', '/^.{7,150}$/us');

        $description = $this->check_input($params['description'], null, 'توضیحات محصول', '/^.{150,}$/us');

        $what_you_learn = $this->check_input($params['what_you_learn'], 'array', 'نکات یادگیری');

        $requirements = [];
        if (!empty($params['requirements'])) {
            $cleaned = array_filter($params['requirements'], function ($val) {
                return trim($val) !== '';
            });

            if (!empty($cleaned)) {
                $requirements = $this->check_input($cleaned, 'array', 'پیش نیازها');
            }
        }

        $price = $this->check_input($params['price'], 'int', 'قیمت اصلی');
        $discount_amount = $this->check_input($params['discount_amount'], 'int', 'مقدار تخفیف');
        if ($discount_amount > $price) {
            Response::error('مقدار تخفیف نمی تواند از قیمت اصلی بیشتر باشد');
        }

        $access_type_price = 0;
        $access_type_discount_amount = 0;
        if (isset($params['online_price']) && $params['online_price']) {
            $access_type_price = $this->check_input($params['online_price'], 'int', 'قیمت دوره آنلاین');
            $access_type_discount_amount = $this->check_input($params['online_discount_amount'], 'int', 'مقدار تخفیف دوره آنلاین');
            if ($access_type_discount_amount > $access_type_price) {
                Response::error('مقدار تخفیف نمی تواند از قیمت اصلی دوره آنلاین بیشتر باشد');
            }
        } elseif (isset($params['printed_price']) && $params['printed_price']) {
            $access_type_price = $this->check_input($params['printed_price'], 'int', 'قیمت جزوه چاپی');
            $access_type_discount_amount = $this->check_input($params['printed_discount_amount'], 'int', 'مقدار تخفیف جزوه چاپی');
            if ($access_type_discount_amount > $access_type_price) {
                Response::error('مقدار تخفیف نمی تواند از قیمت اصلی جزوه چاپی بیشتر باشد');
            }
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

        $full_book = $type === 'book' ? $this->check_input($params['digital_link'], null, 'فایل جزوه', '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/i') : null;

        $product_status ??= 'need-approval';

        $db = new Database();
        $db->beginTransaction();

        $product_id = $db->insertData(
            "INSERT INTO {$db->table['products']}
                        (`uuid`, `status`, `slug`, `category_id`, `instructor_id`, `type`, `thumbnail`, `title`, `introduction`, `description`, `what_you_learn`, `requirements`, `level`, `price`, `discount_amount`, `creator_id`, `created_at`, `updated_at`)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                $product_status,
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
            $full_book_url = $book_path . $full_book;

            if ($type === 'book') {
                $full_book_uuid = explode('.', $full_book)[0];
                $full_book_files = glob("{$temp_path}{$full_book_uuid}-*.*");
                if (!$full_book_files || count($full_book_files) === 0) {
                    Response::error('فایل کامل جزوه بارگذاری نشده است');
                }
                $full_book_temp = $full_book_files[0];

                $pdf = new Fpdi();
                $pages = $pdf->setSourceFile($full_book_temp);
                $size = round(filesize($full_book_temp) / 1024 / 1024, 2);
                $format = strtoupper(pathinfo($full_book, PATHINFO_EXTENSION));

                if ($pages < 5) {
                    Response::error('جزوه نباید کمتر از ۵ صفحه باشد');
                }

                $all_pages = range(1, $pages);
                $selected_pages = array_slice($all_pages, 0, $pages < 20 ? 3 : 5);

                foreach ($selected_pages as $page_num) {
                    $templateId = $pdf->importPage($page_num);
                    $demo_size = $pdf->getTemplateSize($templateId);
                    $pdf->AddPage($demo_size['orientation'], [$demo_size['width'], $demo_size['height']]);
                    $pdf->useTemplate($templateId);
                }

                $demo_uuid = $this->generate_uuid();
                $demo_book_url = $book_path . $demo_uuid . '.' . strtolower($format);
                $pdf->Output('F', $demo_book_url);
            }

            if (!in_array($format, ['PDF', 'POWERPOINT', 'EPUB'])) {
                Response::error('فرمت جزوه معتبر نیست', [], 400, $db);
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
            $full_book_uuid = explode('.', $full_book)[0];
            $this->move_file_by_uuid($full_book_uuid, $temp_path, $book_path, $db, 'جزوه بارگذاری نشده است');
        }

        $db->commit();

        Response::success('محصول ثبت شد و پس از تأیید در سایت نمایش داده خواهد شد');
    }

    public function upload_product_file()
    {
        $instructor = $this->check_role(['instructor', 'admin']);

        $fileId = $_POST['fileId'] ?? null;
        $chunkIndex = isset($_POST['chunkIndex']) ? intval($_POST['chunkIndex']) : null;
        $totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : null;
        $fileName = $_POST['fileName'] ?? null;

        $upload_dir = 'Uploads/Temp/';
        $uuid = $this->generate_uuid();
        $time = time();

        if ($fileId && $chunkIndex !== null && $totalChunks && $fileName && isset($_FILES['chunk'])) {
            $result = $this->handle_chunked_upload(
                $_FILES['chunk'],
                $upload_dir,
                $fileId,
                $chunkIndex,
                $totalChunks,
                $fileName,
                $uuid,
                $time
            );

            if ($result === null) {
                Response::error("خطا در ذخیره بخش یا فایل نهایی");
            }

            if ($result === "partial") {
                Response::success("بخش شماره {$chunkIndex} ذخیره شد", "status", "partial");
            }

            $full_file_name = $uuid . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
            Response::success("فایل نهایی ذخیره شد", "fileName", basename($full_file_name));
        }

        if (!isset($_FILES['product_file']) || $_FILES['product_file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('فایل یافت نشد یا خطا دارد');
        }

        $file_path = $this->handle_file_upload($_FILES['product_file'], $upload_dir, $uuid, $time);

        if (!$file_path) {
            Response::error('خطا در ذخیره فایل');
        }

        $file_name = $uuid . '.' . pathinfo($_FILES['product_file']['name'], PATHINFO_EXTENSION);
        Response::success("فایل ذخیره شد", "fileName", basename($file_name));
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

    public function get_similar_products($params)
    {
        $this->check_params($params, ['product_uuid', 'product_type']);

        $product_uuid = $params['product_uuid'];
        $product_type = $params['product_type'];

        $sql = "SELECT
                    p2.slug,
                    p2.title,
                    p2.thumbnail,
                    JSON_OBJECT(
                        'name', CONCAT(up2.first_name_fa, ' ', up2.last_name_fa)
                    ) AS instructor,
                    p2.price,
                    p2.discount_amount
                FROM {$this->table['products']} p1
                INNER JOIN {$this->table['products']} p2 
                    ON p1.category_id = p2.category_id
                INNER JOIN {$this->table['instructors']} i2 
                    ON p2.instructor_id = i2.id
                    INNER JOIN {$this->table['user_profiles']} up2 
                    ON i2.user_id = up2.user_id
                WHERE p1.uuid = ? 
                AND p2.uuid != ? 
                AND p2.type = ?
                GROUP BY p2.id
                ORDER BY 
                    (p1.instructor_id = p2.instructor_id) DESC,
                    p2.created_at DESC
                LIMIT 3;
        ";

        $similar_products = $this->getData($sql, [$product_uuid, $product_uuid, $product_type], true);

        if (!$similar_products) {
            Response::success('محصول مشابهی یافت نشد', 'similarProducts', []);
        }

        foreach ($similar_products as &$similar_product) {
            $similar_product['instructor'] = json_decode($similar_product['instructor']);
            $similar_product['thumbnail'] = $this->get_full_image_url($similar_product['thumbnail']);
        }

        Response::success('محصولات مشابه دریافت شد', 'similarProducts', $similar_products);
    }


}
<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Database;
use Classes\Instructors\Instructors;
use Classes\Users\Users;
use Exception;
use setasign\Fpdi\Fpdi;

class Products extends Users
{
    use Base, Sanitizer;

    protected function get_products($params, $type, $details_table, $select_fields, $access_types, $not_found_message, $success_message, $response_key)
    {
        $query = $params['q'] ?? null;
        $category = $params['category'] ?? null;
        $level = $params['level'] ?? null;
        $access_type = $params['access_type'] ?? null;
        $sort = $params['sort'] ?? 'newest';
        $current_page = isset($params['current_page']) ? max((int) $params['current_page'], 1) : 1;
        $per_page_count = (isset($params['per_page_count']) && $params['per_page_count'] <= 12)
            ? $params['per_page_count']
            : 12;

        $role = $params['role'] ?? null;
        $management_columns = '';
        $status_where_condition = '';
        $status_bindParams = '';
        $stats_query = '';
        if ($role && in_array($role, ['admin', 'instructor'])) {
            if ($role === 'admin') {
                $admin = $this->check_role(['admin']);
                $where_condition = '';
                $bindParams = [];
            } elseif ($role === 'instructor') {
                $instructor_user = $this->check_role(['instructor']);
                $instructor_obj = new Instructors();
                $instructor = $instructor_obj->get_instructor_by_user_id($instructor_user['id']);
                $where_condition = " AND p.instructor_id = ? ";
                $bindParams = [$instructor['id']];
            }

            $management_columns = ' p.status, p.instructor_active, p.instructor_share_percent, ';

            $status_filter = $params['status'] ?? null;
            if (!empty($status_filter) && in_array($status_filter, ['not-completed', 'need-approval', 'verified', 'rejected', 'deleted', 'admin-deleted'])) {
                $status_where_condition = " AND p.status = ?";
                $status_bindParams = $status_filter;
            }

            $stats_query = ", SUM(CASE WHEN status = 'not-completed' THEN 1 ELSE 0 END) as not_completed,
                            SUM(CASE WHEN status = 'need-approval' THEN 1 ELSE 0 END) as need_approval,
                            SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified,
                            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                            SUM(CASE WHEN status = 'deleted' THEN 1 ELSE 0 END) as deleted,
                            SUM(CASE WHEN status = 'admin-deleted' THEN 1 ELSE 0 END) as admin_deleted ";
        } else {
            $where_condition = " AND p.status = 'verified' AND p.instructor_active = 1 ";
            $bindParams = [];
        }

        if ($query) {
            $where_condition .= " AND p.title LIKE ?";
            $bindParams[] = "%{$query}%";
        }

        if ($category && is_numeric($category) && $category > 0) {
            $where_condition .= " AND p.category_id = ?";
            $bindParams[] = $category;
        }

        if ($level && in_array($level, ['beginner', 'intermediate', 'advanced', 'expert'])) {
            $where_condition .= " AND p.level = ?";
            $bindParams[] = $level;
        }

        if ($access_type && in_array($access_type, $access_types)) {
            $where_condition .= " AND dt.access_type = ?";
            $bindParams[] = $access_type;
        }

        $sort_condition = match ($sort) {
            'newest' => 'p.created_at DESC',
            'rating' => 'p.rating_avg DESC',
            'students' => 'p.students DESC',
            'price_asc' => '(p.price - p.discount_amount) ASC',
            'price_desc' => '(p.price - p.discount_amount) DESC',
            default => 'p.created_at DESC',
        };

        array_unshift($bindParams, $type);

        if ($role === 'instructor') {
            $instructor = $instructor_obj->get_instructor_by_user_id($instructor_user['id']);
            $where_condition .= ' AND instructor_id = ?';
            $bindParams[] = $instructor['id'];
        }

        $statsSql = "SELECT 
                            COUNT(*) as total
                            $stats_query
                        FROM {$this->table['products']} p
                            WHERE `type` = ? $where_condition";

        $stats = $this->getData($statsSql, $bindParams);

        if ($status_bindParams !== '') {
            array_push($bindParams, $status_bindParams);
        }

        $offset = ($current_page - 1) * $per_page_count;
        $bindParams[] = $per_page_count;
        $bindParams[] = $offset;

        $sql = "SELECT
                    p.uuid,
                    p.slug,
                    $management_columns
                    p.category_id,
                    pc.name AS category,
                    p.thumbnail,
                    p.title,
                    JSON_OBJECT(
                        'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa),
                        'avatar', u.avatar,
                        'professional_title', i.professional_title
                    ) AS instructor,
                    p.introduction,
                    p.level,
                    p.price,
                    p.discount_amount,
                    p.rating_avg,
                    p.rating_count,
                    p.students,
                    $select_fields
                FROM {$this->table['products']} p
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                LEFT JOIN {$this->table[$details_table]} dt ON p.id = dt.product_id
                    WHERE p.type = ? $where_condition $status_where_condition
                GROUP BY p.id
                ORDER BY $sort_condition
                LIMIT ? OFFSET ?
        ";

        $results = $this->getData($sql, $bindParams, true);

        $total_pages = ceil($stats['total'] / $per_page_count);

        if (!$results) {
            Response::success($not_found_message, 'productsData', [
                $type . 's' => [],
                'stats' => $stats,
                'total_pages' => 1
            ]);
        }

        foreach ($results as &$item) {
            $item['thumbnail'] = $this->get_full_image_url($item['thumbnail']);
            $item['instructor'] = json_decode($item['instructor'], true);
            $item['instructor']['avatar'] = $this->get_full_image_url($item['instructor']['avatar']);
        }

        Response::success($success_message, 'productsData', [
            $type . 's' => $results,
            'stats' => $stats,
            'total_pages' => $total_pages
        ]);
    }

    public function get_slug_by_short_link($params)
    {
        $this->check_params($params, ['short_link']);

        $short_link = $params['short_link'];

        $product = $this->getData(
            "SELECT `type`, slug FROM {$this->table['products']} WHERE `status` = 'verified' AND instructor_active = 1 AND short_link = ?",
            [$short_link]
        );

        if (!$product) {
            Response::error('محصول یافت نشد');
        }

        Response::success(
            'محصول یافت شد',
            'productData',
            [
                'type' => $product['type'],
                'slug' => $product['slug']
            ]
        );
    }
    public function get_product_by_uuid($params)
    {
        $this->check_role(['instructor', 'admin']);

        $this->check_params($params, ['uuid']);

        $product = $this->getData("SELECT `type`, slug FROM {$this->table['products']} WHERE uuid = ?", [$params['uuid']]);

        if (!$product) {
            Response::error('محصول یافت نشد');
        }

        $slug = $product['slug'];
        $params = ['slug' => $slug, 'dashboard' => true];

        if ($product['type'] === 'course') {
            $courses_obj = new Courses();
            $courses_obj->get_course_by_slug($params);
        } elseif ($product['type'] === 'book') {
            $books_obj = new Books();
            $books_obj->get_book_by_slug($params);
        } else {
            Response::error('نوع محصول نامعتبر است');
        }
    }

    protected function get_product_by_slug($params, $typeConfig)
    {
        $this->check_params($params, ['slug']);

        $slug = !empty($params['slug']) ? $params['slug'] : null;

        if (!$slug) {
            Response::error('شناسه محصول یافت نشد');
        }

        $digital_link = '';
        $additional_where = " AND p.status = 'verified' AND i.status = 'active' ";
        if (isset($params['dashboard']) && $params['dashboard'] === true) {
            $user = $this->check_role(['instructor', 'admin']);

            $is_instructor = $user && $user['role'] === 'instructor';
            if ($is_instructor) {
                $instructor_obj = new Instructors();
                $instructor = $instructor_obj->get_instructor_by_user_id($user['id']);
                $instructor_obj->check_instructor_permission($instructor['id'], $slug);
            }

            $digital_link = !empty($typeConfig['details_table']) && $typeConfig['details_table'] === 'book_details' ? ' dt.digital_link, ' : '';
            $additional_where = '';
        }

        $defaultConfig = [
            'details_table' => '',
            'select_fields' => '',
            'instructor_stats_field' => '',
            'special_processing' => null,
            'messages' => [
                'not_found' => '',
                'success' => '',
                'response_key' => ''
            ]
        ];

        $config = array_merge($defaultConfig, $typeConfig);

        $sql = "SELECT
                    p.uuid,
                    p.short_link,
                    p.status,
                    p.instructor_active,
                    p.type,
                    p.category_id,
                    pc.name AS category,
                    p.thumbnail,
                    p.title,
                    JSON_OBJECT(
                        'uuid', i.uuid,
                        'name', CONCAT(up.first_name_fa, ' ', up.last_name_fa),
                        'avatar', u.avatar,
                        'professional_title', i.professional_title,
                        'bio', i.bio,
                        'rating_avg', i.rating_avg,
                        'rating_count', i.rating_count,
                        'students', i.students,
                        '{$config['instructor_stats_field']}', i.{$config['instructor_stats_field']}
                    ) AS instructor,
                    p.introduction,
                    p.description,
                    p.what_you_learn,
                    p.requirements,
                    p.level,
                    p.price,
                    p.discount_amount,
                    p.rating_avg,
                    p.rating_count,
                    p.students,
                    $digital_link
                    {$config['select_fields']}
                FROM {$this->table['products']} p
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['users']} u ON i.user_id = u.id
                LEFT JOIN {$this->table['user_profiles']} up ON u.id = up.user_id
                LEFT JOIN {$this->table[$config['details_table']]} dt ON p.id = dt.product_id
                WHERE p.slug = ? $additional_where
                ORDER BY p.created_at DESC
                LIMIT 1;
        ";

        $product = $this->getData($sql, [$slug]);

        if (!$product) {
            Response::error($config['messages']['not_found']);
        }

        $product['thumbnail_raw'] = explode('/', $product['thumbnail'])[2];
        $product['thumbnail'] = $this->get_full_image_url($product['thumbnail']);
        $product['instructor'] = json_decode($product['instructor'], true);
        $product['instructor']['avatar'] = $this->get_full_image_url($product['instructor']['avatar']);
        $product['what_you_learn'] = json_decode($product['what_you_learn'], true);
        $product['requirements'] = isset($product['requirements'])
            ? json_decode($product['requirements'], true)
            : null;
        $product['digital_link'] = !empty($product['digital_link']) ? $this->get_full_image_url($product['digital_link']) : null;

        $chapter_obj = new Chapters();
        $product['chapters'] = $chapter_obj->get_product_chapters(['product_uuid' => $product['uuid'], 'return' => true]);

        $specialProcessing = $config['special_processing'] ?? null;

        if ($specialProcessing !== null && is_callable($specialProcessing)) {
            $specialProcessing($product);
        }

        Response::success(
            $config['messages']['success'],
            !empty($params['dashboard']) ? 'product' : $config['messages']['response_key'],
            $product
        );
    }

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

        $short_link = $this->get_random('mix', 6, $this->table['products'], 'short_link');

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
                        (`uuid`, `status`, `short_link`, `slug`, `category_id`, `instructor_id`, `type`, `thumbnail`, `title`, `introduction`, `description`, `what_you_learn`, `requirements`, `level`, `price`, `discount_amount`, `creator_id`, `created_at`, `updated_at`)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $uuid,
                $product_status,
                $short_link,
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

        if ($product_status === 'need-approval') {
            $instructor_name = $this->getData(
                "SELECT 
                            CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS instructor_name
                        FROM {$this->table['instructors']} i
                        LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                        WHERE i.id = ?",
                [$instructor_id]
            )['instructor_name'];
            $submission_date = jdate("Y/m/d H:i", '', '', 'Asia/Tehran', 'en');
            $this->send_review_product_email(
                $type,
                $type === 'course' ? 'دوره' : 'جزوه',
                $title,
                $price,
                $discount_amount,
                $instructor_name,
                $submission_date,
                $description
            );
        }

        Response::success('محتوا ثبت شد و پس از تأیید در سایت نمایش داده خواهد شد');
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

    public function update_product($params)
    {
        $this->check_params($params, ['uuid']);

        $existing_product = $this->getData(
            "SELECT p.*, i.user_id AS instructor_user_id 
         FROM {$this->table['products']} p
         LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
         WHERE p.uuid = ?",
            [$params['uuid']]
        );

        if (!$existing_product) {
            Response::error('محصول یافت نشد');
        }

        $product_id = $existing_product['id'];
        $product_uuid = $existing_product['uuid'];
        $product_type = $existing_product['type'];

        $user = $this->check_role(['instructor', 'admin']);
        if ($user['role'] === 'instructor' && $existing_product['instructor_user_id'] != $user['id']) {
            Response::error('شما مجاز به ویرایش این محصول نیستید');
        }

        $db = new Database();
        $db->beginTransaction();
        $current_time = $this->current_time();

        try {
            $category = $this->getData("SELECT id FROM {$this->table['categories']} WHERE id = ?", [$params['category']]);
            if (!$category) {
                throw new Exception('دسته بندی یافت نشد');
            }

            $level = $params['level'];
            if (!in_array($level, ['beginner', 'intermediate', 'advanced', 'expert'])) {
                throw new Exception('سطح دشواری معتبر نیست');
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
                throw new Exception('مقدار تخفیف نمی‌تواند از قیمت اصلی بیشتر باشد');
            }

            $access_type = $params['access_type'];
            $valid_access_types = ($product_type === 'course')
                ? ['online', 'recorded']
                : ['printed', 'digital'];

            if (!in_array($access_type, $valid_access_types)) {
                throw new Exception('نوع دسترسی به محصول معتبر نیست');
            }

            if (empty($params['chapters']) || !is_array($params['chapters'])) {
                throw new Exception('فصل‌ها ارسال نشده‌اند');
            }

            $temp_path = 'Uploads/Temp/';
            $thumbnail_path = 'Uploads/Thumbnails/';
            $thumbnail_url = $existing_product['thumbnail'];

            if (!empty($params['thumbnail'])) {
                $thumbnail = $this->check_input(
                    $params['thumbnail'],
                    null,
                    'تصویر',
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/i'
                );

                $new_thumbnail_url = $thumbnail_path . $thumbnail;

                if ($existing_product['thumbnail'] !== $new_thumbnail_url) {
                    if (!empty($existing_product['thumbnail']) && file_exists($existing_product['thumbnail'])) {
                        unlink($existing_product['thumbnail']);
                    }

                    $thumbnail_uuid = explode('.', $thumbnail)[0];
                    $this->move_file_by_uuid($thumbnail_uuid, $temp_path, $thumbnail_path, $db, 'تصویری بارگذاری نشده است');
                    $thumbnail_url = $new_thumbnail_url;
                }
            }

            $book_details = null;
            if ($product_type === 'book') {
                $book_path = 'Uploads/Books/';

                $current_book = $this->getData(
                    "SELECT digital_link, demo_link, pages, format, size
                            FROM {$this->table['book_details']} 
                            WHERE product_id = ?",
                    [$product_id]
                );

                $file_changed = false;

                if (!empty($params['digital_link'])) {
                    $full_book_filename = basename($params['digital_link']);
                    $full_book = $this->check_input(
                        $full_book_filename,
                        null,
                        'فایل جزوه',
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/i'
                    );

                    $full_book_url = $book_path . $full_book;
                    $full_book_uuid = explode('.', string: $full_book)[0];
                    $full_book_files = glob("{$temp_path}{$full_book_uuid}-*.*");

                    $new_book_file = true;
                    if (empty($full_book_files)) {
                        $full_book_files = glob("{$book_path}{$full_book_uuid}.*");
                        $new_book_file = false;
                    }

                    if (!$full_book_files || count($full_book_files) === 0) {
                        throw new Exception('فایل کامل جزوه بارگذاری نشده است');
                    }

                    $full_book_temp = $full_book_files[0];

                    $file_changed = true;
                    if ($current_book && $current_book['digital_link'] === $full_book_url) {
                        $file_changed = false;
                    }

                    if ($file_changed) {
                        $demo_pdf = new Fpdi();
                        $pages = $demo_pdf->setSourceFile($full_book_temp);

                        if ($pages < 5) {
                            throw new Exception('جزوه نباید کمتر از ۵ صفحه باشد');
                        }

                        $size = round(filesize($full_book_temp) / 1024 / 1024, 2);
                        $format = strtoupper(pathinfo($full_book, PATHINFO_EXTENSION));

                        if ($current_book) {
                            if (!empty($current_book['digital_link']) && file_exists($current_book['digital_link'])) {
                                unlink($current_book['digital_link']);
                            }

                            if (!empty($current_book['demo_link']) && file_exists($current_book['demo_link'])) {
                                unlink($current_book['demo_link']);
                            }
                        }

                        $all_pages = range(1, $pages);
                        $selected_pages = array_slice($all_pages, 0, $pages < 20 ? 3 : 5);

                        foreach ($selected_pages as $page_num) {
                            $templateId = $demo_pdf->importPage($page_num);
                            $demo_size = $demo_pdf->getTemplateSize($templateId);
                            $demo_pdf->AddPage($demo_size['orientation'], [$demo_size['width'], $demo_size['height']]);
                            $demo_pdf->useTemplate($templateId);
                        }

                        $demo_uuid = $this->generate_uuid();
                        $demo_book_url = $book_path . $demo_uuid . '.' . strtolower($format);
                        $demo_pdf->Output('F', $demo_book_url);

                        if ($new_book_file) {
                            $this->move_file_by_uuid($full_book_uuid, $temp_path, $book_path, $db, 'جزوه بارگذاری نشده است');
                        }

                        $book_details = [
                            'pages' => $pages,
                            'format' => $format,
                            'size' => $size,
                            'demo_link' => $demo_book_url,
                            'digital_link' => $full_book_url
                        ];
                    }
                    else {
                        $book_details = [
                            'pages' => $current_book['pages'],
                            'format' => $current_book['format'],
                            'size' => $current_book['size'],
                            'demo_link' => $current_book['demo_link'],
                            'digital_link' => $current_book['digital_link']
                        ];
                    }
                }
                else if ($current_book) {
                    $book_details = [
                        'pages' => $current_book['pages'],
                        'format' => $current_book['format'],
                        'size' => $current_book['size'],
                        'demo_link' => $current_book['demo_link'],
                        'digital_link' => $current_book['digital_link']
                    ];
                }
                else {
                    throw new Exception('فایل جزوه ارسال نشده است');
                }
            }

            $result = $db->updateData(
                "UPDATE {$db->table['products']} SET
                            `category_id` = ?, `title` = ?, `introduction` = ?, `description` = ?, 
                            `what_you_learn` = ?, `requirements` = ?, `level` = ?, `price` = ?, 
                            `discount_amount` = ?, `thumbnail` = ?, `updated_at` = ?
                        WHERE id = ?",
                [
                    $category['id'],
                    $title,
                    $introduction,
                    $description,
                    json_encode($what_you_learn),
                    json_encode($requirements),
                    $params['level'],
                    $price,
                    $discount_amount,
                    $thumbnail_url,
                    $current_time,
                    $product_id
                ]
            );

            if (!$result) {
                throw new Exception('خطا در بروزرسانی اطلاعات محصول');
            }

            $access_type_price = 0;
            $access_type_discount_amount = 0;

            $price_field = ($product_type === 'course') ? 'online_price' : 'printed_price';
            $discount_field = ($product_type === 'course') ? 'online_discount_amount' : 'printed_discount_amount';

            if (isset($params[$price_field])) {
                $access_type_price = $this->check_input($params[$price_field], 'int', "قیمت {$product_type}");
                $access_type_discount_amount = $this->check_input($params[$discount_field], 'int', "مقدار تخفیف {$product_type}");

                if ($access_type_discount_amount > $access_type_price) {
                    throw new Exception('مقدار تخفیف نمی‌تواند از قیمت اصلی بیشتر باشد');
                }
            }

            $lessons_count = 0;
            $total_length = 0;

            foreach ($params['chapters'] as $chapter) {
                $lessons_count += count($chapter['lessons_detail']);
                $total_length += array_sum(array_column($chapter['lessons_detail'], 'length'));
            }

            if ($product_type === 'course') {
                $result = $db->updateData(
                    "UPDATE {$db->table['course_details']} SET
                                `access_type` = ?, `all_lessons_count` = ?, `duration` = ?, 
                                `online_price` = ?, `online_discount_amount` = ?
                            WHERE product_id = ?",
                    [
                        $access_type,
                        $lessons_count,
                        $total_length,
                        $access_type_price,
                        $access_type_discount_amount,
                        $product_id
                    ]
                );
            } else {
                $result = $db->updateData(
                    "UPDATE {$db->table['book_details']} SET
                                `access_type` = ?, `pages` = ?, `format` = ?, `size` = ?, 
                                `all_lessons_count` = ?, `printed_price` = ?, 
                                `printed_discount_amount` = ?, `demo_link` = ?, `digital_link` = ?
                            WHERE product_id = ?",
                    [
                        $access_type,
                        $book_details['pages'] ?? null,
                        $book_details['format'] ?? null,
                        $book_details['size'] ?? null,
                        $lessons_count,
                        $access_type_price,
                        $access_type_discount_amount,
                        $book_details['demo_link'] ?? null,
                        $book_details['digital_link'] ?? null,
                        $product_id
                    ]
                );
            }

            if (!$result) {
                throw new Exception('خطا در بروزرسانی جزئیات محصول');
            }

            $chapter_obj = new Chapters();
            $chapter_obj->update_chapters($params, $product_id, $product_type, $db);

            $status = ($user['role'] === 'admin') ? 'verified' : 'need-approval';
            $this->update_product_properties(['product_uuid' => $product_uuid, 'status' => $status, 'return' => true], $db);

            $db->commit();
            Response::success('محصول با موفقیت ویرایش شد', 'product', [
                'uuid' => $params['uuid'],
                'type' => $product_type
            ]);

        } catch (Exception $e) {
            $db->rollback();
            Response::error($e->getMessage());
        }
    }

    public function update_product_properties($params, ?Database $db = null)
    {
        try {
            if ($db === null) {
                $db = new Database();
            }

            if (empty($params['product_uuid']) || empty($params['status'])) {
                throw new Exception('اطلاعات لازم ارسال نشده است');
            }
            $product_uuid = $params['product_uuid'];

            $existing_product = $db->getData(
                "SELECT p.id, i.user_id AS instructor_user_id 
                        FROM {$db->table['products']} p
                        LEFT JOIN {$db->table['instructors']} i ON p.instructor_id = i.id
                            WHERE p.uuid = ?",
                [$product_uuid]
            );

            if (!$existing_product) {
                throw new Exception('محصول یافت نشد');
            }

            $status = $params['status'];
            if (!in_array($status, ['not-completed', 'need-approval', 'verified', 'rejected', 'deleted', 'admin-deleted'])) {
                throw new Exception('وضعیت معتبر نیست');
            }

            $user = $this->check_role(['instructor', 'admin']);
            if ($user['role'] === 'instructor' && ($existing_product['instructor_user_id'] != $user['id'] || $status != 'need-approval')) {
                throw new Exception('شما مجاز به ویرایش این محصول نیستید');
            }

            $bind_params = [$status, $product_uuid];
            $instructor_share_query = '';
            if (!empty($params['instructor_share_percent'])) {
                $instructor_share_percent = $this->check_input($params['instructor_share_percent'], null, 'سهم مدرس', '/^(100|[1-9]?[0-9])$/');
                $instructor_share_query = 'instructor_share_percent = ?, ';
                array_unshift($bind_params, $instructor_share_percent);
            }

            $update_product_status = $db->updateData(
                "UPDATE {$db->table['products']} SET $instructor_share_query `status` = ? WHERE uuid = ?",
                $bind_params
            );

            if (!$update_product_status) {
                throw new Exception('خطا در بروزرسانی وضعیت محصول');
            }

            if ($status === 'need-approval') {
                $product_data = $db->getData(
                    "SELECT
                                p.type,
                                p.title,
                                p.price,
                                p.discount_amount,
                                p.description,
                                CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS instructor_name
                            FROM {$db->table['products']} p
                            INNER JOIN {$db->table['instructors']} i ON p.instructor_id = i.id
                            LEFT JOIN {$db->table['user_profiles']} up ON i.user_id = up.user_id
                            WHERE p.uuid = ?",
                    [$product_uuid]
                );
                $submission_date = jdate("Y/m/d H:i", '', '', 'Asia/Tehran', 'en');
                $this->send_review_product_email(
                    $product_data['type'],
                    $product_data['type'] === 'course' ? 'دوره' : 'جزوه',
                    $product_data['title'],
                    $product_data['price'],
                    $product_data['discount_amount'],
                    $product_data['instructor_name'],
                    $submission_date,
                    $product_data['description']
                );
            }

            if (!empty($params['return'])) {
                return true;
            } else {
                Response::success('وضعیت محصول بروزرسانی شد');
            }
        } catch (Exception $e) {
            if (!empty($params['return'])) {
                $db->rollback();
            }
            Response::error($e->getMessage());
        }
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

    private function send_review_product_email(
        $content_type,
        $content_type_fa,
        $content_title,
        $content_price,
        $content_discount_amount,
        $instructor_name,
        $submission_date,
        $content_description
    ) {
        $this->send_email(
            $_ENV['ADMIN_MAIL'],
            'مدیریت محترم',
            'بررسی محتوا در آکادمی وویس کلاس',
            null,
            null,
            [],
            $_ENV['SENDPULSE_NEW_OR_EDIT_PRODUCT_TEMPLATE_ID'],
            [
                "content_type" => $content_type,
                "content_type_fa" => $content_type_fa,
                "content_title" => $content_title,
                "content_price" => number_format($content_price),
                "content_discount_amount" => number_format($content_discount_amount),
                "instructor_name" => $instructor_name,
                "submission_date" => $submission_date,
                "content_description" => $content_description,
            ]
        );
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
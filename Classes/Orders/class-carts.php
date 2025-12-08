<?php
namespace Classes\Orders;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Classes\Base\Error;
use Classes\Users\Users;
use DateTime;

class Carts extends Users
{
    use Base, Sanitizer;

    public function add_cart_item($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['uuid', 'access_type', 'quantity']);

        $item_uuid = $params['uuid'];
        $item_access_type = $params['access_type'];
        $item_quantity = $params['quantity'];
        $return = !empty($params['return']) ? true : false;

        $product = $this->getData("SELECT `id`, `status`, `instructor_active` FROM {$this->table['products']} WHERE uuid = ?", [$item_uuid]);
        if (!$product) {
            Response::error('محصول یافت نشد');
        }

        $order_obj = new Orders();
        $purchased = $order_obj->check_user_purchased(['product_uuid' => $item_uuid, 'return' => true]);
        if ($purchased) {
            Response::error('شما قبلاً این محصول را خریداری کردید');
        }

        if ($product['status'] !== 'verified' || $product['instructor_active'] != true) {
            Response::error('محصول معتبر نیست');
        }

        if (!in_array($item_access_type, ['online', 'recorded', 'printed', 'digital'])) {
            Response::error('نوع محصول به درستی ارسال نشده است');
        }

        $exist_item = $this->getData(
            "SELECT id FROM {$this->table['cart_items']} WHERE user_id = ? AND product_id = ? AND access_type = ?",
            [$user['id'], $product['id'], $item_access_type]
        );
        if ($exist_item) {
            if ($return) {
                return false;
            }
            Response::error('محصول قبلا به سبد خرید اضافه شده است');
        }

        $item_id = $this->insertData(
            "INSERT INTO {$this->table['cart_items']} (`user_id`, `product_id`, `access_type`, `quantity`, `added_at`) VALUES (?, ?, ?, ?, ?)",
            [
                $user['id'],
                $product['id'],
                $item_access_type,
                $item_quantity,
                $this->current_time()
            ]
        );

        if ($item_id) {
            if ($return) {
                return true;
            }
            $cart_items = $this->get_cart_items(['return' => true]);
            Response::success('محصول به سبد خرید افزوده شد', 'userCart', $cart_items);
        }

        if ($return) {
            return false;
        }
        Response::error('خطا در افزودن محصول به سبد خرید');
    }

    public function remove_cart_item($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['uuid', 'access_type']);

        $item_uuid = $params['uuid'];
        $item_access_type = $params['access_type'];

        $product = $this->getData("SELECT id FROM {$this->table['products']} WHERE uuid = ?", [$item_uuid]);
        if (!$product) {
            Response::error('محصول یافت نشد');
        }

        if (!in_array($item_access_type, ['online', 'recorded', 'printed', 'digital'])) {
            Response::error('نوع محصول به درستی ارسال نشده است');
        }

        $sql = "DELETE FROM {$this->table['cart_items']} WHERE user_id = ? AND product_id = ? AND access_type = ?";
        $delete_item = $this->deleteData($sql, [$user['id'], $product['id'], $item_access_type]);

        if ($delete_item) {
            $cart_items = $this->get_cart_items(['return' => true]);
            Response::success('محصول از سبد خرید حذف شد', 'userCart', $cart_items);
        }

        Response::error('خطا در حذف محصول از سبد خرید');
    }

    public function clear_cart_items($params = [])
    {
        $user = $this->check_role();

        $sql = "DELETE FROM {$this->table['cart_items']} WHERE user_id = ?";
        $delete_item = $this->deleteData($sql, [$user['id']]);

        if ($delete_item) {
            if (isset($params['return']) && $params['return'] === true) {
                return true;
            }
            Response::success('سبد خرید خالی شد', 'userCart', []);
        }

        if (isset($params['return']) && $params['return'] === true) {
            return false;
        }
        Response::error('خطا در خالی کردن سبد خرید');
    }

    public function sync_cart_items($params)
    {
        $user = $this->check_role();

        $this->check_params($params, ['items']);

        $items = $params['items'];
        if (!is_array($items)) {
            Response::error('خطا در دریافت محصولات سبد خرید');
        }

        foreach ($items as $item) {
            $this->add_cart_item(
                [
                    'uuid' => $item['uuid'],
                    'access_type' => $item['access_type'],
                    'quantity' => $item['quantity'],
                    'return' => true,
                ]
            );
        }

        $cart_items = $this->get_cart_items(['return' => true]);
        Response::success('سبد خرید همگام سازی شد', 'userCart', $cart_items);
    }

    public function get_cart_items($params = [])
    {
        $user = $this->check_role();

        $return = !empty($params['return']) ? true : false;
        $get_product_id = $return ? 'p.id,' : '';

        $sql = "SELECT 
                    $get_product_id
                    p.uuid,
                    p.slug,
                    p.title,
                    p.thumbnail,
                    pc.name AS category,
                    p.type,
                    ci.access_type,
                    ci.quantity,
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa) AS instructor,
                    CASE 
                        WHEN p.type = 'course' AND ci.access_type = 'online' 
                            THEN cd.online_price
                        WHEN p.type = 'book' AND ci.access_type = 'printed'
                            THEN bd.printed_price
                        ELSE p.price
                    END AS price,
                    CASE 
                        WHEN p.type = 'course' AND ci.access_type = 'online' 
                            THEN cd.online_discount_amount
                        WHEN p.type = 'book' AND ci.access_type = 'printed'
                            THEN bd.printed_discount_amount
                        ELSE p.discount_amount
                    END AS discount_amount
                FROM {$this->table['cart_items']} ci
                LEFT JOIN {$this->table['products']} p ON ci.product_id = p.id
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                LEFT JOIN {$this->table['course_details']} cd ON p.id = cd.product_id
                LEFT JOIN {$this->table['book_details']} bd ON p.id = bd.product_id
                WHERE ci.user_id = ?
                GROUP BY ci.id
        ";

        $cart_items = $this->getData($sql, [$user['id']], true);

        if (!$cart_items) {
            if ($return) {
                return [];
            }
            Response::success('سبد خرید خالی است', 'userCart', []);
        }

        foreach ($cart_items as &$item) {
            $item['thumbnail'] = $this->get_full_image_url($item['thumbnail']);
        }

        if ($return) {
            return $cart_items;
        }
        Response::success('سبد خرید دریافت شد', 'userCart', $cart_items);
    }

}
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

        $product = $this->getData("SELECT id FROM {$this->table['products']} WHERE uuid = ?", [$item_uuid]);
        if (!$product) {
            Response::error('محصول یافت نشد');
        }

        if (!in_array($item_access_type, ['online', 'recorded', 'printed', 'digital'])) {
            Response::error('نوع محصول به درستی ارسال نشده است');
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
            Response::success('محصول به سبد خرید افزوده شد');
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
            Response::success('محصول از سبد خرید حذف شد');
        }

        Response::error('خطا در حذف محصول از سبد خرید');
    }

    public function clear_cart_items($params)
    {
        $user = $this->check_role();

        $sql = "DELETE FROM {$this->table['cart_items']} WHERE user_id = ?";
        $delete_item = $this->deleteData($sql, [$user['id']]);

        if ($delete_item) {
            Response::success('سبد خرید خالی شد');
        }

        Response::error('خطا در خالی کردن سبد خرید');
    }

    public function get_cart_items($params = [])
    {
        $user = $this->check_role();

        $get_product_id = isset($params['return']) ? 'p.id,' : '';

        $sql = "SELECT 
                    $get_product_id
                    p.uuid,
                    p.title,
                    p.thumbnail,
                    pc.name AS category,
                    p.type,
                    p.price,
                    p.discount_amount,
                    ci.access_type,
                    ci.quantity,
                    CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                FROM {$this->table['cart_items']} ci
                LEFT JOIN {$this->table['products']} p ON ci.product_id = p.id
                LEFT JOIN {$this->table['categories']} pc ON p.category_id = pc.id
                LEFT JOIN {$this->table['instructors']} i ON p.instructor_id = i.id
                LEFT JOIN {$this->table['user_profiles']} up ON i.user_id = up.user_id
                    WHERE ci.user_id = ?
                GROUP BY ci.id, p.id, i.id, up.id
        ";

        $cart_items = $this->getData($sql, [$user['id']], true);

        if (!$cart_items) {
            if (isset($params['return']) && $params['return'] === true) {
                return false;
            }
            Response::success('سبد خرید خالی است');
        }

        foreach ($cart_items as &$item) {
            $item['thumbnail'] = $this->get_full_image_url($item['thumbnail']);
        }

        if (isset($params['return']) && $params['return'] === true) {
            return $cart_items;
        }
        Response::success('سبد خرید دریافت شد', 'userCart', $cart_items);
    }

}
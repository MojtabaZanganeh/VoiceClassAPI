<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;

class Categories extends Products
{
    use Base, Sanitizer;

    public function get_categories()
    {
        $categories = $this->getData("SELECT * FROM {$this->table['categories']}", [], true);

        if (!$categories) {
            Response::error('خطا در دریافت دسته بندی ها');
        }

        Response::success('دسته بندی رویدادها دریافت شد', 'allCategories', $categories);
    }

    public function get_categories_by_id($categories_id)
    {
        if ($categories_id && is_array($categories_id)) {

            $categories_id_string = implode(',', $categories_id);

            $categories_sql = "SELECT
                    CONCAT(
                        '[',
                        IFNULL(
                            GROUP_CONCAT(
                                DISTINCT JSON_QUOTE(name)
                                ORDER BY name
                                SEPARATOR ','
                            ),
                            ''
                        ),
                        ']'
                    ) AS categories
                FROM {$this->table['categories']} WHERE FIND_IN_SET(id, ?)
            ";

            $categories = $this->getData($categories_sql, [$categories_id_string]);

            if ($categories) {
                return json_decode($categories['categories']);
            }
        }

        return [];
    }
}
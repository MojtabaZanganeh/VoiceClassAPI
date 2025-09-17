<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Database;
use Classes\Users\Users;

class Chapters extends Products
{
    use Base, Sanitizer;

     public function get_product_chapters($params)
    {
        $this->check_params($params, ['product_uuid']);

        $product = $this->getData("SELECT id FROM {$this->table['products']} WHERE uuid = ?", [$params['product_uuid']]);

        $chapters_sql = "SELECT id, title, lessons_count, chapter_length 
                     FROM {$this->table['chapters']} 
                     WHERE product_id = ?";
        $chapters = $this->getData($chapters_sql, [$product['id']], true);

        if (!$chapters) {
            Response::error('خطا در دریافت سرفصل ها');
        }

        foreach ($chapters as &$chapter) {
            $lessons_sql = "SELECT id, title, `length`, free 
                        FROM {$this->table['chapter_lessons']} 
                        WHERE chapter_id = ?";
            $chapter['lessons_detail'] = $this->getData($lessons_sql, [$chapter['id']], true) ?: [];
        }

        Response::success('سرفصل ها دریافت شد', 'productChapters', $chapters);
    }
}
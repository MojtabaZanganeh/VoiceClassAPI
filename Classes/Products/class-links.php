<?php
namespace Classes\Products;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Products\Products;
use Exception;

class Links extends Products
{
    use Base, Sanitizer;

    public function add_product_links($product_id, $links, Database $db)
    {
        if (empty($links) || !is_array($links)) {
            throw new Exception('هیچ لینکی برای افزودن وجود ندارد');
        }

        try {
            foreach ($links as $key => $link) {
                $linkType = $link['type'];
                if (!in_array($linkType, ['online_class', 'related_book', 'file', 'support_group'])) {
                    throw new Exception(message: 'نوع لینک معتبر نیست');
                }

                $platform = $link['platform'];
                if (!in_array($platform, ['website', 'eitaa', 'bale', 'soroush', 'rubika', 'gap', 'igap', 'telegram', 'whatsapp', 'other'])) {
                    throw new Exception('پلتفرم لینک معتبر نیست');
                }

                $title = $this->check_input($link['title'], null, 'عنوان لینک', '/^.{7,150}$/us');
                $url = $this->check_input($link['url'], 'url', 'لینک');

                $result = $db->insertData(
                    "INSERT INTO {$db->table['product_links']} 
                            (`product_id`, `type`, `platform`, `title`, `url`, `created_at`) 
                                VALUES (?, ?, ?, ?, ?, ?)",
                    [$product_id, $linkType, $platform, $title, $url, $this->current_time()]
                );

                if (!$result) {
                    throw new Exception('خطا در افزودن لینک');
                }
            }

            return true;
        } catch (Exception $e) {
            $db->rollback();
            Response::error($e->getMessage() ?? 'خطا در افزودن لینک');
        }
    }
  
    public function update_product_links($product_id, $links, Database $db)
    {
        try {
            $db->deleteData(
                "DELETE FROM {$db->table['product_links']} WHERE product_id = ?",
                [$product_id]
            );

            if (empty($links) || !is_array($links)) {
                return true;
            }

            foreach ($links as $key => $link) {
                $linkType = $link['type'];
                if (!in_array($linkType, ['online_class', 'related_book', 'file', 'support_group'])) {
                    throw new Exception(message: 'نوع لینک معتبر نیست');
                }

                $platform = $link['platform'];
                if (!in_array($platform, ['website', 'eitaa', 'bale', 'soroush', 'rubika', 'gap', 'igap', 'telegram', 'whatsapp', 'other'])) {
                    throw new Exception('پلتفرم لینک معتبر نیست');
                }

                $title = $this->check_input($link['title'], null, 'عنوان لینک', '/^.{7,150}$/us');
                $url = $this->check_input($link['url'], 'url', 'لینک');

                $result = $db->insertData(
                    "INSERT INTO {$db->table['product_links']} 
                            (`product_id`, `type`, `platform`, `title`, `url`, `created_at`) 
                                VALUES (?, ?, ?, ?, ?, ?)",
                    [$product_id, $linkType, $platform, $title, $url, $this->current_time()]
                );

                if (!$result) {
                    throw new Exception('خطا در آپدیت لینک');
                }
            }

            return true;
        } catch (Exception $e) {
            $db->rollback();
            Response::error($e->getMessage() ?? 'خطا در آپدیت لینک');
        }
    }

    public function get_product_links($product_id)
    {
        $links = $this->getData(
            "SELECT  id, type, platform, title, url
                FROM {$this->table['product_links']}
                WHERE product_id = ?",
            [$product_id],
            true
        );

        return $links ?: [];
    }
}
<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Orders\Orders;
use Classes\Orders\Transactions;
use Classes\Users\Users;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Transliterator;

class Stats extends Support
{
    use Base, Sanitizer;

    public function get_stats()
    {
        $this->check_role(['admin']);

        $stats_sql = "SELECT
                            (SELECT COUNT(*) FROM users) AS total_users,
                            (SELECT COUNT(*) FROM instructors) AS total_instructors,
                            (SELECT COUNT(*) FROM products WHERE type = 'course') AS total_courses,
                            (SELECT COUNT(*) FROM products WHERE type = 'book') AS total_books,
                            (SELECT COUNT(DISTINCT order_id)
                        FROM order_items
                        WHERE status NOT IN ('canceled', 'rejected')) AS total_orders,
                            (SELECT SUM(amount)
                        FROM transactions
                        WHERE status = 'paid') AS total_income;
        ";
        $stats = $this->getData($stats_sql, []);

        $orders_sql = "SELECT
                            oi.id,
                            oi.uuid,
                            o.code,
                            p.title,
                            CASE
                                WHEN up.first_name_fa IS NULL OR up.first_name_fa = '' 
                                    THEN u.phone
                                ELSE CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                            END AS user_full_name,
                            oi.price,
                            oi.status,
                            o.created_at
                        FROM {$this->table['order_items']} oi
                        JOIN {$this->table['orders']} o ON oi.order_id = o.id
                        JOIN {$this->table['products']} p ON oi.product_id = p.id
                        JOIN {$this->table['users']} u ON o.user_id = u.id
                        JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id
                        ORDER BY o.created_at DESC
                        LIMIT 3;
        ";
        $last_orders = $this->getData($orders_sql, [], true);

        $transactions_sql = "SELECT
                                t.id,
                                t.uuid,
                                t.amount,
                                t.status,
                                t.type,
                                t.created_at,
                                t.updated_at,
                                JSON_OBJECT(
                                    'id', o.id,
                                    'code', o.code,
                                    'user_full_name',
                                        CASE
                                            WHEN up.first_name_fa IS NULL OR up.first_name_fa = '' 
                                                THEN u.phone
                                            ELSE CONCAT(up.first_name_fa, ' ', up.last_name_fa)
                                        END,
                                    'total_amount', o.total_amount,
                                    'discount_amount', o.discount_amount
                                ) AS `order`
                            FROM {$this->table['transactions']} t
                            JOIN {$this->table['orders']} o ON t.order_id = o.id
                            JOIN {$this->table['users']} u ON o.user_id = u.id
                            JOIN {$this->table['user_profiles']} up ON o.user_id = up.user_id
                            ORDER BY t.created_at DESC
                            LIMIT 3;
        ";
        $last_transactions = $this->getData($transactions_sql, [], true);

        if ($last_transactions) {
            foreach ($last_transactions as &$transaction) {
                $transaction['order'] = json_decode($transaction['order'], true);
                $orders_obj = new Orders();
                $transaction['order']['products'] = $orders_obj->get_order_items($transaction['order']['id']);
            }
        }

        $courses_sql = "SELECT 
                            p.id,
                            p.uuid,
                            p.slug,
                            p.title,
                            p.thumbnail,
                            p.price,
                            p.students,
                            p.rating_avg,
                            p.rating_count
                        FROM products p
                        WHERE p.type = 'course' AND students > 0
                        GROUP BY p.id
                        ORDER BY p.students DESC
                        LIMIT 3;
        "; 
        $top_selling_courses = $this->getData($courses_sql, [], true);

        $books_sql = "SELECT 
                            p.id,
                            p.uuid,
                            p.slug,
                            p.title,
                            p.thumbnail,
                            p.price,
                            p.students,
                            p.rating_avg,
                            p.rating_count,
                            COUNT(oi.id) AS sales_count
                        FROM products p
                        JOIN order_items oi ON oi.product_id = p.id
                        WHERE p.type = 'book'
                        AND oi.status NOT IN ('canceled', 'rejected')
                        GROUP BY p.id
                        ORDER BY sales_count DESC
                        LIMIT 3;
        "; 
        $top_selling_books = $this->getData($books_sql, [], true);

        Response::success('آمار دریافت شد', 'statsData', [
            'stats' => $stats,
            'last_orders' => $last_orders,
            'last_transactions' => $last_transactions,
            'top_selling_courses' => $top_selling_courses,
            'top_selling_books' => $top_selling_books
        ]);
    }
}
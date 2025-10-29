<?php

use Classes\Base\Api_Router;
$router = new Api_Router();

/**
 * Define routers for the application.
 * 
 * Each router maps an HTTP method and URL pattern to a controller method.
 * The routers defined here are responsible for handling specific API endpoints
 * in the application, and they are linked with methods in the specified classes.
 */
$router->add('/auth/send-code', 'POST', 'Classes\Users\Authentication', 'send_code');
$router->add('/auth/verify-code', 'POST', 'Classes\Users\Authentication', 'verify_code');
$router->add('/auth/register', 'POST', 'Classes\Users\Login', 'user_register');
$router->add('/auth/check-register', 'POST', 'Classes\Users\Login', 'check_user_registered');
$router->add('/auth/login', 'POST', 'Classes\Users\Login', 'user_login');
$router->add('/auth/verify-token', 'POST', 'Classes\Users\Login', 'user_validate');
$router->add('/auth/reset-password', 'POST', 'Classes\Users\Login', 'reset_password');

$router->add('/users/get-shipping-info', 'GET', 'Classes\Users\Profile', 'get_shipping_info');
$router->add('/users/update-profile', 'POST', 'Classes\Users\Profile', 'update_user_profile');
$router->add('/users/update-avatar', 'POST', 'Classes\Users\Profile', 'update_user_avatar');
$router->add('/users/search-users', 'GET', 'Classes\Users\Users', 'search_users');

$router->add('/instructors/add-new-instructor', 'POST', 'Classes\Instructors\Instructors', 'add_new_instructor');
$router->add('/instructors/update-instructor-info', 'POST', 'Classes\Instructors\Instructors', 'update_instructor_info');
$router->add('/instructors/get-all', 'GET', 'Classes\Instructors\Instructors', 'get_instructors');
$router->add('/instructors/get-stats', 'GET', 'Classes\Instructors\Instructors', 'get_instructor_stats');
$router->add('/instructors/admin-login', 'POST', 'Classes\Instructors\Instructors', 'admin_login');
$router->add('/instructors/contracts/upload-contract', 'POST', 'Classes\Instructors\Contracts', 'upload_contract');
$router->add('/instructors/contracts/get-all', 'GET', 'Classes\Instructors\Contracts', 'get_all_contracts');
$router->add('/instructors/contracts/update-status', 'POST', 'Classes\Instructors\Contracts', 'update_contract_status');

$router->add('/products/add-new', 'POST', 'Classes\Products\Products', 'add_new_product');
$router->add('/products/update-product', 'POST', 'Classes\Products\Products', 'update_product');
$router->add('/products/update-product-properties', 'POST', 'Classes\Products\Products', 'update_product_properties');
$router->add('/products/upload-product-file', 'POST', 'Classes\Products\Products', 'upload_product_file');
$router->add('/products/get-similar-products', 'GET', 'Classes\Products\Products', 'get_similar_products');
$router->add('/products/get-by-uuid', 'GET', 'Classes\Products\Products', 'get_product_by_uuid');
$router->add('/products/get-slug-by-short-link', 'GET', 'Classes\Products\Products', 'get_slug_by_short_link');

$router->add('/chapters/get-product-chapters', 'GET', 'Classes\Products\Chapters', 'get_product_chapters');

$router->add('/reviews/get-product-reviews', 'GET', 'Classes\Products\Reviews', 'get_product_reviews');

$router->add('/categories/get-all', 'GET', 'Classes\Products\Categories', 'get_categories');

$router->add('/courses/get-all', 'GET', 'Classes\Products\Courses', 'get_all_courses');
$router->add('/courses/get-by-slug', 'GET', 'Classes\Products\Courses', 'get_course_by_slug');
$router->add('/courses/get-user-courses', 'GET', 'Classes\Products\Courses', 'get_user_courses');

$router->add('/books/get-all', 'GET', 'Classes\Products\Books', 'get_all_books');
$router->add('/books/get-by-slug', 'GET', 'Classes\Products\Books', 'get_book_by_slug');
$router->add('/books/get-user-books', 'GET', 'Classes\Products\Books', 'get_user_books');

$router->add('/carts/add-item', 'POST', 'Classes\Orders\Carts', 'add_cart_item');
$router->add('/carts/remove-item', 'POST', 'Classes\Orders\Carts', 'remove_cart_item');
$router->add('/carts/clear-cart', 'GET', 'Classes\Orders\Carts', 'clear_cart_items');
$router->add('/carts/sync-cart', 'POST', 'Classes\Orders\Carts', 'sync_cart_items');
$router->add('/carts/get-items', 'GET', 'Classes\Orders\Carts', 'get_cart_items');

$router->add('/orders/add-order', 'POST', 'Classes\Orders\Orders', 'add_order');
$router->add('/orders/get-unpaid-order', 'POST', 'Classes\Orders\Orders', 'get_unpaid_order');
$router->add('/orders/get-all-orders', 'GET', 'Classes\Orders\Orders', 'get_all_order_items');
$router->add('/orders/update-item-status', 'POST', 'Classes\Orders\Orders', 'update_item_status');

$router->add('/transactions/card-pay', 'POST', 'Classes\Orders\Transactions', 'card_pay_order');
$router->add('/transactions/get-user-transactions', 'GET', 'Classes\Orders\Transactions', 'get_user_transactions');
$router->add('/transactions/get-all-transactions', 'GET', 'Classes\Orders\Transactions', 'get_all_transactions');
$router->add('/transactions/update-status', 'POST', 'Classes\Orders\Transactions', 'update_status');

$router->add('/notifications/get-user-notifications', 'GET', 'Classes\Notifications\Notifications', 'get_user_notifications');
$router->add('/notifications/read-notification', 'POST', 'Classes\Notifications\Notifications', 'read_notification');

$router->add('/support/join-us-request', 'POST', 'Classes\Support\Support', 'join_us_request');
$router->add('/support/get-all-join-us-requests', 'GET', 'Classes\Support\Support', 'get_all_join_us_requests');
$router->add('/support/update-join-us-request-status', 'POST', 'Classes\Support\Support', 'update_join_us_request_status');
$router->add('/support/get-user-tickets', 'GET', 'Classes\Support\Tickets', 'get_user_tickets');
$router->add('/support/emails/get-all', 'GET', 'Classes\Support\Emails', 'get_sent_emails');
$router->add('/support/emails/send-email', 'POST', 'Classes\Support\Emails', 'send_email_manually');
$router->add('/support/stats/get-stats', 'GET', 'Classes\Support\Stats', 'get_stats');
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

$router->add('/categories/get-all', 'GET', 'Classes\Products\Categories', 'get_categories');

$router->add('/courses/get-all', 'GET', 'Classes\Products\Courses', 'get_all_courses');
$router->add('/courses/get-by-slug', 'POST', 'Classes\Products\Courses', 'get_course_by_slug');
$router->add('/courses/get-user-courses', 'GET', 'Classes\Products\Courses', 'get_user_courses');

$router->add('/books/get-all', 'GET', 'Classes\Products\Books', 'get_all_books');
$router->add('/books/get-by-slug', 'POST', 'Classes\Products\Books', 'get_book_by_slug');
$router->add('/books/get-user-books', 'GET', 'Classes\Products\Books', 'get_user_books');

$router->add('/carts/add-item', 'POST', 'Classes\Orders\Carts', 'add_cart_item');
$router->add('/carts/remove-item', 'POST', 'Classes\Orders\Carts', 'remove_cart_item');
$router->add('/carts/clear-cart', 'GET', 'Classes\Orders\Carts', 'clear_cart_items');
$router->add('/carts/get-items', 'GET', 'Classes\Orders\Carts', 'get_cart_items');
$router->add('/orders/add-order', 'POST', 'Classes\Orders\Orders', 'add_order');

$router->add('/transactions/get-user-transactions', 'GET', 'Classes\Orders\Transactions', 'get_user_transactions');

$router->add('/notifications/get-user-notifications', 'GET', 'Classes\Notifications\Notifications', 'get_user_notifications');
$router->add('/notifications/read-notification', 'POST', 'Classes\Notifications\Notifications', 'read_notification');

$router->add('/support/tickets/get-user-tickets', 'GET', 'Classes\Support\Tickets', 'get_user_tickets');
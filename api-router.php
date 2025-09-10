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

$router->add('/users/update-profile', 'POST', 'Classes\Users\Profile', 'update_user_profile');
$router->add('/users/update-avatar', 'POST', 'Classes\Users\Profile', 'update_user_avatar');

$router->add('/transactions/get-user-transactions', 'GET', 'Classes\Reservations\Transactions', 'get_user_transactions');

$router->add('/notifications/get-user-notifications', 'GET', 'Classes\Notifications\Notifications', 'get_user_notifications');

$router->add('/support/tickets/get-user-tickets', 'GET', 'Classes\Support\Tickets', 'get_user_tickets');
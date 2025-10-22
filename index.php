<?php

/// For Test Step
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Api-Key");
    http_response_code(200);
    exit();
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Api-Key");
///

/**
 * Checks if the request is sent over HTTPS.
 * If the request is made over HTTP, the user is redirected to the HTTPS version.
 * 
 */
// if ((!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') && (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https')) {
//     header("Location: https://voiceclass.ir");
// die("You Not Access To This API");
// }


/**
 * Loads the necessary libraries and classes for setting up the API.
 * This section loads the required libraries and class files for routing and other operations.
 *
 */
require_once "vendor/autoload.php";
require_once "jdf.php";
require_once "load-classes.php";
require_once "api-router.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Classes\Base\Error;
new Error();

define('ROOT_PATH', __DIR__);

/**
 * Retrieves the "Api-Key" header from the request.
 * If the header is missing or incorrect, the request is redirected to a specific address.
 *
 * @var string|null $header The value of the "Api-Key" header
 * @return void
 */

$site_url = $_ENV['SITE_URL'];

$header = getallheaders()["Api-Key"] ?? null;
if (is_null($header)) {
    header("Location: $site_url");
    die("You Not Access To This API");
}
if (!is_null($header) && $header != $_ENV['API_KEY']) {
    header("Location: $site_url");
    die("You Not Access To This API");
}

/**
 * Processes and sanitizes the request URL.
 * This section grabs the request URL, sanitizes it to prevent potential attacks,
 * and then uses the "match" method for routing.
 *
 * @var string $requestUrl The sanitized request URL
 * @var string $requestMethod The HTTP method of the request
 * @return void
 */
$requestUrl = parse_url(htmlspecialchars($_SERVER['PATH_INFO']), PHP_URL_PATH);
$requestMethod = htmlspecialchars($_SERVER['REQUEST_METHOD']);

$router->match($requestUrl, $requestMethod);
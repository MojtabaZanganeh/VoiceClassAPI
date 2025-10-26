<?php
namespace Classes\Base;

use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Classes\Users\Authentication;

/**
 * Route Class for managing URL routing and authentication.
 *
 * This class provides functionality for defining and handling routes,
 * matching URLs, handling HTTP methods, and applying authentication.
 * 
 * @package Classes\Base
 */
class Api_Router
{
    use Sanitizer;

    /**
     * List of all registered routes.
     *
     * @var array
     */
    private $routes = [];

    /**
     * Flag to determine if authentication is enabled for the route.
     *
     * @var bool
     */
    private $csrf_check = false;

    /**
     * Enable authentication for the route.
     *
     * When authentication is enabled, the request must contain a valid token.
     *
     * @return $this
     */
    public function csrf()
    {
        $this->csrf_check = true;
        return $this;
    }

    /**
     * Add a route to the route collection.
     *
     * Registers a URL pattern, HTTP method, class, and function for the route.
     * Optionally, authentication can be applied to the route by calling the `auth()` method before adding the route.
     *
     * @param string $url The URL pattern for the route.
     * @param string $method The HTTP method (e.g., GET, POST, PUT).
     * @param string $class The class that handles the route.
     * @param string $function The method in the class that handles the request.
     */
    public function add($url, $method, $class, $function)
    {
        $urlPattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_-]*)\}/', function ($matches) {
            return "(?<$matches[1]>[^/]+)";
        }, $url);

        $this->routes[] = [
            'url' => "#^$urlPattern$#",
            'method' => $method,
            'class' => $class,
            'function' => $function,
            'csrf' => $this->csrf_check
        ];
    }

    /**
     * Match a request URL and method to a registered route.
     *
     * This method checks the incoming request URL and method against the registered routes.
     * If a match is found, the corresponding class method is called. 
     * Optionally, authentication is handled if enabled for the route.
     *
     * @param string $requestUrl The URL of the incoming request.
     * @param string $requestMethod The HTTP method of the incoming request.
     * @return bool Returns true if the route is matched and the function is called, otherwise returns false.
     */
    public function match($requestUrl, $requestMethod)
    {

        $requestUrl = $this->sanitizeInput($requestUrl);
        $requestMethod = $this->sanitizeInput($requestMethod);

        foreach ($this->routes as $route) {

            if ($route['method'] === $requestMethod) {

                if (preg_match($route['url'], $requestUrl, $matches)) {
                    $class = $route['class'];
                    $function = $route['function'];
                    unset($matches[0]);

                    if ($route['csrf']) {
                        $body_token = $requestMethod !== 'GET' ? $_POST['CSRF_TOKEN'] ?: json_decode(file_get_contents('php://input'), true)['CSRF_TOKEN'] : null;
                        $auth_obj = new Authentication();
                        $auth_obj->csrf_token_validation($body_token);
                    }

                    $params = match ($requestMethod) {
                        'GET' => $_GET,
                        'POST' => $_POST ?: json_decode(file_get_contents('php://input'), true),
                        'PUT', 'DELETE' => json_decode(file_get_contents('php://input'), true),
                        default => null,
                    } ?: null;

                    $files = !empty($_FILES) ? $_FILES : null;

                    $this->callClassFunction($class, $function, $params, $files);
                    return true;
                }

            }

        }
        Response::error('درخواست یافت نشد!', ['requested_route' => $requestUrl], 404);
        return false;

    }

    /**
     * Call the method of the class corresponding to the matched route.
     *
     * This method invokes the specified method of the given class, passing the provided parameters.
     *
     * @param string $class The class that contains the method to be called.
     * @param string $function The method of the class to be called.
     * @param mixed $params The parameters to be passed to the method.
     */
    private function callClassFunction($class, $function, $params, $files)
    {
        $class = $this->sanitizeInput($class);
        $function = $this->sanitizeInput($function);
        $params = (!is_null($params)) ? $this->sanitizeInput($params) : null;

        $classInstance = new $class();
        if (method_exists($classInstance, $function)) {
            call_user_func_array([$classInstance, $function], [$params, $files]);
        } else {
            Response::error('خطای سرور', 'method_exists', 500);
        }
    }

}
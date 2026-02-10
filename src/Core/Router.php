<?php

namespace Fbs\trpay\Core;

use Fbs\trpay\Auth\Auth;
use Fbs\trpay\Database;
use NumberFormatter;

/**
 * Router Class
 *
 * Handles routing and dispatching of requests to appropriate controllers
 * in the Nestogy application.
 */
class Router
{
    private $routes = [];

    private $apiRoutes = [];

    private $defaultPage = 'home';

    private $domain = '';

    private $currency_format;

    private $pdo;

    /**
     * Router constructor.
     *
     * Initializes the router with database connection and registers routes.
     */
    public function __construct($domain)
    {
        $config = require 'config.php';
        $this->domain = $domain;
        $database = new Database($config['db']);
        $this->pdo = $database->getConnection();
        $this->currency_format = numfmt_create($config['locale'], NumberFormatter::CURRENCY);
        $GLOBALS['currency_format'] = $this->currency_format;
        $this->registerRoutes();
    }

    /**
     * Register all application routes.
     *
     * @return void
     */
    public function registerRoutes()
    {
        require 'routes.php'; // This file defines the routes using $this->add() and $this->addApi()
    }

    /**
     * Add a new route to the application.
     *
     * @param  string  $route  The route identifier
     * @param  string  $controller  The controller class name
     * @param  string  $action  The controller action method
     * @param  array  $middlewares  Optional middleware parameters
     * @return void
     */
    public function add($route, $controller, $action = 'index', $middlewares = [])
    {
        $this->routes[$route] = [
            'controller' => $controller,
            'action' => $action,
            'middlewares' => $middlewares,
        ];
    }

    /**
     * Add a new API endpoint.
     *
     * @param  string  $endpoint  The API endpoint identifier
     * @param  string  $controller  The controller class name
     * @param  string  $method  The controller method to call
     * @param  string  $requestMethod  The HTTP request method (default: 'POST')
     * @return void
     */
    public function addApi($endpoint, $controller, $method, $requestMethod = 'POST')
    {
        $this->apiRoutes[$endpoint] = [
            'controller' => $controller,
            'method' => $method,
            'requestMethod' => $requestMethod,
        ];
    }

    /**
     * Dispatch the current request to the appropriate controller.
     *
     * @param  string  $domain  The domain to use for the request
     * @return void
     */
    public function dispatch()
    {
        // Check if the request is for an API endpoint
        if (isset($_GET['endpoint'])) {
            $this->handleApiRequest();

            return;
        }

        // Get the page from the URL
        $page = $_GET['page'] ?? $this->defaultPage;
        $route = $this->routes[$page] ?? null;

        // If the page is not found, handle the error
        if (! $route) {
            $this->handleNotFound($page);

            return;
        }

        // Get the controller and action from the route
        $controller = 'Fbs\\Pcsre\\Controller\\'.$route['controller'];
        $action = $route['action'];
        $params = $this->getParams($route['middlewares']);

        // If the user is not logged in and the page is not the login page, redirect to the login page
        if (! Auth::check() && $page !== 'login') {
            header('Location: login.php');
            exit;
        }

        // If the controller and action exist, call them
        if (class_exists($controller) && method_exists($controller, $action)) {
            $controllerInstance = new $controller($this->pdo);
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $postData = $this->sanitizePostData($_POST);
                call_user_func_array([$controllerInstance, $action], array_merge($params, [$postData]));
            } else {
                call_user_func_array([$controllerInstance, $action], $params);
            }
        } else {
            $this->handleNotFound($controller.'::'.$action);

            return;
        }
    }

    /**
     * Sanitize POST data to prevent XSS attacks.
     *
     * @param  array  $data  The POST data to sanitize
     * @return array Sanitized data
     */
    private function sanitizePostData($data)
    {
        $sanitizedData = [];
        foreach ($data as $key => $value) {
            $sanitizedData[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        return $sanitizedData;
    }

    /**
     * Get parameters from URL based on middleware requirements.
     *
     * @param  array  $middlewares  List of required parameters
     * @return array List of parameter values
     */
    private function getParams($middlewares)
    {
        $params = [];
        foreach ($middlewares as $param) {
            if (isset($_GET[$param])) {
                $params[] = htmlspecialchars($_GET[$param], ENT_QUOTES, 'UTF-8');
            } else {
                $params[] = null; // or handle missing parameters as needed
            }
        }

        return $params;
    }

    /**
     * Handle 404 Not Found errors.
     *
     * @return void
     */
    private function handleNotFound($page)
    {

        $view = new \Fbs\trpay\View\View;
        $messages = [
            "Well, this is awkward. The page you're looking for ran away with the circus. Try searching for something else or double-check that URL!",
            "Oh no! The page you're looking for is on vacation. Try searching for something else or double-check that URL!",
            "Oh dear! The page you're looking for must be taking a nap. Try searching for something else or double-check that URL!",
            "Oh snap! The page you're looking for is on a coffee break. Try searching for something else or double-check that URL!",
            "Oh my! The page you're looking for must be in a meeting. Try searching for something else or double-check that URL!",
            "Oh brother! The page you're looking for is at the gym. Try searching for something else or double-check that URL!",
            "Yee Yee, the page you're looking for is at the rodeo. Try searching for something else or double-check that URL!",
        ];
        $message = $messages[array_rand($messages)];
        $view->error([
            'title' => 'Oops! Page \''.$page.'\' not found',
            'message' => $message,
        ]);
    }

    /**
     * Handle API requests and return JSON responses.
     *
     * @return void
     *
     * @throws \Exception When request method is invalid
     */
    private function handleApiRequest()
    {
        $endpoint = $_GET['endpoint'] ?? null;
        $route = $this->apiRoutes[$endpoint] ?? null;

        if (! $route) {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            exit;
        }

        try {
            try {
                $controller = 'Fbs\\Pcsre\\Controller\\'.$route['controller'];
                $method = $route['method'];

                if ($_SERVER['REQUEST_METHOD'] !== $route['requestMethod']) {
                    throw new \Exception('Invalid request method');
                }

                $controllerInstance = new $controller($this->pdo);
                $requestData = json_decode(file_get_contents('php://input'), true) ?? [];
                $result = $controllerInstance->$method($requestData);

                echo json_encode(['success' => true, 'data' => $result]);
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('Router Exception: '.$e->getMessage().' Trace: '.$e->getTraceAsString());
            echo json_encode(['error' => 'Internal Server Error']);
        }
        exit;
    }
}

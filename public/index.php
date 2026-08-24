<?php

declare(strict_types=1);

use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\Application;

/**
 * This makes our life easier when dealing with paths. Everything is relative
 * to the application root now.
 */
chdir(dirname(__DIR__));

// Decline static file requests back to the PHP built-in webserver
if (php_sapi_name() === 'cli-server') {
    $path = realpath(__DIR__.parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    if (is_string($path) && $path !== __FILE__ && is_file($path)) {
        return false;
    }
    unset($path);
}

// Composer autoloading
include __DIR__.'/../vendor/autoload.php';

// Cargar helpers (polyfills para Laravel)
require __DIR__.'/../helpers.php';

if (! class_exists(Application::class)) {
    throw new RuntimeException(
        "Unable to load application.\n"
        ."- Type `composer install` if you are developing locally.\n"
        ."- Type `docker-compose run laminas composer install` if you are using Docker.\n"
    );
}

$container = require __DIR__.'/../config/container.php';
// Run the application!
$app = $container->get('Application');

$request = $app->getRequest();
if ($request instanceof Request) {
    $uri = $request->getRequestUri();
    $scriptName = $request->getServer('SCRIPT_NAME', '');

    // Detectar basePath basado en la URI y SCRIPT_NAME.
    // Si la URL contiene /mavoo_gestion/gestion_laminas/public/,
    // el basePath es /mavoo_gestion/gestion_laminas/public (incluye /public).
    if (strpos($uri, '/mavoo_gestion/gestion_laminas/public/') !== false) {
        $basePath = '/mavoo_gestion/gestion_laminas/public';
        $request->setBasePath($basePath);
        $request->setBaseUrl($basePath);
    } elseif (strpos($uri, '/mavoo_gestion/gestion_laminas/') === 0) {
        $basePath = '/mavoo_gestion/gestion_laminas';
        $request->setBasePath($basePath);
        $request->setBaseUrl($basePath);
    } elseif (strpos($scriptName, '/mavoo_gestion/gestion_laminas/public/index.php') !== false) {
        $basePath = '/mavoo_gestion/gestion_laminas/public';
        $request->setBasePath($basePath);
        $request->setBaseUrl($basePath);
    } elseif (strpos($uri, '/gestion_laminas/public/') !== false) {
        $basePath = '/gestion_laminas/public';
        $request->setBasePath($basePath);
        $request->setBaseUrl($basePath);
    } elseif (strpos($uri, '/gestion_laminas/') === 0) {
        $basePath = '/gestion_laminas';
        $request->setBasePath($basePath);
        $request->setBaseUrl($basePath);
    }
}

$app->run();

<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Building the application is separated from running it so the HTTP tests can
 * exercise the very same routes, controllers and middleware the server does,
 * without a socket in between.
 *
 * The JSON body is decoded by the endpoints themselves rather than by body
 * parsing middleware: a malformed body is then a normal 422 from the same code
 * path as any other bad field, instead of a framework error.
 */
return static function (ContainerInterface $container): App {
    AppFactory::setContainer($container);

    $app = AppFactory::create();
    $app->addRoutingMiddleware();
    $app->addErrorMiddleware(false, true, true);

    (require __DIR__ . '/routes.php')($app);

    return $app;
};

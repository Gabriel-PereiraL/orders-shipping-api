<?php

declare(strict_types=1);

use OrderApi\Http\Middleware\JsonErrorHandler;
use OrderApi\Http\Middleware\RequestId;
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

    // Slim runs the last middleware added first, so this reads inside out:
    // routing is innermost, the error handler wraps it so that a missing route
    // is answered in the same JSON shape as everything else, and the request id
    // wraps both — it has to be on the request before the error handler reads
    // it, and on the response even when the request failed.
    $app->addRoutingMiddleware();

    $errorMiddleware = $app->addErrorMiddleware(false, true, true);
    $errorMiddleware->setDefaultErrorHandler(new JsonErrorHandler($app->getResponseFactory()));

    $app->add(new RequestId());

    (require __DIR__ . '/routes.php')($app);

    return $app;
};

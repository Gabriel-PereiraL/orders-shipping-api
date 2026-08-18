<?php

declare(strict_types=1);

use OrderApi\Http\Controller\HealthController;
use OrderApi\Http\Controller\OrderController;
use OrderApi\Http\Controller\ProductController;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', HealthController::class);

    $app->post('/products', [ProductController::class, 'create']);
    $app->get('/products/{id}', [ProductController::class, 'show']);

    $app->post('/orders', [OrderController::class, 'create']);
    $app->get('/orders/{id}', [OrderController::class, 'show']);
    $app->post('/orders/{id}/shipping-quote', [OrderController::class, 'quoteShipping']);
    $app->post('/orders/{id}/confirm', [OrderController::class, 'confirm']);
};

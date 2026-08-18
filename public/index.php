<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$container = (require __DIR__ . '/../config/container.php')($settings);
$app = (require __DIR__ . '/../config/app.php')($container);

$app->run();

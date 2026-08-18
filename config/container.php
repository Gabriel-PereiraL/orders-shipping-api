<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use GuzzleHttp\Client;
use OrderApi\Application\Order\ConfirmOrder;
use OrderApi\Application\Order\CreateOrder;
use OrderApi\Application\Port\Clock;
use OrderApi\Application\Port\OrderRepository;
use OrderApi\Application\Port\ProductRepository;
use OrderApi\Application\Port\ShippingQuoteProvider;
use OrderApi\Application\Product\CreateProduct;
use OrderApi\Infrastructure\Clock\SystemClock;
use OrderApi\Infrastructure\Persistence\Pdo\PdoOrderRepository;
use OrderApi\Infrastructure\Persistence\Pdo\PdoProductRepository;
use OrderApi\Infrastructure\Shipping\FakeShippingQuoteProvider;
use OrderApi\Infrastructure\Shipping\HttpShippingQuoteProvider;
use Psr\Container\ContainerInterface;

use function DI\autowire;
use function DI\get;

/**
 * The one file that knows which concrete thing sits behind each port.
 *
 * Use cases are autowired: they only ask for interfaces, so nothing needs
 * declaring beyond the scalars that come from configuration.
 *
 * @param array{db: array{host: string, port: int, name: string, user: string, password: string}, currency: string, shipping: array{provider: string, quote_ttl_seconds: int}} $settings
 */
return static function (array $settings): ContainerInterface {
    $builder = new ContainerBuilder();

    $builder->addDefinitions([
        'settings' => $settings,
        'currency' => $settings['currency'],

        PDO::class => static function () use ($settings): PDO {
            $db = $settings['db'];

            return new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
                $db['user'],
                $db['password'],
                [
                    // Exceptions, not silent false returns: a query that failed
                    // must never look like a query that found nothing.
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        },

        'quoteTtlSeconds' => $settings['shipping']['quote_ttl_seconds'],

        Clock::class => autowire(SystemClock::class),
        ProductRepository::class => autowire(PdoProductRepository::class),
        OrderRepository::class => autowire(PdoOrderRepository::class),
        // The one switch that decides whether this application talks to a real
        // carrier. Everything above the port is identical either way, which is
        // the whole point of having the port.
        ShippingQuoteProvider::class => static function (ContainerInterface $container) use ($settings): ShippingQuoteProvider {
            $shipping = $settings['shipping'];
            $clock = $container->get(Clock::class);

            if ($shipping['provider'] !== 'http') {
                return new FakeShippingQuoteProvider($clock);
            }

            if ($shipping['base_url'] === '') {
                throw new RuntimeException('SHIPPING_HTTP_BASE_URL must be set when SHIPPING_PROVIDER=http.');
            }

            return new HttpShippingQuoteProvider(
                new Client(['base_uri' => $shipping['base_url']]),
                $clock,
                $shipping['carrier'],
                'quotes',
                $shipping['timeout_seconds'],
                $shipping['connect_timeout_seconds'],
            );
        },

        CreateProduct::class => autowire()->constructorParameter('currency', get('currency')),
        CreateOrder::class => autowire()->constructorParameter('currency', get('currency')),
        ConfirmOrder::class => autowire()->constructorParameter('quoteTtlSeconds', get('quoteTtlSeconds')),
    ]);

    return $builder->build();
};

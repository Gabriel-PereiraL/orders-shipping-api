<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Persistence\Pdo;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Application\Port\OrderRepository;
use OrderApi\Domain\Order\Order;
use OrderApi\Domain\Order\OrderItem;
use OrderApi\Domain\Order\OrderStatus;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;
use OrderApi\Infrastructure\Persistence\PersistenceFailure;
use PDO;
use PDOException;
use Throwable;

/**
 * An order is written as one unit: the row and its lines are replaced together
 * inside a transaction, so a crash halfway through cannot leave an order whose
 * total no longer matches its items.
 *
 * Times are stored as UTC DATETIME. MySQL's TIMESTAMP would convert against the
 * server's time zone, which means the same row can read back differently on a
 * differently configured server.
 */
final readonly class PdoOrderRepository implements OrderRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private PDO $connection)
    {
    }

    public function save(Order $order): void
    {
        try {
            $this->connection->beginTransaction();

            $this->upsertOrder($order);

            // Lines are replaced wholesale rather than diffed: an order has a
            // handful of them, and "delete then insert" cannot drift out of
            // sync with the aggregate the way a partial update can.
            $delete = $this->connection->prepare('DELETE FROM order_items WHERE order_id = :order_id');
            $delete->execute(['order_id' => $order->id]);

            $insert = $this->connection->prepare(
                'INSERT INTO order_items
                    (order_id, product_id, product_name, unit_price_cents, weight_grams, quantity)
                 VALUES (:order_id, :product_id, :product_name, :unit_price_cents, :weight_grams, :quantity)',
            );

            foreach ($order->items() as $item) {
                $insert->execute([
                    'order_id' => $order->id,
                    'product_id' => $item->productId,
                    'product_name' => $item->productName,
                    'unit_price_cents' => $item->unitPrice->cents,
                    'weight_grams' => $item->weightGrams,
                    'quantity' => $item->quantity,
                ]);
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw PersistenceFailure::while('saving an order', $exception);
        }
    }

    public function findById(string $id): ?Order
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT id, status, destination_zip_code, currency,
                        shipping_carrier, shipping_service, shipping_amount_cents,
                        shipping_estimated_days, shipping_quoted_at,
                        created_at, confirmed_at
                 FROM orders WHERE id = :id',
            );
            $statement->execute(['id' => $id]);

            /** @var array<string, string|int|null>|false $row */
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            $itemsStatement = $this->connection->prepare(
                'SELECT product_id, product_name, unit_price_cents, weight_grams, quantity
                 FROM order_items WHERE order_id = :order_id ORDER BY id',
            );
            $itemsStatement->execute(['order_id' => $id]);

            /** @var list<array<string, string|int>> $itemRows */
            $itemRows = $itemsStatement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw PersistenceFailure::while('reading an order', $exception);
        }

        return $this->hydrate($row, $itemRows);
    }

    private function upsertOrder(Order $order): void
    {
        $quote = $order->shippingQuote();
        $confirmedAt = $order->confirmedAt();

        $statement = $this->connection->prepare(
            'INSERT INTO orders
                (id, status, destination_zip_code, currency,
                 shipping_carrier, shipping_service, shipping_amount_cents,
                 shipping_estimated_days, shipping_quoted_at, created_at, confirmed_at)
             VALUES
                (:id, :status, :destination_zip_code, :currency,
                 :carrier, :service, :amount_cents,
                 :estimated_days, :quoted_at, :created_at, :confirmed_at)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                shipping_carrier = VALUES(shipping_carrier),
                shipping_service = VALUES(shipping_service),
                shipping_amount_cents = VALUES(shipping_amount_cents),
                shipping_estimated_days = VALUES(shipping_estimated_days),
                shipping_quoted_at = VALUES(shipping_quoted_at),
                confirmed_at = VALUES(confirmed_at)',
        );

        $statement->execute([
            'id' => $order->id,
            'status' => $order->status()->value,
            'destination_zip_code' => $order->destinationZipCode,
            'currency' => $order->currency,
            'carrier' => $quote?->carrier,
            'service' => $quote?->service,
            'amount_cents' => $quote?->amount->cents,
            'estimated_days' => $quote?->estimatedDays,
            'quoted_at' => $quote?->quotedAt->format(self::DATETIME_FORMAT),
            'created_at' => $order->createdAt->format(self::DATETIME_FORMAT),
            'confirmed_at' => $confirmedAt?->format(self::DATETIME_FORMAT),
        ]);
    }

    /**
     * @param array<string, string|int|null> $row
     * @param list<array<string, string|int>> $itemRows
     */
    private function hydrate(array $row, array $itemRows): Order
    {
        $currency = (string) $row['currency'];

        $items = array_map(
            static fn (array $itemRow): OrderItem => new OrderItem(
                (string) $itemRow['product_id'],
                (string) $itemRow['product_name'],
                Money::fromCents((int) $itemRow['unit_price_cents'], $currency),
                (int) $itemRow['weight_grams'],
                (int) $itemRow['quantity'],
            ),
            $itemRows,
        );

        $quote = $row['shipping_amount_cents'] === null ? null : new ShippingQuote(
            (string) $row['shipping_carrier'],
            (string) $row['shipping_service'],
            Money::fromCents((int) $row['shipping_amount_cents'], $currency),
            (int) $row['shipping_estimated_days'],
            $this->toDateTime((string) $row['shipping_quoted_at']),
        );

        return Order::reconstitute(
            (string) $row['id'],
            (string) $row['destination_zip_code'],
            $currency,
            $this->toDateTime((string) $row['created_at']),
            OrderStatus::from((string) $row['status']),
            $items,
            $quote,
            $row['confirmed_at'] === null ? null : $this->toDateTime((string) $row['confirmed_at']),
        );
    }

    private function toDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

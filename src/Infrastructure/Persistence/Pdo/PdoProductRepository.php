<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Persistence\Pdo;

use OrderApi\Application\Exception\DuplicateSku;
use OrderApi\Application\Port\ProductRepository;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Infrastructure\Persistence\PersistenceFailure;
use PDO;
use PDOException;

final readonly class PdoProductRepository implements ProductRepository
{
    private const DUPLICATE_ENTRY = 1062;

    public function __construct(private PDO $connection)
    {
    }

    public function save(Product $product): void
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO products (id, sku, name, price_cents, currency, weight_grams, created_at)
                 VALUES (:id, :sku, :name, :price_cents, :currency, :weight_grams, UTC_TIMESTAMP())',
            );

            $statement->execute([
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price_cents' => $product->price->cents,
                'currency' => $product->price->currency,
                'weight_grams' => $product->weightGrams,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                throw DuplicateSku::forSku($product->sku);
            }

            throw PersistenceFailure::while('saving a product', $exception);
        }
    }

    public function findById(string $id): ?Product
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT id, sku, name, price_cents, currency, weight_grams FROM products WHERE id = :id',
            );
            $statement->execute(['id' => $id]);

            /** @var array{id: string, sku: string, name: string, price_cents: int|string, currency: string, weight_grams: int|string}|false $row */
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw PersistenceFailure::while('reading a product', $exception);
        }

        if ($row === false) {
            return null;
        }

        return new Product(
            $row['id'],
            $row['sku'],
            $row['name'],
            Money::fromCents((int) $row['price_cents'], $row['currency']),
            (int) $row['weight_grams'],
        );
    }

    private function isDuplicateEntry(PDOException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY;
    }
}

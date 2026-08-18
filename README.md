# Orders & Shipping API

A small PHP backend that takes an order from creation to confirmation, pricing
the freight through a carrier along the way.

This is a deliberately small technical project. It exists to make public some of
the practices I use in professional systems that live in private repositories:
how I separate business rules from infrastructure, how I model failure, how I
decide what deserves a test, and how I keep an environment reproducible.

It is not a product, and it is not trying to be impressive. Every part of it
should be explainable in one sentence — and if a layer, interface or abstraction
could not be, it was not written.

[![CI](https://github.com/Gabriel-PereiraL/orders-shipping-api/actions/workflows/ci.yml/badge.svg)](https://github.com/Gabriel-PereiraL/orders-shipping-api/actions/workflows/ci.yml)

---

## What it does

1. Register products (price, weight).
2. Create an order with products and quantities; it calculates the subtotal.
3. Ask a shipping provider for a freight price.
4. Return the total (subtotal + freight).
5. Confirm the order.
6. Read the order back later.

The shipping provider is an abstraction with two implementations: a local
simulator that runs with no external account, and a real HTTP adapter. Both fail
in the same four ways — success, timeout, unavailable, unusable response — and
the application answers each of them differently, on purpose.

## Stack

| | |
|---|---|
| Language | PHP 8.2 |
| HTTP | Slim 4 (PSR-7 / PSR-15) |
| DI | PHP-DI 7 |
| Database | MySQL 8.4, plain PDO |
| Migrations | Phinx |
| HTTP client | Guzzle 7 |
| Tests | PHPUnit 11 |
| Static analysis | PHPStan level 8 |
| Style | PHP-CS-Fixer (PSR-12) |
| Environment | Docker Compose (nginx + php-fpm + MySQL) |

A micro-framework rather than a full one: the point of this repository is to
show how the pieces are arranged, and a large framework would arrange most of
them for me.

## Architecture

Four layers, each with one job. Dependencies only ever point inwards.

```
HTTP  ──▶  Application  ──▶  Domain
             │
             ▼ (ports)
       Infrastructure ── implements the ports
```

**Domain** — the rules. What an order is, when it may be confirmed, how a
subtotal is calculated, why a quantity of zero is not a quantity. It does not
know that MySQL, HTTP, Docker or Slim exist. This is checked by the build, not
promised in a README: see `tests/Unit/Architecture/LayerBoundariesTest.php`,
which fails if anything in `src/Domain` imports anything outside itself.

**Application** — the use cases. They fetch, call the domain, call a port, store
the result. They contain sequence, not rules: whether an order *may* be
confirmed is `Order::confirm()`'s decision, not `ConfirmOrder`'s.

**Infrastructure** — the implementations: SQL repositories, the carrier
adapters, the system clock. Everything that can fail for environmental reasons
lives here, and this is where those failures are translated into the
application's own vocabulary so that a `PDOException` never travels upwards.

**HTTP** — translation only. JSON in, typed values out; domain object in, JSON
out. Controllers read a payload, call one use case, render the result.

### Ports, and why there are only four

An interface is written here when there are two real implementations or a test
seam that cannot exist without one:

| Port | Why it exists |
|---|---|
| `ProductRepository`, `OrderRepository` | MySQL in production, in-memory in the use case tests |
| `ShippingQuoteProvider` | local simulator vs. real HTTP carrier |
| `Clock` | quotes expire, so "what time is it" changes the outcome and must be controllable in tests |

There is no `UnitOfWork`, no event bus, no DTO per layer, no mapper factory, and
no interface with a single implementation.

### Directory structure

```
config/          settings, DI container, routes, app assembly
docker/          php-fpm image, nginx config, MySQL init
migrations/      Phinx migrations
public/          index.php — the only entry point
src/
  Domain/        Money, Product, Order, OrderItem, OrderStatus, ShippingQuote
  Application/   use cases + Port/ (the interfaces above)
  Infrastructure/ PDO repositories, shipping adapters, system clock
  Http/          controllers, payload reader, presenters, error handler
tests/
  Unit/          domain, use cases, adapters — no database, no network
  Integration/   repositories and the API against a real MySQL
  Support/       in-memory repositories, fixed clock, carrier stub
```

## Running it

```bash
docker compose up -d          # nginx, php-fpm and MySQL
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phinx migrate -e development
```

The API is then on <http://localhost:8080>.

```bash
curl http://localhost:8080/health
```

There is no `.env` to create: every value has a working default in
`docker-compose.yml`. Copy `.env.example` to `.env` only to override one.
MySQL is published on **3307** so it does not collide with a MySQL already
running on your machine.

A `Makefile` wraps the same commands (`make up`, `make install`, `make migrate`,
`make test`, `make check`).

## Running the tests

```bash
make migrate-test                                        # once
make test                                                # everything
docker compose run --rm app vendor/bin/phpunit --testsuite unit
```

The unit suite needs nothing but PHP and runs in about a second; you can run it
on the host with `vendor/bin/phpunit --testsuite unit`. The integration suite
needs the database and **fails loudly** if it cannot reach it, rather than
skipping itself — a silently skipped integration suite still goes green, which
is worse than not having one.

CI runs code style, static analysis and the full suite, including integration,
against a real MySQL service on every push and pull request.

## API

All request and response bodies are JSON. Money is always an integer number of
cents plus a currency, never a decimal number: JSON numbers are floats in most
clients, and a float is exactly what the domain avoids internally.

| Method | Path | Success | Errors |
|---|---|---|---|
| `GET` | `/health` | 200 | — |
| `POST` | `/products` | 201 | 409 duplicate sku, 422 invalid |
| `GET` | `/products/{id}` | 200 | 404 |
| `POST` | `/orders` | 201 | 404 unknown product, 422 invalid |
| `GET` | `/orders/{id}` | 200 | 404 |
| `POST` | `/orders/{id}/shipping-quote` | 200 | 404, 409 already confirmed, 502, 503 |
| `POST` | `/orders/{id}/confirm` | 200 | 404, 409 no/expired quote or already confirmed |

Quoting is a `POST` because it is not free: it calls a carrier, and it changes
the order by attaching the price it got back.

### Create a product

```bash
curl -X POST http://localhost:8080/products \
  -H 'Content-Type: application/json' \
  -d '{"sku":"KB-01","name":"Mechanical keyboard","price_cents":24990,"weight_grams":900}'
```

```json
{
  "id": "01a0154a-bad5-7202-b592-dcb78e0b0cf9",
  "sku": "KB-01",
  "name": "Mechanical keyboard",
  "price": { "amount_cents": 24990, "currency": "BRL" },
  "weight_grams": 900
}
```

### Create an order

```bash
curl -X POST http://localhost:8080/orders \
  -H 'Content-Type: application/json' \
  -d '{"destination_zip_code":"01310-100","items":[{"product_id":"01a0154a-...","quantity":2}]}'
```

```json
{
  "id": "01a0154c-d39a-73db-a3ff-c079d80fe492",
  "status": "draft",
  "destination_zip_code": "01310100",
  "items": [
    {
      "product_id": "01a0154a-...",
      "product_name": "Mechanical keyboard",
      "unit_price": { "amount_cents": 24990, "currency": "BRL" },
      "quantity": 2,
      "subtotal": { "amount_cents": 49980, "currency": "BRL" }
    }
  ],
  "subtotal": { "amount_cents": 49980, "currency": "BRL" },
  "shipping": null,
  "total": null,
  "created_at": "2026-08-18T14:35:51+00:00",
  "confirmed_at": null
}
```

`total` is `null` until the freight is known. A client that shows a total before
shipping has been quoted is showing a number the customer will not be charged.

### Quote the shipping

```bash
curl -X POST http://localhost:8080/orders/{id}/shipping-quote
```

```json
{
  "status": "draft",
  "subtotal": { "amount_cents": 49980, "currency": "BRL" },
  "shipping": {
    "carrier": "ACME Express (simulated)",
    "service": "standard",
    "amount": { "amount_cents": 3599, "currency": "BRL" },
    "estimated_days": 2,
    "quoted_at": "2026-08-18T14:35:55+00:00"
  },
  "total": { "amount_cents": 53579, "currency": "BRL" }
}
```

### Confirm

```bash
curl -X POST http://localhost:8080/orders/{id}/confirm
```

Returns the same shape with `"status": "confirmed"` and a `confirmed_at`.

### Errors

Every failure answers with `application/problem+json` in one shape, and every
response carries an `X-Request-Id` (honoured if you supply one) that also
appears in the body and in the log line for a 500.

```json
{
  "type": "invalid_request",
  "status": 422,
  "title": "The request payload is invalid.",
  "errors": {
    "sku": "Expected a non-empty string.",
    "price_cents": "Expected an integer."
  },
  "request_id": "01a0154f-2221-712f-86e0-c1d025809196"
}
```

| Situation | Status | `type` |
|---|---|---|
| malformed body, or a value the business rejects | 422 | `invalid_request` |
| unknown product, order or route | 404 | `not_found` |
| confirm twice, confirm without a quote, expired quote, change a confirmed order | 409 | `order_state_conflict` |
| sku already in use | 409 | `duplicate_sku` |
| carrier timed out or is unreachable | 503 + `Retry-After` | `shipping_provider_unavailable` |
| carrier answered with something unusable | 502 | `shipping_provider_invalid_response` |
| anything unexpected | 500 | `internal_error` |

The split between 503 and 502 is the point: 503 says *try again*, 502 says
*trying again will not help, their contract is broken*. A client that cannot
tell those apart will either hammer a broken integration or give up on a passing
outage.

### Simulating carrier failures by hand

The local provider reserves three zip prefixes so every failure path is
reachable with `curl`, not only from tests:

| Destination zip | What the carrier does | Response |
|---|---|---|
| `999xxxxx` | never answers | 503 |
| `998xxxxx` | refuses the connection | 503 |
| `997xxxxx` | answers with an unusable payload | 502 |
| anything else | quotes normally | 200 |

To point at a real carrier instead, set `SHIPPING_PROVIDER=http` and
`SHIPPING_HTTP_BASE_URL`. Nothing above the port changes.

## Technical decisions

**Money is an integer number of cents.** `0.1 + 0.2 !== 0.3`, and the error
compounds once you multiply by quantities and sum lines. `Money` has no
`subtract()` because this domain never produces a negative amount; adding one
would only widen the set of states to defend against.

**Order lines are snapshots.** An item copies the product's name, price and
weight at the moment it is added. When the catalogue raises a price tomorrow,
orders placed today keep the price the customer agreed to. This is also why
`order_items.product_id` is deliberately *not* a foreign key: the line must
survive the product being renamed, repriced or removed.

**Business rules live in the domain; the database protects structure.**
Uniqueness, relationships and referential integrity are the schema's job — the
unique key on `sku`, the unique key on `(order_id, product_id)`, the foreign key
from items to orders. Rules like "an order cannot be confirmed twice" or "a
quantity must be at least 1" are the domain's job, because they are decisions
about behaviour rather than about the shape of the data.

**A shipping quote expires.** Confirming an order whose quote is fifteen minutes
old would charge a price the carrier no longer offers. The rule lives in the
order; the *number* is configuration, injected. This is the reason the `Clock`
port exists — the rule would otherwise be untestable without sleeping.

**Only two order states.** `draft` and `confirmed`. There is no payment and no
fulfilment here, so `processing` and `shipped` would be states nothing could
ever reach.

**Errors are typed by category, translated once.** Each layer throws in its own
vocabulary and none of them mentions a status code; `JsonErrorHandler` is the
only place that maps exception to HTTP. Adding a rule to the domain does not
mean touching a controller.

**No retry in the carrier adapter.** Retrying belongs to whoever knows whether
the request is safe to repeat and how long the caller will wait. Hiding it in
the adapter would double a customer's wait during exactly the outage the
timeout exists to bound.

**Orders are written whole, inside a transaction.** Items are replaced rather
than diffed: an order has a handful of lines, and delete-then-insert cannot
drift out of sync with the aggregate. There is an integration test that provokes
a mid-write failure and asserts nothing half-saved survives.

## Trade-offs

Things that are defensible rather than universally right:

- **Identifiers are plain strings**, not value objects. The only boundary where
  a malformed id can enter is HTTP, and it is checked once there. Wrapping every
  id would add two classes to prevent a mistake PHPStan mostly catches anyway.
- **Products are fetched one at a time** when creating an order. Orders here hold
  a handful of lines; a batch lookup would buy nothing and cost a less obvious
  repository.
- **The shipping quote lives in the `orders` row**, not in its own table. An
  order has at most one, it is replaced rather than accumulated, and it is never
  read on its own.
- **`Order::reconstitute()` bypasses the invariants** the other methods enforce.
  Replaying rules on load would mean an order could stop being loadable because
  a rule changed after it was placed.
- **No logging library.** There is exactly one place that logs (unexpected 500s)
  and `error_log` reaches the container's stderr. A PSR-3 stack for one call site
  would be furniture.
- **Migrations are plain SQL** instead of the schema builder. This targets MySQL
  only, and the exact types matter here.
- **The image is a development image.** It bind-mounts the source rather than
  copying it. A production image would copy the code, install without dev
  dependencies and drop Composer.

## Deliberately not implemented

Authentication · pagination and filtering · order cancellation · stock and
inventory · payment · idempotency keys · retry and circuit breaking · caching ·
API versioning · an ORM · domain events · a coverage target.

Each of these is a real concern in a real system. None of them is needed to show
how this one is put together, and adding them would make the repository harder
to read rather than more convincing. The tests here are meant to prove
behaviour — happy path, edge cases and failure paths — not to move a percentage.

## License

MIT — see [LICENSE](LICENSE).

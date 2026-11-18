# Tests

- `php tests/unit.php` — the out-of-stock-ordering rule (every out-of-stock behaviour × the shop setting × quantity, combinations, per shop), webhook signature, order status and address mapping, price markup maths, server setting, request batching, and mirror mode (scan paging and failures, what is switched off, the over-half guard and "Apply anyway", the preview, batching above 200 rows) against a fake Partner API.
  No PrestaShop needed; runs on PHP 7.2 – 8.5.
- `node tests/e2e/run.mjs` — builds `dist/fetchsync-1.3.1.zip`, boots PrestaShop 8.1 + MariaDB with `docker compose` (`tests/e2e/docker-compose.yml`, port 8088), mounts the unzipped release as `modules/fetchsync`, points it at a local mock of the Fetch Partner API (`mock-fetch.mjs`, port 9099, reached from the container as `host.docker.internal`) and runs the full flow through `driver.php`, the cron URL and the webhook URL.
  It covers install and uninstall, connect, product and combination sync, stock changes, 429 back-off, the price markup, order import (poll and webhook), duplicates, signatures, accept / ready / reject, cancel, refund, masked customer data, auto-accept, product deletion, "Exclude backorder items" (a backorder product is sent with stock 0 and not created, one already linked keeps updating, stock returning creates it, combinations, the default behaviour following the shop setting, the setting off) and mirror mode (off, preview, a failing scan page, switching off foreign / no-ref / filtered-out / deleted listings, reactivation, the over-half guard with "Apply anyway", batches of at most 200 rows).
  The mock keeps the store's listings: `PUT /products` creates them, `GET /listings` pages them with a cursor like live, and `POST /listings/batch` applies the live rules (a price on every row, at most 200 rows, at most 500 KB).
  The first run downloads the images and takes a few minutes; the containers are removed at the end.
- `node tests/e2e/multistore.mjs` — the same stack with multistore on and three shops: A (the default shop, store-1), B (its own domain, `127.0.0.1:<port>`, its own price and stock, store-2) and C (another shop group that shares stock, created without the module's data, store-3, 10% markup).
  B and C share one Partner key that covers store-2 and store-3.
  It also checks "Exclude backorder items" per shop (one product, three shops with different ordering rules). It checks that settings are per shop and that a shop never inherits another's store, that two shops cannot pick one store, that each store receives its shop's price and stock, that an order for store-2 lands in shop B (and store-3's in shop C), that webhooks are routed by store and verified with that shop's secret on any domain, that mirror mode on shop A leaves store-2 alone, that one failing key does not stop the cron for the others, and the "All shops" overview.
- `node tests/e2e/upgrade.mjs` — installs the module from the git tag `v1.3.0` (`FROM_REF`; `v1.2.0` works too), connects it, syncs and imports an order, then puts this tree's files in place and runs PrestaShop's module upgrade.
  It checks that "Exclude backorder items" is turned on, the table keys, that every setting is kept, that the shop still syncs, does not import the old order twice and accepts the old webhook secret, and that turning multistore on afterwards keeps the default shop connected while the new shop starts empty.

The mock keeps listings and orders per store and knows two keys: `client-1` (store-1, webhook secret `whsec_test`) and `client-2` (store-2 and store-3, a new secret on every registration).

Options:

| Variable | Effect |
|---|---|
| `E2E_KEEP=1` | Leave PrestaShop running afterwards (the mock stops with the runner). |
| `E2E_REUSE=1` | Reuse a running stack and mock; skips the install/uninstall checks. |
| `E2E_SOURCE=1` | Mount `fetchsync/` instead of the unzipped ZIP. |
| `MOCK_PORT` | Port of the mock Fetch API (default 9099). |
| `FROM_REF` | `upgrade.mjs`: the git ref of the release to upgrade from (default `v1.3.0`). |
| `PS_IMAGE`, `PS_PORT`, `COMPOSE_PROJECT_NAME` | Another PrestaShop image, port or project, e.g. `PS_IMAGE=prestashop/prestashop:1.7.8.11-7.2-apache PS_PORT=8097 COMPOSE_PROJECT_NAME=fetchsync-e2e-17`. |

The local admin is `http://localhost:8088/admin-dev/` with `admin@example.com` / `fetch-e2e-admin` (test container only).
`driver.php` and the compose file are test-only and are not part of the ZIP.

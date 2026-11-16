# Tests

- `php tests/unit.php` — webhook signature, order status and address mapping, price markup maths, server setting and request batching.
  No PrestaShop needed; runs on PHP 7.2 – 8.5.
- `node tests/e2e/run.mjs` — builds `dist/fetchsync-1.0.0.zip`, boots PrestaShop 8.1 + MariaDB with `docker compose` (`tests/e2e/docker-compose.yml`, port 8088), mounts the unzipped release as `modules/fetchsync`, points it at a local mock of the Fetch Partner API (`mock-fetch.mjs`, port 9099, reached from the container as `host.docker.internal`) and runs the full flow through `driver.php`, the cron URL and the webhook URL.
  It covers install and uninstall, connect, product and combination sync, stock changes, 429 back-off, the price markup, order import (poll and webhook), duplicates, signatures, accept / ready / reject, cancel, refund, masked customer data, auto-accept and product deletion.
  The first run downloads the images and takes a few minutes; the containers are removed at the end.

Options:

| Variable | Effect |
|---|---|
| `E2E_KEEP=1` | Leave PrestaShop running afterwards (the mock stops with the runner). |
| `E2E_REUSE=1` | Reuse a running stack and mock; skips the install/uninstall checks. |
| `E2E_SOURCE=1` | Mount `fetchsync/` instead of the unzipped ZIP. |
| `PS_IMAGE`, `PS_PORT`, `COMPOSE_PROJECT_NAME` | Another PrestaShop image, port or project, e.g. `PS_IMAGE=prestashop/prestashop:1.7.8.11-7.2-apache PS_PORT=8097 COMPOSE_PROJECT_NAME=fetchsync-e2e-17`. |

The local admin is `http://localhost:8088/admin-dev/` with `admin@example.com` / `fetch-e2e-admin` (test container only).
`driver.php` and the compose file are test-only and are not part of the ZIP.

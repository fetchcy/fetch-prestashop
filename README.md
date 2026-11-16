# Fetch for PrestaShop

A PrestaShop module that sells your catalogue on [Fetch](https://fetch.com.cy), Cyprus's delivery marketplace.
It keeps your products, prices and stock in sync with your Fetch store, and brings Fetch orders into PrestaShop as normal orders.

It uses the [Fetch Partner API](https://developer.fetch.com.cy) and works the same way as the Fetch for WooCommerce plugin.

## Features

- **Automatic product sync.** New products, edits, price changes, sales and stock changes are sent to Fetch at the end of the request that made them.
  This includes stock changes caused by orders on your own website.
- **Fetch orders as PrestaShop orders.** Each Fetch order appears in **Orders**, paid on Fetch, and reduces your stock like any other order.
- **Accept, mark ready or reject from PrestaShop.** A **Fetch** block on the order page sends your decision straight to Fetch, which books the courier.
- **Instant order alerts with a safety net.** Orders arrive within seconds through a signed webhook, and the module also checks every 2 minutes so nothing is missed.
- **Optional auto-accept** with your usual preparation time.
- **Optional price markup** for the prices you show on Fetch.

## Requirements

| | Minimum |
|---|---|
| PrestaShop | 8.1 (tested on 8.1.7). 1.7.8 also works (tested on 1.7.8.11). |
| PHP | 7.2 – 8.1, as your PrestaShop version allows, with the `curl` extension |
| HTTPS | Required for instant order alerts (webhooks) |
| Cron | Recommended: a server cron job calling the module's cron URL every 1–2 minutes |
| Fetch | A Partner API key for your store |

## Installation

1. Ask Fetch for a **Partner API key** for your store.
   You receive a **client ID** and a **client secret**.
2. In the back office, go to **Modules → Module Manager → Upload a module** and upload `fetchsync-1.0.0.zip`.
   PrestaShop installs it.
3. Press **Configure** on the **Fetch** module.
4. Enter your client ID and client secret, press **Save settings**, then press **Connect & load stores** and choose your Fetch store.
5. Press **Sync all products now** to send your catalogue the first time.
   Large catalogues are sent in the background in batches of 50 products.
6. Press **Register webhook** so new orders arrive instantly.
7. Add the **Cron URL** shown on the page to your server's crontab, for example:

   ```text
   * * * * * curl -s "https://your-shop.example/module/fetchsync/cron?token=…" > /dev/null
   ```

8. Optional: turn on **Auto-accept** and set your usual **Preparation time**.

Installing the module creates its own database tables, four order states, a hidden carrier called **Fetch delivery** and a hidden, inactive product called **Fetch item (not in your catalogue)**.
Do not delete the carrier or the product; the module uses them to build Fetch orders.

## Settings

| Setting | What it does |
|---|---|
| Server | **Production** (`https://api.fetch.com.cy`) is the default. Live keys, and sandbox keys Fetch issues for testing, both use Production; your key decides whether you see test data. **Staging** (`https://api-stg.fetch.com.cy`) is only for keys Fetch has moved to staging. **Custom URL** only if Fetch gives you a different address. Changing the server clears the stored token, store and webhook secret. |
| Custom URL | Used only with **Custom URL**. A trailing slash is removed. |
| Client ID / Client secret | Your Partner API key. The secret is stored in your PrestaShop database and is never shown again after saving; leave the field blank to keep it. |
| Fetch store | The Fetch store your products and orders belong to. Filled in by **Connect & load stores**. |
| Sync to Fetch | Turns automatic product, price and stock sync on or off. |
| Price markup (%) | Added to every price and sale price sent to Fetch, for example `10` sends a €10.00 product as €11.00. Your PrestaShop prices are not changed. Accepts 0 to 100 with up to two decimals (`12,5` and `15 %` are accepted). The VAT-inclusive price is rounded to the cent first, then the markup is added and rounded half up (9.99 at 15% is 11.49). Stock is never affected. Changing the markup resends all products to Fetch. Imported Fetch orders keep the prices the customer paid on Fetch. |
| Import Fetch orders | Turns order import on or off. |
| Auto-accept | Accepts new Fetch orders automatically, using the preparation time below. |
| Preparation time | Minutes you need to prepare an order (0–180). The courier is booked to collect after this. |
| Instant order alerts | Shows your webhook URL. **Register webhook** registers it with Fetch and stores the signing secret. Press it again to issue a new secret. |
| Cron URL | The URL your server's cron should call. The token in it is generated at install time. |

## How products are synced

### Matching

Every product is matched on Fetch by a **reference** (`ref`):

- the product's **reference**, or the **combination's reference** for a product with combinations;
- `ps-<product id>` (or `ps-<product id>-<combination id>`) when there is no reference.

PrestaShop copies a product's reference into its new combinations.
A reference shared by several combinations of the same product cannot tell them apart, so those combinations use `ps-<product id>-<combination id>`.
Give each combination its own reference to use your codes on Fetch.

The reference is remembered after the first successful sync.
Changing a reference later updates the same Fetch product instead of creating a second one.

If you sell a product that Fetch already carries in its shared catalogue (matched by reference, then barcode), only **your price, stock and availability** are updated.
Fetch maintains the name, photos and category of shared catalogue products.

### What is sent

| PrestaShop | Fetch |
|---|---|
| Name, description (or summary) | Name, description (HTML is stripped) |
| Price, specific prices (sales) | Price and sale price, **VAT included**, as an anonymous visitor in your shop's default country would pay, plus the price markup |
| Quantity (stock management on) | Exact quantity (never below 0) |
| Stock management off | Sold without a stock limit |
| Enabled, visible somewhere, available for order, in the default shop | Active on Fetch |
| Disabled, visibility "Nowhere", not available for order | Inactive on Fetch |
| Deleted product or combination | Switched off on Fetch (it keeps existing there for order history) |
| Cover image and other images | Photos (set when the product is first created on Fetch) |
| Default category (or the deepest category when it is Home) | Category path, for example `Clothes > Men`, matched against Fetch's categories |
| Tags, brand (manufacturer), EAN-13 / UPC / ISBN | Tags, brand, barcode |

Standard products and products with combinations are synced.
Packs and virtual products are not.

### Products with combinations

Fetch lists each combination as its own product.
A T-shirt in sizes S, M and L becomes three Fetch products, for example **"Logo T-shirt – Black / M"**, each with its own reference, price, stock, barcode and images.
The product's description, brand, category and tags are shared by all of them.
A combination only uses its own barcode, never the product's.

### Timing

- Changes are queued and sent at the end of the request that made them, in batches of up to 200 products (and under 500 KB) per API call.
  Where the server supports it (PHP-FPM, LiteSpeed), the page is sent to the browser first.
- If Fetch cannot be reached, or answers `429 Too Many Requests`, the products stay queued and are retried later (after `Retry-After`, then 2, 4, 8 … minutes, at most hourly).
- A sale that starts or ends on a date is picked up by the next cron run after that date.
- The configuration page shows how many changes are waiting, and **Recent activity** shows each batch.

## How orders work

1. A customer orders from your store on Fetch.
2. The order appears in **Orders** with the status **Fetch: waiting for you to accept**.
   It has the customer's name, phone, delivery address, note, items and delivery fee, and your stock is reduced.
   - Items are linked to your products by reference, at the price the customer paid on Fetch.
   - Items that match no product are added by name, with a note on the order, and reduce no stock.
   - The delivery fee is the shipping cost, on the **Fetch delivery** carrier.
   - The payment is **Paid on Fetch**; its transaction ID is the Fetch order ID.
3. Open the order and use the **Fetch** block:
   - **Accept order** with the minutes you need to prepare it;
   - **Mark ready for pickup** when it is packed;
   - **Reject order** with a reason (out of stock, store closed, cannot fulfil, other) and an optional note.
4. Fetch books the courier and handles delivery.
   The PrestaShop order follows along automatically.

| Fetch status | PrestaShop order status |
|---|---|
| Waiting for you to accept | Fetch: waiting for you to accept |
| Accepted, preparing, ready for pickup, out for delivery | Fetch: accepted |
| Delivered | Fetch: delivered |
| Cancelled or rejected | Canceled (stock is restored) |
| Refunded | Fetch: refunded (stock is restored) |

Accept new Fetch orders promptly: the customer has already paid.
Auto-accept does this for you.

### Customers, e-mails and privacy

Each Fetch order gets its own guest customer with an address at `fetch.invalid`, a reserved domain that cannot receive mail.
The module also blocks every e-mail PrestaShop tries to send to such an address, so Fetch customers never get order confirmations or status e-mails from your shop.
E-mails to you (for example new-order alerts) are not affected.

Fetch hides the customer's name, phone and address 2 hours after an order is placed.
The module stores them as soon as it sees the order, through the webhook or the 2-minute check.
If an order is first seen after that, it is imported with placeholder details (**Fetch customer**, "Address not provided"), and filled in if Fetch later sends the real details.
Details already stored are never overwritten with hidden ones.

## Webhooks

Fetch sends a signed `POST` to:

```text
https://<your-shop>/module/fetchsync/webhook
https://<your-shop>/index.php?fc=module&module=fetchsync&controller=webhook   (without friendly URLs)
```

for these events:

- `order.created`
- `order.status_changed`
- `order.cancelled`

Each request carries an `X-Fetch-Signature` header:

```text
X-Fetch-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with your signing secret>
```

The module rejects requests with a wrong signature or a timestamp more than 5 minutes off (`401`).
Fetch retries failed deliveries with the same `eventId`, and the module processes each event once.
The endpoint works in maintenance mode too.

If you cannot use webhooks (for example a shop without HTTPS), the 2-minute check still imports every order.

## Cron and background work

The cron URL (`/module/fetchsync/cron?token=…`) sends queued product changes, catches sales that start or end, and checks Fetch for orders.
Call it every 1–2 minutes.

Without a cron job, the module does the same work at most every 2 minutes at the end of an ordinary page view of your shop or back office.
On a quiet shop that can delay orders, so set up the cron job.

## Multistore

The module works with your **default shop**: its products, prices, stock, language and currency, and one Fetch store.
Settings are shared by all shops.
Other shops of a multistore installation are not synced.

## Troubleshooting

| Problem | What to check |
|---|---|
| "Connect & load stores" fails | Client ID and secret, the **Server** setting, and that the key is for your store. |
| A product does not appear on Fetch | **Recent activity** on the configuration page, and **Advanced Parameters → Logs** (object type `FetchSync`) show Fetch's reason. Check the product is enabled, visible and available for order. |
| Combinations share one reference | They are sent as `ps-<product id>-<combination id>`. Give each combination its own reference. |
| A category is not applied | The category path must match a Fetch category. The product is still listed, without that category. |
| Orders arrive late | Register the webhook and set up the cron job. |
| Webhook returns 401 | Press **Register webhook** again to refresh the signing secret, and check the server clock. |
| An order line shows "not in your catalogue" | The Fetch item's reference matches no product or combination reference. Its stock was not reduced. |
| Changes wait in the queue | Fetch was unreachable or rate-limited the shop; the next cron run retries. |

## Data and privacy

- The module talks only to the Fetch API server you configure.
- Your client secret and webhook signing secret are stored in PrestaShop's configuration table.
- Customer details on imported orders come from Fetch and are stored like any other PrestaShop order.
- Uninstalling the module removes its settings and its tables (sync state, queue, order links, activity log) and retires the **Fetch delivery** carrier.
  Orders, customers, products, the order states and the placeholder product are kept.
  After a reinstall, existing Fetch orders are recognised again by their payment transaction ID, so they are not imported twice.

## Development

```bash
# Unit tests (no PrestaShop needed)
php tests/unit.php

# Build the module ZIP (fetchsync/ at the zip root)
./build.sh            # → dist/fetchsync-1.0.0.zip

# End-to-end: PrestaShop 8.1 + MariaDB in Docker, the release ZIP, and a mock Fetch API
node tests/e2e/run.mjs
```

See [tests/README.md](tests/README.md) for details.

## Support

Contact Fetch merchant support.

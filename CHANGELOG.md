# Changelog

## 1.3.1 (beta)

- **Exclude backorder items.** A new setting, on by default and set per shop.
  A product or combination that PrestaShop would sell when it is out of stock (stock management on, quantity 0 or less, out-of-stock orders allowed by the product or by the shop) is sent to Fetch with stock 0 and is not created as a new product on Fetch.
  One that is already on Fetch keeps being updated, so it drops to 0 and comes back when stock returns.
  It applies to full and single-product syncs, stock changes from orders, combinations and every shop of a multistore.
  In mirror mode, a backorder item that was never sent is switched off if it is on Fetch.
  Changing the setting resends all products.
- The upgrade from 1.3.0 turns the setting on and leaves every other setting as it was.

## 1.3.0 (beta)

- **Multistore.** Every shop of a multistore installation can be connected to its own Fetch store, independently.
  Credentials, server, Fetch store, sync, price markup, catalogue mode, order import, auto-accept, preparation time and webhook secret are set per shop, as PrestaShop per-shop configuration, through the back office's shop selector.
- In **All shops** or a shop group, the configuration page shows an overview of every shop: its Fetch store, last sync and status.
- Shops can share a Partner key that covers several Fetch stores; each shop picks its own store, and two shops cannot pick the same one.
- A product change is queued for every connected shop that sells the product, and each Fetch store gets that shop's price, sale and specific prices, stock (shared or not within the shop group), active state, language, currency and markup.
  Full sync, mirror mode and the queue run per shop.
- A Fetch order goes into the shop connected to its Fetch store, with that shop's currency, language and customers.
  The Fetch carrier and placeholder product are associated with every shop, including shops created later.
- Webhooks are routed by the delivery's Fetch store and verified with that shop's secret, on any shop's domain.
- One cron URL processes every connected shop; a shop whose key fails does not stop the others.
- Without multistore nothing changes.
  The upgrade from 1.2.0 keeps every setting and adds the shop to the module's sync, order and log tables; nothing needs reconfiguring.

## 1.2.0 (beta)

- **Specs.** Product features are sent to Fetch as specs (name and value), in the order the shop shows them.
  A feature with several values is sent once, with its values joined by commas.
- **Summary.** The short description is sent as well as the description.
- **SEO.** The product's meta title and meta description are sent, so Fetch can use the shop's own SEO text.
- Descriptions keep their paragraphs and line breaks instead of being flattened to one line.
- Every combination carries its product's specs, summary and SEO, like the description and brand.
- Text and SEO come from the shop's default language, like the name.
  All the new fields are optional, so older Fetch servers keep accepting the module's updates.

## 1.1.0 (beta)

- **Mirror this shop 1:1.** A new **Catalogue mode** setting.
  **Add and update only** stays the default and works as before.
  **Mirror this shop 1:1** switches off, at the end of every full sync, the products in your Fetch store that this shop does not sell: products without a reference, references this shop no longer has, packs and virtual products, and products that are disabled or deleted here.
  Nothing is deleted, and a product that comes back is switched on again by the normal sync.
  Names, photos and descriptions stay as Fetch's shared catalogue has them.
- **Preview** shows how many Fetch products a mirror run would switch off, with up to 20 names, without changing anything.
- A mirror run that would switch off more than half of the store's active products waits for **Apply anyway**.
  A run is skipped when the full sync could not reach Fetch or a page of the store's products could not be read.
- Every mirror run is logged under **Recent activity**: scanned, kept, switched off and errors.
- Every product push now carries a fresh Idempotency-Key instead of a hash of its content, so the Fetch sandbox can no longer replay an old answer when the same product data is sent again.

## 1.0.0 (beta)

- First release: product, price and stock sync, Fetch orders as PrestaShop orders, accept / ready / reject, webhooks with a 2-minute safety net, auto-accept and the price markup.

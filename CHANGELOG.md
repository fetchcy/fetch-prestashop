# Changelog

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

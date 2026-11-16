<?php
/**
 * PrestaShop product → Fetch `PUT /stores/{storeId}/products` items.
 *
 * Fetch prices are what the customer pays, VAT included, as decimal strings.
 * A product's ref is its reference (or "ps-<id>" without one), frozen in the
 * fetchsync_product table on first sync so a later reference edit updates the
 * same Fetch product instead of creating a second one.
 *
 * Live Fetch stores accept SIMPLE products only through PUT /products, so a
 * product with combinations is sent as one simple product per combination:
 * its own ref (the combination reference), "Product – Black / M" as the name,
 * its own price, stock, barcode and images, and the product's description,
 * brand, category and tags.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class FetchSyncProductMapper
{
    const TABLE = 'fetchsync_product';

    /** @var array<int,array<int,string>> id_product → id_product_attribute → frozen ref */
    private static $refs = array();

    /** Product types we can express on Fetch: standard products and products with combinations. */
    public static function supports($product)
    {
        return $product instanceof Product
            && Validate::isLoadedObject($product)
            && (int) $product->id !== (int) FetchSyncSettings::internal('PLACEHOLDER_ID', 0)
            && Product::PTYPE_SIMPLE === (int) $product->getType();
    }

    public static function loadProduct($idProduct)
    {
        return new Product((int) $idProduct, false, FetchSyncSettings::defaultLangId(), FetchSyncSettings::defaultShopId());
    }

    /**
     * Frozen ref first, then the reference, then ps-<id>[-<id_product_attribute>].
     * A reference several combinations share (PrestaShop copies the product's
     * reference into new combinations) cannot identify one, so it is skipped.
     */
    public static function ref($idProduct, $idProductAttribute, $reference, $shared = false)
    {
        $frozen = self::frozenRefs($idProduct);
        if (isset($frozen[(int) $idProductAttribute]) && '' !== $frozen[(int) $idProductAttribute]) {
            return $frozen[(int) $idProductAttribute];
        }
        $reference = trim((string) $reference);
        if ('' !== $reference && !$shared) {
            return $reference;
        }

        return 'ps-' . (int) $idProduct . ($idProductAttribute ? '-' . (int) $idProductAttribute : '');
    }

    public static function forgetRefCache()
    {
        self::$refs = array();
    }

    /**
     * Fetch items for one product, each paired with the [id_product,
     * id_product_attribute] its result is recorded on.
     *
     * @return array<int,array{0:array<string,mixed>,1:array{0:int,1:int}}>
     */
    public static function toFetchItems(Product $product, $forceInactive = false)
    {
        if (!self::supports($product)) {
            return array();
        }
        $idLang = FetchSyncSettings::defaultLangId();
        $id = (int) $product->id;
        $base = self::sharedFields($product, $idLang);
        $active = !$forceInactive && self::isActive($product);
        $name = self::plainName($product->name);

        $combinations = self::combinations($product, $idLang);
        if (!$combinations) {
            $item = array_merge(
                array(
                    'ref' => self::ref($id, 0, $product->reference),
                    'name' => $name,
                    'productType' => 'SIMPLE',
                    'isActive' => $active,
                ),
                $base,
                self::priceAndStock($id, 0)
            );
            $barcode = self::barcode($product);
            if ('' !== $barcode) {
                $item['barcode'] = $barcode;
            }
            $images = self::images($product, 0, $idLang);
            if ($images) {
                $item['images'] = $images;
            }

            return array(array($item, array($id, 0)));
        }

        $counts = array_count_values(array_map(function ($combination) {
            return trim($combination['reference']);
        }, $combinations));
        $out = array();
        foreach ($combinations as $ipa => $combination) {
            $label = implode(' / ', array_filter($combination['values'], 'strlen'));
            $shared = $counts[trim($combination['reference'])] > 1;
            $item = array_merge(
                array(
                    'ref' => self::ref($id, $ipa, $combination['reference'], $shared),
                    'name' => $name . ('' !== $label ? ' – ' . $label : ''),
                    'productType' => 'SIMPLE',
                    'isActive' => $active,
                ),
                $base,
                self::priceAndStock($id, $ipa)
            );
            $barcode = self::firstCode($combination);
            if ('' !== $barcode) {
                $item['barcode'] = $barcode;
            }
            $images = self::images($product, $ipa, $idLang);
            if ($images) {
                $item['images'] = $images;
            }
            $out[] = array($item, array($id, $ipa));
        }

        return $out;
    }

    /** Full items with isActive=false (live Fetch requires a price on every row). */
    public static function deactivations(Product $product)
    {
        $out = array();
        foreach (self::toFetchItems($product, true) as $pair) {
            $out[] = $pair[0];
        }

        return $out;
    }

    /** Minimal deactivation for a row we can no longer build (combination already gone). */
    public static function deactivation($ref, $name)
    {
        return array(
            'ref' => $ref,
            'name' => $name ? $name : $ref,
            'isActive' => false,
        );
    }

    /** True once Fetch has accepted this product, or any of its combinations. */
    public static function wasSynced($idProduct, $idProductAttribute = null)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `id_product` = ' . (int) $idProduct
            . (null !== $idProductAttribute ? ' AND `id_product_attribute` = ' . (int) $idProductAttribute : '') . '
            AND `fetch_product_id` <> \'\''
        );
    }

    /** Price as a 2-decimal string. */
    public static function money($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Add the store's markup to an already-rounded price, in integer cents and
     * rounded half-up, so 9.99 at 15% (11.4885) is 11.49 rather than whatever
     * the float lands on. Identical to the WooCommerce plugin.
     */
    public static function applyMarkup($price, $percent)
    {
        $cents = (int) round((float) $price * 100);
        $bp = (int) round((float) $percent * 100);
        if ($bp <= 0) {
            return self::money($cents / 100);
        }
        $marked = intdiv($cents * (10000 + $bp) + 5000, 10000);

        return self::money($marked / 100);
    }

    public static function plainText($html)
    {
        $text = str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8'));
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, 5000) : substr($text, 0, 5000);
    }

    private static function plainName($name)
    {
        return trim(html_entity_decode(strip_tags((string) $name), ENT_QUOTES, 'UTF-8'));
    }

    /** @return array<int,string> */
    private static function frozenRefs($idProduct)
    {
        $idProduct = (int) $idProduct;
        if (!isset(self::$refs[$idProduct])) {
            self::$refs[$idProduct] = array();
            $rows = Db::getInstance()->executeS(
                'SELECT `id_product_attribute`, `ref` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_product` = ' . $idProduct
            );
            foreach (is_array($rows) ? $rows : array() as $row) {
                self::$refs[$idProduct][(int) $row['id_product_attribute']] = (string) $row['ref'];
            }
        }

        return self::$refs[$idProduct];
    }

    /** @return array<int,array{reference:string,ean13:string,upc:string,isbn:string,values:string[]}> */
    private static function combinations(Product $product, $idLang)
    {
        $rows = $product->getAttributeCombinations($idLang);
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $ipa = (int) $row['id_product_attribute'];
            if (!isset($out[$ipa])) {
                $out[$ipa] = array(
                    'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
                    'ean13' => isset($row['ean13']) ? (string) $row['ean13'] : '',
                    'upc' => isset($row['upc']) ? (string) $row['upc'] : '',
                    'isbn' => isset($row['isbn']) ? (string) $row['isbn'] : '',
                    'values' => array(),
                );
            }
            $out[$ipa]['values'][] = (string) $row['attribute_name'];
        }
        ksort($out);

        return $out;
    }

    /** Active, visible somewhere, orderable and in the default shop. */
    private static function isActive(Product $product)
    {
        return (bool) $product->active
            && 'none' !== $product->visibility
            && (bool) $product->available_for_order
            && $product->isAssociatedToShop(FetchSyncSettings::defaultShopId());
    }

    /**
     * price / salePrice / stockCount for a product or a combination. Prices are
     * what an anonymous visitor in the shop's default country pays, VAT
     * included, plus the configured markup.
     */
    private static function priceAndStock($idProduct, $idProductAttribute)
    {
        $out = array();
        $regular = self::price($idProduct, $idProductAttribute, false);
        $final = self::price($idProduct, $idProductAttribute, true);
        $markup = FetchSyncSettings::markup();

        $out['price'] = self::applyMarkup(self::money($regular), $markup);
        if (self::money($final) !== self::money($regular) && $final < $regular) {
            $out['salePrice'] = self::applyMarkup(self::money($final), $markup);
        }

        $stock = self::stock($idProduct, $idProductAttribute);
        if (null !== $stock) {
            $out['stockCount'] = $stock;
        }

        return $out;
    }

    private static function price($idProduct, $idProductAttribute, $withReductions)
    {
        $specificPrice = null;
        $country = (int) Configuration::get('PS_COUNTRY_DEFAULT');

        return (float) Product::priceCalculation(
            FetchSyncSettings::defaultShopId(),
            (int) $idProduct,
            (int) $idProductAttribute,
            $country,
            0,
            '',
            (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            (int) Configuration::get('PS_UNIDENTIFIED_GROUP'),
            1,
            true,
            6,
            false,
            (bool) $withReductions,
            true,
            $specificPrice,
            true
        );
    }

    /**
     * With stock management on: the available quantity (never negative).
     * With it off: omitted, so Fetch sells the product without a stock limit.
     */
    private static function stock($idProduct, $idProductAttribute)
    {
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return null;
        }

        return max(0, (int) StockAvailable::getQuantityAvailableByProduct((int) $idProduct, (int) $idProductAttribute, FetchSyncSettings::defaultShopId()));
    }

    /** Description, brand, category and tags, shared by a product's combinations. */
    private static function sharedFields(Product $product, $idLang)
    {
        $fields = array();
        $description = self::plainText($product->description ? $product->description : $product->description_short);
        if ('' !== $description) {
            $fields['description'] = $description;
        }
        if ($product->id_manufacturer) {
            $brand = Manufacturer::getNameById((int) $product->id_manufacturer);
            if ($brand) {
                $fields['brand'] = (string) $brand;
            }
        }
        $categoryPath = self::categoryPath($product, $idLang);
        if ('' !== $categoryPath) {
            $fields['categoryPath'] = $categoryPath;
        }
        $tags = Tag::getProductTags((int) $product->id);
        if (is_array($tags) && !empty($tags[$idLang])) {
            $fields['tags'] = array_values($tags[$idLang]);
        }

        return $fields;
    }

    /**
     * The product's default category as "Parent > Child" (Root and Home left
     * out); the deepest of its categories when the default is Home.
     */
    private static function categoryPath(Product $product, $idLang)
    {
        $top = array((int) Configuration::get('PS_ROOT_CATEGORY'), (int) Configuration::get('PS_HOME_CATEGORY'), 0);
        $id = (int) $product->id_category_default;
        if (in_array($id, $top, true)) {
            $id = 0;
            $depth = -1;
            foreach (Product::getProductCategories((int) $product->id) as $candidate) {
                $category = new Category((int) $candidate);
                if (!in_array((int) $candidate, $top, true) && (int) $category->level_depth > $depth) {
                    $depth = (int) $category->level_depth;
                    $id = (int) $candidate;
                }
            }
        }
        if (!$id) {
            return '';
        }
        $category = new Category($id, $idLang);
        if (!Validate::isLoadedObject($category)) {
            return '';
        }
        $names = array();
        foreach (array_reverse($category->getParentsCategories($idLang)) as $row) {
            if (in_array((int) $row['id_category'], $top, true) || !empty($row['is_root_category'])) {
                continue;
            }
            $names[] = $row['name'];
        }

        return implode(' > ', $names);
    }

    /** EAN-13, then UPC, then ISBN. Combinations only use their own codes. */
    private static function barcode(Product $product)
    {
        return self::firstCode(array('ean13' => $product->ean13, 'upc' => $product->upc, 'isbn' => $product->isbn));
    }

    private static function firstCode(array $codes)
    {
        foreach (array('ean13', 'upc', 'isbn') as $key) {
            $value = isset($codes[$key]) ? trim((string) $codes[$key]) : '';
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    /** Cover first, then by position; a combination's own images come first. */
    private static function images(Product $product, $idProductAttribute, $idLang)
    {
        $link = Context::getContext()->link ? Context::getContext()->link : new Link();
        $rewrite = is_array($product->link_rewrite) ? reset($product->link_rewrite) : (string) $product->link_rewrite;
        $rows = array();
        if ($idProductAttribute) {
            foreach ((array) Image::getImages($idLang, (int) $product->id, (int) $idProductAttribute) as $row) {
                $rows[(int) $row['id_image']] = $row;
            }
        }
        $all = (array) Image::getImages($idLang, (int) $product->id);
        usort($all, function ($a, $b) {
            if ((int) $a['cover'] !== (int) $b['cover']) {
                return (int) $b['cover'] - (int) $a['cover'];
            }

            return (int) $a['position'] - (int) $b['position'];
        });
        foreach ($all as $row) {
            if (!isset($rows[(int) $row['id_image']])) {
                $rows[(int) $row['id_image']] = $row;
            }
        }

        $images = array();
        foreach ($rows as $idImage => $row) {
            $images[] = array(
                'url' => $link->getImageLink($rewrite ? $rewrite : 'product', (string) $idImage),
                'isPrimary' => 0 === count($images),
                'sortOrder' => count($images),
                'alt' => isset($row['legend']) ? (string) $row['legend'] : '',
            );
        }

        return $images;
    }
}

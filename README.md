# Primer Woo Package

Version 1.2 adds SEO data to the single-product route.

WooCommerce REST API endpoints for attributes, terms, swatches, product attributes and variable-product combinations.

**Author:** Alireza Bazargani

## Global settings

The plugin adds a **Primer WooCommerce API** admin menu group with the `assets/icon.svg` icon. It contains **Settings** and **API endpoints** submenu pages. The settings panel is generated from a JSON field definition in `primer-wc-settings.php` and supports media pickers, text, text areas, selects, checkboxes and color pickers.

### Global configuration

`GET /wp-json/primer-better-woocommerce/v1/global-config`

Returns the field definitions and the saved/default configuration:

```json
{
  "success": true,
  "fields": [],
  "config": {
    "app_logo": null,
    "app_name": null,
    "app_description": null,
    "default_theme": "system",
    "show_prices": true,
    "primary_color": "#2271b1"
  }
}
```

Media values are returned as an object containing the attachment `id` and its full-size `url`.

## Routes

### All global attributes + all terms + swatches

`GET /wp-json/primer-better-wocommerce/v1/attributes`

Designed for archive/category filters. Returns all global WooCommerce attributes and all their terms, including color/image swatch data when it is stored as supported term metadata.

### Product attributes + selected values + combinations

`GET /wp-json/primer-better-wocommerce/v1/products/{product_id}/attributes`

Returns the product's attributes and selected values. For variable products it also returns every child variation and its exact attribute combination.

### Product + attributes + combinations

`GET /wp-json/primer-better-wocommerce/v1/products/{product_id}`

Returns compact product data together with attributes, variable-product combinations and a `seo` object.

When Yoast SEO is active, `seo` contains the resolved title, description, canonical URL, robots directives, breadcrumbs, Open Graph/Twitter values, a standard-compatible `yoast_head_json` object and Yoast's generated schema graph. When WooCommerce Structured Data is available, its product schema is included as `woocommerce_schema` and merged into `schema`; this includes Yoast WooCommerce SEO enrichments when that extension is active.

The `seo.available` and `seo.woocommerce_seo.available` flags make it safe for clients to consume the response on sites where either SEO plugin is not installed.

## Example combination

```json
{
  "variation_id": 1234,
  "attributes": [
    {
      "taxonomy": "pa_color",
      "name": "رنگ",
      "value": "قرمز",
      "slug": "red",
      "swatch": {
        "type": "color",
        "color": "#FF0000"
      }
    },
    {
      "taxonomy": "pa_size",
      "name": "سایز",
      "value": "42",
      "slug": "42"
    }
  ]
}
```

WooCommerce core does not enforce one universal swatch term-meta schema, so the plugin detects several common color/image keys. The arrays are easy to extend for a specific swatch plugin used by the store.

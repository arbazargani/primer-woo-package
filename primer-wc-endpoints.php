<?php
/**
 * Plugin Name: Primer Woo Package
 * Description: Optimized REST API endpoints for WooCommerce attributes, terms, swatches and variable-product combinations.
 * Version: 2.1
 * Author: Alireza Bazargani
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, woo-variation-swatches
 * Text Domain: primer-better-woocommerce-endpoints
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/primer-wc-settings.php';
require_once __DIR__ . '/card-to-card-payment.php';

final class Primer_Better_WooCommerce_Endpoints
{
    private const NAMESPACE = 'primer-better-woocommerce/v1';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/global-config', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_global_config'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/attributes', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_attributes'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)/attributes', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_product_attributes'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_product'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function get_global_config(): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'fields' => Primer_Better_WooCommerce_Endpoints_Settings::get_public_fields(),
            'config' => Primer_Better_WooCommerce_Endpoints_Settings::get_public_config(),
        ]);
    }

    public static function get_attributes(): WP_REST_Response
    {
        if (!function_exists('wc_get_attribute_taxonomies')) {
            return new WP_REST_Response(['success' => false, 'message' => 'WooCommerce is not active.'], 503);
        }

        $attributes = [];

        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'term_id',
                'order' => 'ASC',
            ]);

            if (is_wp_error($terms)) $terms = [];

            $attributes[] = [
                'id' => (int) $attribute->attribute_id,
                'name' => $attribute->attribute_label ?: $attribute->attribute_name,
                'slug' => $attribute->attribute_name,
                'taxonomy' => $taxonomy,
                'type' => $attribute->attribute_type,
                'order_by' => $attribute->attribute_orderby,
                'has_archives' => (bool) $attribute->attribute_public,
                'terms_count' => count($terms),
                'terms' => array_map([self::class, 'format_term'], $terms),
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'count' => count($attributes),
            'attributes' => $attributes,
        ]);
    }

    public static function get_product_attributes(WP_REST_Request $request): WP_REST_Response
    {
        $product = self::get_product_or_error((int) $request['id']);
        if ($product instanceof WP_REST_Response) return $product;

        $combinations = self::format_variations($product);

        return new WP_REST_Response([
            'success' => true,
            'product_id' => $product->get_id(),
            'type' => $product->get_type(),
            'attributes' => self::format_product_attributes($product),
            'combinations' => $combinations,
            'combinations_count' => count($combinations),
        ]);
    }

    public static function get_product(WP_REST_Request $request): WP_REST_Response
    {
        $product = self::get_product_or_error((int) $request['id']);
        if ($product instanceof WP_REST_Response) return $product;

        return new WP_REST_Response([
            'success' => true,
            'product' => [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'slug' => $product->get_slug(),
                'type' => $product->get_type(),
                'status' => $product->get_status(),
                'sku' => $product->get_sku(),
                'attributes' => self::format_product_attributes($product),
                'combinations' => self::format_variations($product),
                'seo' => self::format_product_seo($product),
            ],
        ]);
    }

    private static function format_product_seo(WC_Product $product): array
    {
        $meta = null;

        if (function_exists('YoastSEO')) {
            try {
                $yoast = \YoastSEO();
                $meta_surface = is_object($yoast) ? ($yoast->meta ?? null) : null;

                if (is_object($meta_surface) && method_exists($meta_surface, 'for_post')) {
                    $meta = $meta_surface->for_post($product->get_id());
                }
            } catch (\Throwable $exception) {
                // SEO integrations must never make the product endpoint fail.
            }
        }

        $yoast_head_json = self::get_yoast_head_json($meta);
        $yoast_schema = self::get_yoast_value($meta, 'schema', []);
        $woocommerce_schema = self::get_woocommerce_product_schema($product);

        return [
            'available' => is_object($meta),
            'version' => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : null,
            'woocommerce_seo' => [
                'available' => defined('WPSEO_WOO_VERSION')
                    || class_exists('WPSEO_WooCommerce')
                    || class_exists('WPSEO_WooCommerce_Schema'),
                'version' => defined('WPSEO_WOO_VERSION') ? (string) WPSEO_WOO_VERSION : null,
            ],
            'title' => self::get_yoast_value($meta, 'title'),
            'description' => self::get_yoast_value(
                $meta,
                'description',
                self::get_yoast_value($meta, 'meta_description')
            ),
            'canonical' => self::get_yoast_value($meta, 'canonical'),
            'robots' => self::get_yoast_value($meta, 'robots', []),
            'page_type' => self::get_yoast_value($meta, 'page_type'),
            'main_schema_id' => self::get_yoast_value($meta, 'main_schema_id'),
            'breadcrumbs' => self::get_yoast_value($meta, 'breadcrumbs', []),
            'yoast_head_json' => $yoast_head_json,
            'open_graph' => [
                'enabled' => (bool) self::get_yoast_value($meta, 'open_graph_enabled', false),
                'title' => self::get_yoast_value($meta, 'open_graph_title'),
                'description' => self::get_yoast_value($meta, 'open_graph_description'),
                'url' => self::get_yoast_value($meta, 'open_graph_url'),
                'type' => self::get_yoast_value($meta, 'open_graph_type'),
                'locale' => self::get_yoast_value($meta, 'open_graph_locale'),
                'site_name' => self::get_yoast_value($meta, 'open_graph_site_name'),
                'images' => self::get_yoast_value($meta, 'open_graph_images', []),
            ],
            'twitter' => [
                'card' => self::get_yoast_value($meta, 'twitter_card'),
                'title' => self::get_yoast_value($meta, 'twitter_title'),
                'description' => self::get_yoast_value($meta, 'twitter_description'),
                'image' => self::get_yoast_value($meta, 'twitter_image'),
                'site' => self::get_yoast_value($meta, 'twitter_site'),
                'creator' => self::get_yoast_value($meta, 'twitter_creator'),
            ],
            'yoast_schema' => self::normalize_schema(self::as_array($yoast_schema)),
            'woocommerce_schema' => self::as_array($woocommerce_schema),
            'schema' => self::merge_product_schema(
                self::as_array($yoast_schema),
                self::as_array($woocommerce_schema)
            ),
        ];
    }

    private static function get_yoast_head_json($meta): array
    {
        if (!is_object($meta) || !method_exists($meta, 'get_head')) return [];

        try {
            $head = $meta->get_head();
            if (is_object($head) && isset($head->json) && is_array($head->json)) {
                return $head->json;
            }
        } catch (\Throwable $exception) {
            // Keep the structured SEO fields available if head presentation fails.
        }

        return [];
    }

    private static function get_yoast_value($meta, string $property, $default = null)
    {
        if (!is_object($meta)) return $default;

        try {
            if (!isset($meta->{$property})) return $default;
            $value = $meta->{$property};
        } catch (\Throwable $exception) {
            return $default;
        }

        return $value ?? $default;
    }

    private static function get_woocommerce_product_schema(WC_Product $product): array
    {
        if (!class_exists('WC_Structured_Data') || !method_exists('WC_Structured_Data', 'generate_product_data')) {
            return [];
        }

        $structured_data_schema = null;
        $yoast_schema = null;

        $structured_data_filter = static function ($schema) use (&$structured_data_schema) {
            if (is_array($schema)) $structured_data_schema = $schema;
            return $schema;
        };
        $yoast_schema_filter = static function ($schema) use (&$yoast_schema) {
            if (is_array($schema)) $yoast_schema = $schema;
            return $schema;
        };

        add_filter('woocommerce_structured_data_product', $structured_data_filter, PHP_INT_MAX);
        add_filter('wpseo_schema_product', $yoast_schema_filter, PHP_INT_MAX);

        try {
            $generator = new WC_Structured_Data();
            $generator->generate_product_data($product);
        } catch (\Throwable $exception) {
            // A third-party schema integration may not support every product type.
        }

        remove_filter('woocommerce_structured_data_product', $structured_data_filter, PHP_INT_MAX);
        remove_filter('wpseo_schema_product', $yoast_schema_filter, PHP_INT_MAX);

        if (is_array($structured_data_schema)) return $structured_data_schema;
        if (is_array($yoast_schema)) return $yoast_schema;

        return [];
    }

    private static function merge_product_schema(array $yoast_schema, array $woocommerce_schema): array
    {
        $schema = self::normalize_schema($yoast_schema);
        $woocommerce_schema = self::normalize_schema($woocommerce_schema);

        if (!$schema) return $woocommerce_schema;
        if (!$woocommerce_schema) return $schema;

        foreach ($woocommerce_schema['@graph'] as $woocommerce_piece) {
            if (!is_array($woocommerce_piece)) continue;

            $merged = false;
            if (self::schema_piece_has_type($woocommerce_piece, 'Product')) {
                foreach ($schema['@graph'] as $index => $schema_piece) {
                    if (!is_array($schema_piece) || !self::schema_piece_has_type($schema_piece, 'Product')) {
                        continue;
                    }

                    $schema['@graph'][$index] = array_merge($schema_piece, $woocommerce_piece);
                    $merged = true;
                    break;
                }
            }

            if (!$merged) $schema['@graph'][] = $woocommerce_piece;
        }

        return $schema;
    }

    private static function normalize_schema(array $schema): array
    {
        if (!$schema) return [];

        if (isset($schema['@graph']) && is_array($schema['@graph'])) {
            return [
                '@context' => $schema['@context'] ?? 'https://schema.org',
                '@graph' => array_values($schema['@graph']),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$schema],
        ];
    }

    private static function schema_piece_has_type(array $piece, string $type): bool
    {
        $types = $piece['@type'] ?? [];
        if (!is_array($types)) $types = [$types];

        return in_array($type, $types, true);
    }

    private static function as_array($value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function get_product_or_error(int $id)
    {
        if (!function_exists('wc_get_product')) {
            return new WP_REST_Response(['success' => false, 'message' => 'WooCommerce is not active.'], 503);
        }

        $product = wc_get_product($id);
        if (!$product) {
            return new WP_REST_Response(['success' => false, 'message' => 'Product not found.'], 404);
        }

        return $product;
    }

    private static function format_product_attributes(WC_Product $product): array
    {
        $result = [];

        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute) continue;

            $name = $attribute->get_name();
            $global = $attribute->is_taxonomy();
            $item = [
                'id' => (int) $attribute->get_id(),
                'name' => wc_attribute_label($name),
                'slug' => $global ? $name : sanitize_title($name),
                'taxonomy' => $global ? $name : null,
                'type' => $global ? 'global' : 'custom',
                'visible' => (bool) $attribute->get_visible(),
                'variation' => (bool) $attribute->get_variation(),
                'values' => [],
            ];

            if ($global) {
                $term_ids = array_map('intval', $attribute->get_options());
                if ($term_ids) {
                    $terms = get_terms([
                        'taxonomy' => $name,
                        'include' => $term_ids,
                        'hide_empty' => false,
                    ]);

                    if (!is_wp_error($terms)) {
                        $by_id = [];
                        foreach ($terms as $term) $by_id[(int) $term->term_id] = $term;
                        foreach ($term_ids as $term_id) {
                            if (isset($by_id[$term_id])) $item['values'][] = self::format_term($by_id[$term_id]);
                        }
                    }
                }
            } else {
                foreach ($attribute->get_options() as $option) {
                    $value = wp_strip_all_tags((string) $option);
                    $item['values'][] = [
                        'id' => null,
                        'name' => $value,
                        'slug' => sanitize_title($value),
                        'swatch' => self::empty_swatch(),
                    ];
                }
            }

            $item['values_count'] = count($item['values']);
            $result[] = $item;
        }

        return $result;
    }

    private static function format_variations(WC_Product $product): array
    {
        if (!$product->is_type('variable')) return [];

        $result = [];

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product_Variation) continue;

            $attributes = [];
            foreach ($variation->get_attributes() as $taxonomy => $value) {
                $item = [
                    'taxonomy' => $taxonomy,
                    'name' => wc_attribute_label($taxonomy),
                    'value' => (string) $value,
                    'slug' => sanitize_title((string) $value),
                    'term' => null,
                    'swatch' => self::empty_swatch(),
                ];

                if (taxonomy_exists($taxonomy)) {
                    $term = get_term_by('slug', $value, $taxonomy);
                    if (!$term) $term = get_term_by('name', $value, $taxonomy);
                    if ($term && !is_wp_error($term)) {
                        $item['value'] = $term->name;
                        $item['slug'] = $term->slug;
                        $item['term'] = self::format_term($term);
                        $item['swatch'] = self::get_term_swatch($term);
                    }
                }

                $attributes[] = $item;
            }

            $result[] = [
                'variation_id' => $variation->get_id(),
                'sku' => $variation->get_sku(),
                'is_active' => $variation->get_status() === 'publish',
                'in_stock' => $variation->is_in_stock(),
                'stock_status' => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'price' => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
                'attributes' => $attributes,
            ];
        }

        return $result;
    }

    private static function format_term(WP_Term $term): array
    {
        return [
            'id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'count' => (int) $term->count,
            'swatch' => self::get_term_swatch($term),
        ];
    }

    private static function get_term_swatch(WP_Term $term): array
    {
        $meta = get_term_meta($term->term_id);

        $color_keys = [
            'color', 'colour', 'hex', 'hex_color', 'color_hex', 'swatch_color',
            'product_attribute_color', 'wvs_color', 'woo_variation_swatches_color',
            'viwvs_color', 'variation_swatch_color',
        ];

        $image_keys = [
            'image', 'image_id', 'swatch_image', 'product_attribute_image',
            'wvs_image', 'woo_variation_swatches_image', 'viwvs_image',
            'variation_swatch_image',
        ];

        $color = null;
        $color_key = null;
        foreach ($color_keys as $key) {
            if (!empty($meta[$key][0]) && is_scalar($meta[$key][0])) {
                $candidate = trim((string) $meta[$key][0]);
                if ($candidate !== '') {
                    if (preg_match('/^#?[0-9a-fA-F]{3,8}$/', $candidate)) {
                        $candidate = '#' . ltrim($candidate, '#');
                    }
                    $color = $candidate;
                    $color_key = $key;
                    break;
                }
            }
        }

        $image = null;
        $image_key = null;
        foreach ($image_keys as $key) {
            if (!isset($meta[$key][0]) || $meta[$key][0] === '') continue;
            $raw = $meta[$key][0];
            if (is_numeric($raw)) {
                $image_id = (int) $raw;
                $url = wp_get_attachment_image_url($image_id, 'full');
                if ($url) {
                    $image = ['id' => $image_id, 'url' => $url];
                    $image_key = $key;
                    break;
                }
            } elseif (is_string($raw) && filter_var($raw, FILTER_VALIDATE_URL)) {
                $image = ['id' => null, 'url' => esc_url_raw($raw)];
                $image_key = $key;
                break;
            }
        }

        return [
            'type' => $image ? 'image' : ($color ? 'color' : 'none'),
            'color' => $color,
            'image' => $image,
            'source' => [
                'color_meta_key' => $color_key,
                'image_meta_key' => $image_key,
            ],
        ];
    }

    private static function empty_swatch(): array
    {
        return [
            'type' => 'none',
            'color' => null,
            'image' => null,
            'source' => [
                'color_meta_key' => null,
                'image_meta_key' => null,
            ],
        ];
    }
}

Primer_Better_WooCommerce_Endpoints_Settings::boot();
Primer_Better_WooCommerce_Endpoints::boot();

<?php
/**
 * Product Usage taxonomy for WooCommerce products.
 *
 * This file contains the complete feature and has no dependencies on the
 * plugin's other features.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PWC_Product_Usage_Taxonomy')) {
    final class PWC_Product_Usage_Taxonomy
    {
        private const TAXONOMY = 'product_usage';
        private const POST_TYPE = 'product';
        private const MENU_SLUG = 'pwc-product-usage';
        private const META_KEY = 'thumbnail_id';

        public static function boot(): void
        {
            add_action('init', [self::class, 'register_taxonomy'], 5);
            add_action('init', [self::class, 'register_term_meta'], 6);
            add_action('admin_menu', [self::class, 'register_admin_menu'], 30);
            add_action('product_usage_add_form_fields', [self::class, 'render_add_thumbnail_field']);
            add_action('product_usage_edit_form_fields', [self::class, 'render_edit_thumbnail_field']);
            add_action('created_product_usage', [self::class, 'save_thumbnail']);
            add_action('edited_product_usage', [self::class, 'save_thumbnail']);
            add_filter('manage_edit-product_usage_columns', [self::class, 'add_thumbnail_column']);
            add_filter('manage_product_usage_custom_column', [self::class, 'render_thumbnail_column'], 10, 3);
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
            add_filter('woocommerce_rest_product_collection_params', [self::class, 'add_rest_collection_param']);
            add_filter('woocommerce_rest_product_object_query', [self::class, 'filter_rest_product_query'], 10, 2);
        }

        public static function add_rest_collection_param(array $query_params): array
        {
            $query_params['product_usage'] = [
                'description'       => __('فیلتر محصولات بر اساس استفاده محصول. می‌توانید slug یا شناسه term را وارد کنید و چند مقدار را با کاما جدا کنید.', 'primer-better-woocommerce-endpoints'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static function ($value): bool {
                    return is_scalar($value) && trim((string) $value) !== '';
                },
            ];

            return $query_params;
        }

        public static function filter_rest_product_query(array $args, WP_REST_Request $request): array
        {
            $raw_usage = $request->get_param('product_usage');
            if (!is_scalar($raw_usage) || trim((string) $raw_usage) === '') {
                return $args;
            }

            $terms = array_values(array_filter(array_map(
                static function ($term): string {
                    return sanitize_title(trim((string) $term));
                },
                explode(',', (string) $raw_usage)
            )));

            if (!$terms) {
                return $args;
            }

            $term_ids = [];
            $term_slugs = [];
            foreach ($terms as $term) {
                if (ctype_digit($term)) {
                    $term_ids[] = absint($term);
                } else {
                    $term_slugs[] = $term;
                }
            }

            $usage_query = [
                'relation' => 'OR',
            ];

            if ($term_ids) {
                $usage_query[] = [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_ids,
                ];
            }

            if ($term_slugs) {
                $usage_query[] = [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $term_slugs,
                ];
            }

            if (count($usage_query) > 1) {
                if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
                    $args['tax_query'] = [];
                }

                $args['tax_query'][] = $usage_query;
            }

            return $args;
        }

        public static function register_taxonomy(): void
        {
            $labels = [
                'name'                       => _x('استفاده‌های محصول', 'taxonomy general name', 'primer-better-woocommerce-endpoints'),
                'singular_name'              => _x('استفاده محصول', 'taxonomy singular name', 'primer-better-woocommerce-endpoints'),
                'search_items'               => __('جستجوی استفاده‌های محصول', 'primer-better-woocommerce-endpoints'),
                'all_items'                  => __('همه استفاده‌های محصول', 'primer-better-woocommerce-endpoints'),
                'parent_item'                => __('استفاده محصول والد', 'primer-better-woocommerce-endpoints'),
                'parent_item_colon'          => __('استفاده محصول والد:', 'primer-better-woocommerce-endpoints'),
                'edit_item'                  => __('ویرایش استفاده محصول', 'primer-better-woocommerce-endpoints'),
                'view_item'                  => __('مشاهده استفاده محصول', 'primer-better-woocommerce-endpoints'),
                'update_item'                => __('به‌روزرسانی استفاده محصول', 'primer-better-woocommerce-endpoints'),
                'add_new_item'               => __('افزودن استفاده محصول جدید', 'primer-better-woocommerce-endpoints'),
                'new_item_name'              => __('نام استفاده محصول جدید', 'primer-better-woocommerce-endpoints'),
                'menu_name'                  => __('استفاده محصول', 'primer-better-woocommerce-endpoints'),
                'not_found'                  => __('هیچ استفاده‌ای برای محصول پیدا نشد.', 'primer-better-woocommerce-endpoints'),
                'back_to_items'              => __('← بازگشت به استفاده‌های محصول', 'primer-better-woocommerce-endpoints'),
            ];

            register_taxonomy(
                self::TAXONOMY,
                [self::POST_TYPE],
                [
                    'labels'             => $labels,
                    'public'             => true,
                    'hierarchical'       => true,
                    'show_ui'            => true,
                    'show_admin_column'  => true,
                    'show_in_nav_menus'  => false,
                    'show_in_rest'       => true,
                    'rest_base'          => 'product-usage',
                    'query_var'          => self::TAXONOMY,
                    'rewrite'            => ['slug' => 'product-usage'],
                    'capabilities'       => [
                        'manage_terms' => 'manage_product_terms',
                        'edit_terms'   => 'edit_product_terms',
                        'delete_terms' => 'delete_product_terms',
                        'assign_terms' => 'assign_product_terms',
                    ],
                ]
            );
        }

        public static function register_term_meta(): void
        {
            register_term_meta(
                self::TAXONOMY,
                self::META_KEY,
                [
                    'type'              => 'integer',
                    'single'            => true,
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                    'show_in_rest'      => [
                        'schema' => [
                            'type'    => 'integer',
                            'context' => ['view', 'edit'],
                        ],
                    ],
                    'auth_callback'     => static function ($allowed, $meta_key, $term_id) {
                        return current_user_can('edit_term', (int) $term_id, self::TAXONOMY);
                    },
                ]
            );
        }

        public static function render_add_thumbnail_field(): void
        {
            ?>
            <div class="form-field term-thumbnail-wrap">
                <label for="product-usage-thumbnail-id"><?php esc_html_e('تصویر شاخص', 'primer-better-woocommerce-endpoints'); ?></label>
                <input type="hidden" id="product-usage-thumbnail-id" name="product_usage_thumbnail_id" value="">
                <div id="product-usage-thumbnail-preview"></div>
                <button type="button" class="button product-usage-upload-thumbnail">
                    <?php esc_html_e('انتخاب تصویر', 'primer-better-woocommerce-endpoints'); ?>
                </button>
                <button type="button" class="button product-usage-remove-thumbnail" style="display:none;">
                    <?php esc_html_e('حذف تصویر', 'primer-better-woocommerce-endpoints'); ?>
                </button>
                <p class="description"><?php esc_html_e('یک تصویر برای این استفاده محصول انتخاب کنید.', 'primer-better-woocommerce-endpoints'); ?></p>
            </div>
            <?php
        }

        public static function render_edit_thumbnail_field(WP_Term $term): void
        {
            $thumbnail_id = (int) get_term_meta($term->term_id, self::META_KEY, true);
            ?>
            <tr class="form-field term-thumbnail-wrap">
                <th scope="row"><label for="product-usage-thumbnail-id"><?php esc_html_e('تصویر شاخص', 'primer-better-woocommerce-endpoints'); ?></label></th>
                <td>
                    <input type="hidden" id="product-usage-thumbnail-id" name="product_usage_thumbnail_id" value="<?php echo esc_attr($thumbnail_id); ?>">
                    <div id="product-usage-thumbnail-preview">
                        <?php
                        if ($thumbnail_id) {
                            echo wp_get_attachment_image($thumbnail_id, [100, 100]);
                        }
                        ?>
                    </div>
                    <button type="button" class="button product-usage-upload-thumbnail">
                        <?php esc_html_e('انتخاب تصویر', 'primer-better-woocommerce-endpoints'); ?>
                    </button>
                    <button type="button" class="button product-usage-remove-thumbnail" <?php echo $thumbnail_id ? '' : 'style="display:none;"'; ?>>
                        <?php esc_html_e('حذف تصویر', 'primer-better-woocommerce-endpoints'); ?>
                    </button>
                    <p class="description"><?php esc_html_e('یک تصویر برای این استفاده محصول انتخاب کنید.', 'primer-better-woocommerce-endpoints'); ?></p>
                </td>
            </tr>
            <?php
        }

        public static function save_thumbnail(int $term_id): void
        {
            if (!current_user_can('edit_term', $term_id, self::TAXONOMY)) {
                return;
            }

            $thumbnail_id = isset($_POST['product_usage_thumbnail_id'])
                ? absint(wp_unslash($_POST['product_usage_thumbnail_id']))
                : 0;

            if ($thumbnail_id) {
                update_term_meta($term_id, self::META_KEY, $thumbnail_id);
            } else {
                delete_term_meta($term_id, self::META_KEY);
            }
        }

        public static function add_thumbnail_column(array $columns): array
        {
            $updated_columns = [];

            foreach ($columns as $key => $label) {
                $updated_columns[$key] = $label;
                if ($key === 'cb') {
                    $updated_columns['product_usage_thumbnail'] = __('تصویر', 'primer-better-woocommerce-endpoints');
                }
            }

            return $updated_columns;
        }

        public static function render_thumbnail_column(string $content, string $column_name, int $term_id): string
        {
            if ($column_name !== 'product_usage_thumbnail') {
                return $content;
            }

            $thumbnail_id = (int) get_term_meta($term_id, self::META_KEY, true);
            return $thumbnail_id ? (string) wp_get_attachment_image($thumbnail_id, [50, 50]) : '—';
        }

        public static function enqueue_admin_assets(string $hook_suffix): void
        {
            if (!in_array($hook_suffix, ['edit-tags.php', 'term.php'], true)) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen || $screen->taxonomy !== self::TAXONOMY) {
                return;
            }

            wp_enqueue_media();
            wp_register_script('pwc-product-usage-admin', false, ['jquery'], '1.0.0', true);
            wp_enqueue_script('pwc-product-usage-admin');
            wp_add_inline_script('pwc-product-usage-admin', 'jQuery(function($){
                var frame;
                var $id = $("#product-usage-thumbnail-id");
                var $preview = $("#product-usage-thumbnail-preview");
                var $remove = $(".product-usage-remove-thumbnail");
                $(".product-usage-upload-thumbnail").on("click", function(event){
                    event.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: ' . wp_json_encode(__('انتخاب تصویر استفاده محصول', 'primer-better-woocommerce-endpoints')) . ',
                        button: { text: ' . wp_json_encode(__('استفاده از این تصویر', 'primer-better-woocommerce-endpoints')) . ' },
                        library: { type: "image" },
                        multiple: false
                    });
                    frame.on("select", function(){
                        var attachment = frame.state().get("selection").first().toJSON();
                        var image = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                        $id.val(attachment.id);
                        $preview.html("<img src=\"" + image.replace(/\"/g, "&quot;") + "\" width=\"100\" height=\"100\" style=\"object-fit:cover;\"> ");
                        $remove.show();
                    });
                    frame.open();
                });
                $remove.on("click", function(event){
                    event.preventDefault();
                    $id.val("");
                    $preview.empty();
                    $remove.hide();
                });
            });');
        }

        public static function register_admin_menu(): void
        {
            if (!post_type_exists(self::POST_TYPE) || !taxonomy_exists(self::TAXONOMY)) {
                return;
            }

            add_submenu_page(
                'woocommerce',
                __('استفاده محصول', 'primer-better-woocommerce-endpoints'),
                __('استفاده محصول', 'primer-better-woocommerce-endpoints'),
                'manage_product_terms',
                self::MENU_SLUG,
                [self::class, 'redirect_to_term_screen']
            );
        }

        public static function redirect_to_term_screen(): void
        {
            if (!current_user_can('manage_product_terms')) {
                wp_die(esc_html__('متأسفانه اجازه مدیریت استفاده‌های محصول را ندارید.', 'primer-better-woocommerce-endpoints'));
            }

            wp_safe_redirect(
                admin_url(
                    'edit-tags.php?taxonomy=' . rawurlencode(self::TAXONOMY) . '&post_type=' . rawurlencode(self::POST_TYPE)
                )
            );
            exit;
        }
    }

    PWC_Product_Usage_Taxonomy::boot();
}

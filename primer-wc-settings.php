<?php

if (!defined('ABSPATH')) exit;

final class Primer_Better_WooCommerce_Endpoints_Settings
{
    private const OPTION_NAME = 'primer_better_woocommerce_endpoints_global_config';
    private const MENU_SLUG = 'primer-wc-api';
    private const SETTINGS_PAGE_SLUG = 'primer-wc-settings';
    private const API_PAGE_SLUG = 'primer-wc-api-endpoints';
    private const SETTINGS_GROUP = 'primer_better_woocommerce_endpoints_settings';

    private const API_ROUTES = [
        [
            'label' => 'Global configuration',
            'method' => 'GET',
            'path' => 'global-config',
            'description' => 'Returns the JSON field definitions and saved global configuration.',
        ],
        [
            'label' => 'All attributes',
            'method' => 'GET',
            'path' => 'attributes',
            'description' => 'Returns all global attributes, terms and swatch data.',
        ],
        [
            'label' => 'Product attributes',
            'method' => 'GET',
            'path' => 'products/{product_id}/attributes',
            'description' => 'Returns selected product attributes and variation combinations.',
        ],
        [
            'label' => 'Single product',
            'method' => 'GET',
            'path' => 'products/{product_id}',
            'description' => 'Returns product data, attributes, combinations and SEO data.',
        ],
    ];

    private const FIELDS_JSON = <<<'JSON'
[
    {
        "label": "لوگو",
        "group": "store",
        "width": 4,
        "type": "media-picker",
        "values": [],
        "key": "app_logo",
        "default_value": null
    },
    {
        "label": "لوگو تیره",
        "group": "store",
        "width": 4,
        "type": "media-picker",
        "values": [],
        "key": "app_logo_dark",
        "default_value": null
    },
    {
        "label": "فاو آیکون",
        "group": "store",
        "width": 4,
        "type": "media-picker",
        "values": [],
        "key": "app_fav_icon",
        "default_value": null
    },
    {
        "label": "نام فروشگاه",
        "group": "store",
        "width": 5,
        "type": "text",
        "values": [],
        "key": "app_name",
        "default_value": null
    },
    {
        "label": "آدرس",
        "group": "store",
        "width": 7,
        "type": "text",
        "values": [],
        "key": "app_address",
        "default_value": null
    },
    {
        "label": "توضیحات فروشگاه",
        "group": "store",
        "width": 12,
        "type": "text-area",
        "values": [],
        "key": "app_description",
        "default_value": null
    },
    {
        "label": "اطلاعیه کلی فروشگاه",
        "group": "store",
        "width": 12,
        "type": "text-area",
        "values": [],
        "key": "global_notice",
        "default_value": null
    },
    {
        "label": "فعال سازی اطلاعیه کلی",
        "group": "store",
        "width": 6,
        "type": "checkbox",
        "values": [],
        "key": "show_global_notice",
        "default_value": false
    },
    {
        "label": "تم پیش‌فرض",
        "group": "appearance",
        "width": 4,
        "type": "select",
        "values": [
            {"label": "خودکار", "value": "system"},
            {"label": "روشن", "value": "light"},
            {"label": "تیره", "value": "dark"}
        ],
        "key": "default_theme",
        "default_value": "system"
    },
    {
        "label": "عدم نمایش قیمت‌ها",
        "group": "visibility",
        "width": 6,
        "type": "checkbox",
        "values": [],
        "key": "hide_prices",
        "default_value": false
    },
    {
        "label": "غیرفعال سازی ثبت سفارش",
        "group": "orders",
        "width": 6,
        "type": "checkbox",
        "values": [],
        "key": "disable_order_submit",
        "default_value": false
    },
    {
        "label": "رنگ اصلی",
        "group": "appearance",
        "width": 4,
        "type": "color-picker",
        "values": [],
        "key": "primary_color",
        "default_value": "#2271b1"
    },
    {
        "label": "رنگ ثانویه",
        "group": "appearance",
        "width": 4,
        "type": "color-picker",
        "values": [],
        "key": "secondary_color",
        "default_value": "#7d22b1"
    }
]
JSON;

    private const SETTINGS_GROUPS_JSON = <<<'JSON'
[
    {
        "key": "store",
        "label": "اطلاعات فروشگاه",
        "type": "info"
    },
    {
        "key": "appearance",
        "label": "ظاهر فروشگاه",
        "type": "info"
    },
    {
        "key": "visibility",
        "label": "نمایش اطلاعات",
        "type": "warning"
    },
    {
        "key": "orders",
        "label": "ثبت سفارش",
        "type": "danger"
    }
]
JSON;

    private static ?array $fields = null;
    private static ?array $groups = null;
    private static ?string $settings_page_hook = null;

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function get_fields(): array
    {
        if (self::$fields !== null) return self::$fields;

        try {
            $fields = json_decode(self::FIELDS_JSON, true, 512, JSON_THROW_ON_ERROR);
            self::$fields = is_array($fields) ? array_values($fields) : [];
        } catch (\JsonException $exception) {
            self::$fields = [];
        }

        return self::$fields;
    }

    public static function get_public_fields(): array
    {
        return array_map(static function (array $field): array {
            unset($field['group']);
            unset($field['width']);

            return $field;
        }, self::get_fields());
    }

    private static function get_groups(): array
    {
        if (self::$groups !== null) return self::$groups;

        try {
            $groups = json_decode(self::SETTINGS_GROUPS_JSON, true, 512, JSON_THROW_ON_ERROR);
            self::$groups = is_array($groups) ? array_values($groups) : [];
        } catch (\JsonException $exception) {
            self::$groups = [];
        }

        return self::$groups;
    }

    public static function get_public_config(): array
    {
        $config = self::get_config();

        foreach (self::get_fields() as $field) {
            $key = self::get_field_key($field);
            if ($key === '' || self::normalize_type($field['type'] ?? '') !== 'media-picker') continue;

            $value = $config[$key] ?? null;
            if ($value === null || $value === '') continue;

            $attachment_id = absint($value);
            $config[$key] = [
                'id' => $attachment_id,
                'url' => $attachment_id ? wp_get_attachment_image_url($attachment_id, 'full') : null,
            ];
        }

        return $config;
    }

    public static function register_admin_menu(): void
    {
        add_menu_page(
            __('Primer Woo Package', 'primer-better-woocommerce-endpoints'),
            __('Primer Woo API', 'primer-better-woocommerce-endpoints'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_api_page'],
            plugin_dir_url(__FILE__) . 'assets/primer-studio.svg',
            56
        );

        self::$settings_page_hook = add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'primer-better-woocommerce-endpoints'),
            __('Settings', 'primer-better-woocommerce-endpoints'),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            [self::class, 'render_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('API endpoints', 'primer-better-woocommerce-endpoints'),
            __('API endpoints', 'primer-better-woocommerce-endpoints'),
            'manage_options',
            self::API_PAGE_SLUG,
            [self::class, 'render_api_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [self::class, 'sanitize_settings'],
        ]);
    }

    public static function sanitize_settings($input): array
    {
        $input = is_array($input) ? $input : [];
        $settings = [];

        foreach (self::get_fields() as $field) {
            $key = self::get_field_key($field);
            if ($key === '') continue;

            $default = $field['default_value'] ?? null;
            $value = array_key_exists($key, $input) ? $input[$key] : $default;
            $type = self::normalize_type($field['type'] ?? 'text');

            switch ($type) {
                case 'media-picker':
                    $settings[$key] = $value === null || $value === '' ? null : absint($value);
                    break;

                case 'text-area':
                    $settings[$key] = $value === null ? null : sanitize_textarea_field((string) $value);
                    break;

                case 'select':
                    $settings[$key] = self::sanitize_select_value($value, $field, $default);
                    break;

                case 'checkbox':
                    $settings[$key] = self::sanitize_boolean($value, $default);
                    break;

                case 'color-picker':
                    $color = $value === null ? null : sanitize_hex_color((string) $value);
                    $settings[$key] = $color !== null ? $color : $default;
                    break;

                case 'text':
                default:
                    $settings[$key] = $value === null ? null : sanitize_text_field((string) $value);
                    break;
            }
        }

        return $settings;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Primer WooCommerce API', 'primer-better-woocommerce-endpoints'); ?></h1>
            <style>
                .pbe-settings-group {
                    max-width: 1100px;
                    margin: 0 0 24px;
                    padding: 0 20px 12px;
                    border: 1px solid #c3c4c7;
                    border-radius: 4px;
                    background: #fff;
                }
                .pbe-settings-group--info {
                    border-color: #2271b1;
                }
                .pbe-settings-group--warning {
                    border-color: #dba617;
                }
                .pbe-settings-group--danger {
                    border-color: #d63638;
                }
                .pbe-settings-group__title {
                    margin: 0 -20px 0;
                    padding: 14px 20px;
                    border-bottom: 1px solid #c3c4c7;
                    font-size: 16px;
                }
                .pbe-settings-group--info .pbe-settings-group__title {
                    border-bottom-color: #2271b1;
                }
                .pbe-settings-group--warning .pbe-settings-group__title {
                    border-bottom-color: #dba617;
                }
                .pbe-settings-group--danger .pbe-settings-group__title {
                    border-bottom-color: #d63638;
                }
                .pbe-settings-fields {
                    display: grid;
                    grid-template-columns: repeat(12, minmax(0, 1fr));
                    gap: 20px 24px;
                    padding-top: 16px;
                }
                .pbe-setting-item {
                    min-width: 0;
                }
                .pbe-setting-label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 600;
                }
                .pbe-setting-item .large-text {
                    width: 100%;
                    max-width: 100%;
                }
                .pbe-setting-item select {
                    max-width: 100%;
                }
                @media screen and (max-width: 782px) {
                    .pbe-setting-item {
                        grid-column: 1 / -1 !important;
                    }
                }
            </style>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::SETTINGS_GROUP);
                self::render_settings_groups();
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    private static function render_settings_groups(): void
    {
        $fields_by_group = [];

        foreach (self::get_fields() as $field) {
            $group_key = self::get_group_key($field['group'] ?? null);
            $group_key = $group_key !== '' ? $group_key : '__ungrouped';
            $fields_by_group[$group_key][] = $field;
        }

        $rendered_groups = [];

        foreach (self::get_groups() as $group) {
            $group_key = self::get_group_key($group['key'] ?? null);
            if ($group_key === '' || empty($fields_by_group[$group_key])) continue;

            self::render_settings_group($group, $fields_by_group[$group_key]);
            $rendered_groups[$group_key] = true;
        }

        foreach ($fields_by_group as $group_key => $fields) {
            if (isset($rendered_groups[$group_key])) continue;

            self::render_settings_group([
                'key' => $group_key,
                'label' => $group_key === '__ungrouped'
                    ? __('Other settings', 'primer-better-woocommerce-endpoints')
                    : $group_key,
            ], $fields);
        }
    }

    private static function render_settings_group(array $group, array $fields): void
    {
        $variant = self::get_group_variant($group);
        $title = is_scalar($group['label'] ?? null) ? (string) $group['label'] : (string) ($group['key'] ?? '');
        ?>
        <section class="pbe-settings-group pbe-settings-group--<?php echo esc_attr($variant); ?>">
            <h2 class="pbe-settings-group__title"><?php echo esc_html($title); ?></h2>
            <div class="pbe-settings-fields">
                <?php foreach ($fields as $field): ?>
                    <?php
                    $field_key = self::get_field_key($field);
                    if ($field_key === '') continue;
                    ?>
                    <div class="pbe-setting-item" style="grid-column: span <?php echo esc_attr((string) self::get_field_width($field)); ?>;">
                        <label class="pbe-setting-label" for="<?php echo esc_attr('pbe-setting-' . sanitize_html_class($field_key)); ?>">
                            <?php echo esc_html((string) ($field['label'] ?? $field_key)); ?>
                        </label>
                        <?php self::render_field(['field' => $field]); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    public static function render_api_page(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Primer WooCommerce API endpoints', 'primer-better-woocommerce-endpoints'); ?></h1>
            <p><?php echo esc_html__('Use these links to inspect the public REST API responses. Replace {product_id} with an existing WooCommerce product ID for product routes.', 'primer-better-woocommerce-endpoints'); ?></p>
            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Endpoint', 'primer-better-woocommerce-endpoints'); ?></th>
                        <th><?php echo esc_html__('Method', 'primer-better-woocommerce-endpoints'); ?></th>
                        <th><?php echo esc_html__('Route', 'primer-better-woocommerce-endpoints'); ?></th>
                        <th><?php echo esc_html__('Open', 'primer-better-woocommerce-endpoints'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (self::API_ROUTES as $route): ?>
                        <?php
                        $path = (string) $route['path'];
                        $example_path = str_replace('{product_id}', '1', $path);
                        $display_url = rest_url('primer-better-woocommerce/v1/' . $path);
                        $example_url = rest_url('primer-better-woocommerce/v1/' . $example_path);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html((string) $route['label']); ?></strong>
                                <p class="description"><?php echo esc_html((string) $route['description']); ?></p>
                            </td>
                            <td><code><?php echo esc_html((string) $route['method']); ?></code></td>
                            <td><code><?php echo esc_html($display_url); ?></code></td>
                            <td>
                                <a class="button" href="<?php echo esc_url($example_url); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html__('Open', 'primer-better-woocommerce-endpoints'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== self::$settings_page_hook) return;

        wp_enqueue_media();
        wp_enqueue_script('jquery');

        wp_add_inline_script('jquery', <<<'JS'
jQuery(function($) {
    $('.pbe-media-button').on('click', function(event) {
        event.preventDefault();

        const button = $(this);
        const target = $('#' + button.data('target'));
        const preview = $('#' + button.data('preview'));
        const remove = $('#' + button.data('remove'));
        const frame = wp.media({
            title: button.data('title'),
            button: { text: button.data('button') },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            target.val(attachment.id).trigger('change');
            preview.empty();

            if (attachment.url) {
                $('<img>', { src: attachment.url, alt: '', style: 'max-width:150px;height:auto;display:block;margin:8px 0;' }).appendTo(preview);
            }

            remove.show();
        });

        frame.open();
    });

    $('.pbe-media-remove').on('click', function(event) {
        event.preventDefault();

        const button = $(this);
        $('#' + button.data('target')).val('').trigger('change');
        $('#' + button.data('preview')).empty();
        button.hide();
    });

    $('.pbe-checkbox').on('change', function() {
        const checkbox = $(this);
        const status = checkbox.siblings('.pbe-checkbox-status');
        status.text(checkbox.is(':checked') ? checkbox.data('active') : checkbox.data('inactive'));
    });
});
JS
        );
    }

    public static function render_field(array $args): void
    {
        $field = is_array($args['field'] ?? null) ? $args['field'] : [];
        $key = self::get_field_key($field);
        if ($key === '') return;

        $config = self::get_config();
        $value = $config[$key] ?? ($field['default_value'] ?? null);
        $type = self::normalize_type($field['type'] ?? 'text');
        $name = self::OPTION_NAME . '[' . $key . ']';
        $id = 'pbe-setting-' . sanitize_html_class($key);

        switch ($type) {
            case 'media-picker':
                $attachment_id = $value === null || $value === '' ? 0 : absint($value);
                $preview_id = $id . '-preview';
                $remove_id = $id . '-remove';
                $preview_url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'medium') : false;
                ?>
                <input type="hidden" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $attachment_id); ?>">
                <div id="<?php echo esc_attr($preview_id); ?>">
                    <?php if ($preview_url): ?>
                        <img src="<?php echo esc_url($preview_url); ?>" alt="" style="max-width:150px;height:auto;display:block;margin:8px 0;">
                    <?php endif; ?>
                </div>
                <button type="button" class="button pbe-media-button" data-target="<?php echo esc_attr($id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>" data-remove="<?php echo esc_attr($remove_id); ?>" data-title="<?php echo esc_attr__('Choose image', 'primer-better-woocommerce-endpoints'); ?>" data-button="<?php echo esc_attr__('Use image', 'primer-better-woocommerce-endpoints'); ?>">
                    <?php echo esc_html__('Choose image', 'primer-better-woocommerce-endpoints'); ?>
                </button>
                <button type="button" id="<?php echo esc_attr($remove_id); ?>" class="button pbe-media-remove" data-target="<?php echo esc_attr($id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>" <?php echo $attachment_id ? '' : 'style="display:none;"'; ?>>
                    <?php echo esc_html__('Remove', 'primer-better-woocommerce-endpoints'); ?>
                </button>
                <?php
                break;

            case 'text-area':
                printf(
                    '<textarea id="%1$s" name="%2$s" rows="5" class="large-text">%3$s</textarea>',
                    esc_attr($id),
                    esc_attr($name),
                    esc_textarea((string) ($value ?? ''))
                );
                break;

            case 'select':
                printf('<select id="%1$s" name="%2$s">', esc_attr($id), esc_attr($name));
                foreach (self::get_select_values($field) as $option) {
                    $option_value = (string) ($option['value'] ?? '');
                    printf(
                        '<option value="%1$s"%2$s>%3$s</option>',
                        esc_attr($option_value),
                        selected((string) $value, $option_value, false),
                        esc_html((string) ($option['label'] ?? $option_value))
                    );
                }
                echo '</select>';
                break;

            case 'checkbox':
                $active_label = __('فعال', 'primer-better-woocommerce-endpoints');
                $inactive_label = __('غیرفعال', 'primer-better-woocommerce-endpoints');
                printf('<input type="hidden" name="%1$s" value="0">', esc_attr($name));
                printf(
                    '<label><input type="checkbox" class="pbe-checkbox" id="%1$s" name="%2$s" value="1"%3$s data-active="%4$s" data-inactive="%5$s"> <span class="pbe-checkbox-status" aria-live="polite">%6$s</span></label>',
                    esc_attr($id),
                    esc_attr($name),
                    checked((bool) $value, true, false),
                    esc_attr($active_label),
                    esc_attr($inactive_label),
                    esc_html((bool) $value ? $active_label : $inactive_label)
                );
                break;

            case 'color-picker':
                $color = sanitize_hex_color((string) ($value ?? '')) ?: '#000000';
                printf(
                    '<input type="color" id="%1$s" name="%2$s" value="%3$s" class="pbe-color-picker">',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr($color)
                );
                break;

            case 'text':
            default:
                printf(
                    '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr((string) ($value ?? ''))
                );
                break;
        }
    }

    private static function get_config(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        $stored = is_array($stored) ? $stored : [];
        $config = [];

        foreach (self::get_fields() as $field) {
            $key = self::get_field_key($field);
            if ($key === '') continue;

            $config[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : ($field['default_value'] ?? null);
        }

        return $config;
    }

    private static function get_field_key(array $field): string
    {
        return isset($field['key']) && is_scalar($field['key']) ? sanitize_key((string) $field['key']) : '';
    }

    private static function get_group_key($value): string
    {
        return is_scalar($value) ? sanitize_key((string) $value) : '';
    }

    private static function get_group_variant(array $group): string
    {
        $variant = $group['type'] ?? ($group['variant'] ?? ($group['style'] ?? ''));
        $variant = self::get_group_key($variant);

        return in_array($variant, ['danger', 'warning', 'info'], true) ? $variant : 'default';
    }

    private static function get_field_width(array $field): int
    {
        $width = $field['width'] ?? 12;

        if (is_string($width)) {
            $width = trim($width);

            if (str_ends_with($width, '%')) {
                $width = (float) rtrim($width, '%');
                return max(1, min(12, (int) round($width / 100 * 12)));
            }
        }

        if (!is_numeric($width)) return 12;

        $width = (float) $width;
        if ($width <= 0) return 12;
        if ($width <= 12 && floor($width) === $width) return (int) $width;

        return max(1, min(12, (int) round($width / 100 * 12)));
    }

    private static function normalize_type(string $type): string
    {
        $type = strtolower(trim(str_replace(['_', ' '], '-', $type)));

        return match ($type) {
            'media', 'image', 'media-picker' => 'media-picker',
            'textarea', 'text-area' => 'text-area',
            'color', 'color-picker' => 'color-picker',
            'select' => 'select',
            'checkbox' => 'checkbox',
            default => 'text',
        };
    }

    private static function get_select_values(array $field): array
    {
        $values = is_array($field['values'] ?? null) ? $field['values'] : [];

        return array_values(array_filter($values, static function ($value): bool {
            return is_array($value) && isset($value['value']) && is_scalar($value['value']);
        }));
    }

    private static function sanitize_select_value($value, array $field, $default)
    {
        if ($value === null) return null;

        $value = is_scalar($value) ? (string) $value : '';
        $allowed = array_map(static function (array $option): string {
            return (string) $option['value'];
        }, self::get_select_values($field));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function sanitize_boolean($value, $default): bool
    {
        if (is_bool($value)) return $value;
        if ($value === '1' || $value === 1) return true;
        if ($value === '0' || $value === 0 || $value === '' || $value === null) return false;

        return (bool) $default;
    }
}

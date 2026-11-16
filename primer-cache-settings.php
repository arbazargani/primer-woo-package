<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Primer_Cache_Settings
{
    private const OPTION_NAME = 'primer_cache_settings';
    private const SETTINGS_GROUP = 'primer_cache_settings_group';
    private const PAGE_SLUG = 'primer-cache-settings';
    private const REVALIDATE_ACTION = 'primer_revalidate_cache';
    private const CLEAN_CACHE_ACTION = 'primer_clean_cache';

    private const TAGS = [
        'menu',
        'products',
        'productBySlug',
        'productsAttributes',
        'home',
        'categories',
        'categoriesById',
        'brands',
        'brandByIdentifier',
        'attributes',
        'usages',
        'productUsage',
        'usageArchive',
        'reviews',
        'posts',
        'postsDetail',
        'taxonomy',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_admin_menu'], 20);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_post_' . self::REVALIDATE_ACTION, [self::class, 'handle_revalidate']);
        add_action('admin_bar_menu', [self::class, 'add_admin_bar_item'], 100);
        add_action('admin_post_' . self::CLEAN_CACHE_ACTION, [self::class, 'handle_clean_cache']);
    }

    public static function add_admin_bar_item(WP_Admin_Bar $admin_bar): void
    {
        if (!is_admin_bar_showing()) {
            return;
        }

        $admin_bar->add_node([
            'id' => 'primer-clean-cache',
            'title' => __('پاک‌سازی کش', 'primer-better-woocommerce-endpoints'),
            'href' => wp_nonce_url(
                admin_url('admin-post.php?action=' . self::CLEAN_CACHE_ACTION),
                self::CLEAN_CACHE_ACTION
            ),
            'meta' => [
                'title' => __('اعتبارسنجی مجدد همه تگ‌ها و پاک‌سازی کش لایت‌اسپید', 'primer-better-woocommerce-endpoints'),
            ],
        ]);
    }

    public static function register_admin_menu(): void
    {
        add_submenu_page(
            'primer-wc-api',
            __('Cache settings', 'primer-better-woocommerce-endpoints'),
            __('Cache settings', 'primer-better-woocommerce-endpoints'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
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

        return [
            'next_revalidate_secret' => isset($input['next_revalidate_secret'])
                ? sanitize_text_field((string) $input['next_revalidate_secret'])
                : '',
            'next_revalidate_url' => isset($input['next_revalidate_url'])
                ? esc_url_raw((string) $input['next_revalidate_url'])
                : '',
        ];
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) return;

        $settings = self::get_settings();
        $results = [];
        $clean_cache_results = false;
        if (isset($_GET['primer_cache_result'])) {
            $result_key = sanitize_key(wp_unslash($_GET['primer_cache_result']));
            $stored_results = get_transient($result_key);
            delete_transient($result_key);

            if (is_array($stored_results) && isset($stored_results['results'])) {
                $results = is_array($stored_results['results']) ? $stored_results['results'] : [];
                $clean_cache_results = !empty($stored_results['clean_cache']);
            } elseif (is_array($stored_results)) {
                $results = $stored_results;
            }
        }
        ?>
        <div class="wrap">
            <?php if ($clean_cache_results) : ?>
                <h1><?php echo esc_html__('نتیجه پاک‌سازی کش', 'primer-better-woocommerce-endpoints'); ?></h1>
                <?php self::render_results($results); ?>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="button"><?php echo esc_html__('بازگشت به تنظیمات کش', 'primer-better-woocommerce-endpoints'); ?></a></p>
            <?php return; endif; ?>
            <h1><?php echo esc_html__('Cache settings', 'primer-better-woocommerce-endpoints'); ?></h1>
            <style>
                .pbe-cache-card {
                    max-width: 1100px;
                    margin: 0 0 24px;
                    padding: 0 20px 20px;
                    border: 1px solid #c3c4c7;
                    border-radius: 4px;
                    background: #fff;
                }
                .pbe-cache-card__title {
                    margin: 0 -20px;
                    padding: 14px 20px;
                    border-bottom: 1px solid #2271b1;
                    font-size: 16px;
                }
                .pbe-cache-fields {
                    display: grid;
                    grid-template-columns: repeat(12, minmax(0, 1fr));
                    gap: 20px 24px;
                    padding-top: 16px;
                }
                .pbe-cache-field { grid-column: span 6; min-width: 0; }
                .pbe-cache-field label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 600;
                }
                .pbe-cache-field input,
                .pbe-cache-field select { width: 100%; max-width: 100%; }
                .pbe-cache-field select { min-height: 180px; }
                .pbe-cache-response {
                    margin: 16px 0 0;
                    padding: 12px 16px;
                    border-left: 4px solid #2271b1;
                    background: #f6f7f7;
                }
                .pbe-cache-response--error { border-left-color: #d63638; }
                .pbe-cache-response__body {
                    max-height: 180px;
                    overflow: auto;
                    margin: 8px 0 0;
                    padding: 10px;
                    background: #fff;
                    white-space: pre-wrap;
                    word-break: break-word;
                }
                @media screen and (max-width: 782px) {
                    .pbe-cache-field { grid-column: 1 / -1; }
                }
            </style>

            <div class="pbe-cache-card">
                <h2 class="pbe-cache-card__title"><?php echo esc_html__('Revalidation settings', 'primer-better-woocommerce-endpoints'); ?></h2>
                <form action="options.php" method="post">
                    <?php settings_fields(self::SETTINGS_GROUP); ?>
                    <div class="pbe-cache-fields">
                        <div class="pbe-cache-field">
                            <label for="next_revalidate_secret"><?php echo esc_html__('Next revalidate secret', 'primer-better-woocommerce-endpoints'); ?></label>
                            <input type="text" id="next_revalidate_secret" name="<?php echo esc_attr(self::OPTION_NAME); ?>[next_revalidate_secret]" value="<?php echo esc_attr($settings['next_revalidate_secret']); ?>">
                        </div>
                        <div class="pbe-cache-field">
                            <label for="next_revalidate_url"><?php echo esc_html__('Next revalidate URL', 'primer-better-woocommerce-endpoints'); ?></label>
                            <input type="text" id="next_revalidate_url" name="<?php echo esc_attr(self::OPTION_NAME); ?>[next_revalidate_url]" value="<?php echo esc_attr($settings['next_revalidate_url']); ?>">
                        </div>
                    </div>
                    <?php submit_button(__('Save settings', 'primer-better-woocommerce-endpoints')); ?>
                </form>
            </div>

            <div class="pbe-cache-card">
                <h2 class="pbe-cache-card__title"><?php echo esc_html__('Purge cache', 'primer-better-woocommerce-endpoints'); ?></h2>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::REVALIDATE_ACTION); ?>">
                    <?php wp_nonce_field(self::REVALIDATE_ACTION); ?>
                    <div class="pbe-cache-fields">
                        <div class="pbe-cache-field">
                            <label for="primer-cache-tags"><?php echo esc_html__('Tag', 'primer-better-woocommerce-endpoints'); ?></label>
                            <select id="primer-cache-tags" name="tag[]" multiple required>
                                <?php foreach (self::TAGS as $tag) : ?>
                                    <option value="<?php echo esc_attr($tag); ?>"><?php echo esc_html($tag); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html__('Use Ctrl/Cmd-click to select multiple tags.', 'primer-better-woocommerce-endpoints'); ?></p>
                        </div>
                    </div>
                    <?php submit_button(__('Revalidate', 'primer-better-woocommerce-endpoints'), 'primary', 'submit', false); ?>
                </form>

                <?php self::render_results($results); ?>
            </div>
        </div>
        <?php
    }

    public static function handle_revalidate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'primer-better-woocommerce-endpoints'));
        }

        check_admin_referer(self::REVALIDATE_ACTION);

        $tags = isset($_POST['tag']) && is_array($_POST['tag']) ? wp_unslash($_POST['tag']) : [];
        $tags = array_values(array_intersect(array_map('sanitize_text_field', $tags), self::TAGS));
        self::redirect_with_results(self::revalidate_tags($tags));
    }

    public static function handle_clean_cache(): void
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'primer-better-woocommerce-endpoints'));
        }

        check_admin_referer(self::CLEAN_CACHE_ACTION);

        $results = self::revalidate_tags(self::TAGS);
        $results[] = self::purge_litespeed_cache();
        self::redirect_with_results($results, true);
    }

    private static function revalidate_tags(array $tags): array
    {
        $settings = self::get_settings();
        $results = [];

        if ($settings['next_revalidate_url'] === '' || $settings['next_revalidate_secret'] === '' || empty($tags)) {
            return [[
                'tag' => empty($tags) ? __('No tags selected', 'primer-better-woocommerce-endpoints') : implode(', ', $tags),
                'url' => '',
                'success' => false,
                'status' => 0,
                'body' => __('Missing revalidation URL, secret, or selected tags.', 'primer-better-woocommerce-endpoints'),
            ]];
        }

        foreach ($tags as $tag) {
            $url = add_query_arg([
                'secret' => $settings['next_revalidate_secret'],
                'tag' => $tag,
            ], $settings['next_revalidate_url']);
            $response = wp_remote_post($url, ['timeout' => 15]);
            $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);

            $results[] = [
                'tag' => $tag,
                'url' => add_query_arg([
                    'secret' => '********',
                    'tag' => $tag,
                ], $settings['next_revalidate_url']),
                'success' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => (string) $body,
            ];
        }

        return $results;
    }

    private static function purge_litespeed_cache(): array
    {
        if (class_exists('LiteSpeed_Cache_API') && is_callable(['LiteSpeed_Cache_API', 'purge_all'])) {
            $purged = LiteSpeed_Cache_API::purge_all();

            return [
                'tag' => __('LiteSpeed Cache', 'primer-better-woocommerce-endpoints'),
                'url' => '',
                'success' => $purged !== false,
                'status' => $purged !== false ? 200 : 500,
                'body' => $purged !== false
                    ? __('LiteSpeed Cache was purged.', 'primer-better-woocommerce-endpoints')
                    : __('LiteSpeed Cache purge failed.', 'primer-better-woocommerce-endpoints'),
            ];
        }

        if (has_action('litespeed_purge_all') !== false) {
            do_action('litespeed_purge_all');

            return [
                'tag' => __('LiteSpeed Cache', 'primer-better-woocommerce-endpoints'),
                'url' => '',
                'success' => true,
                'status' => 200,
                'body' => __('LiteSpeed Cache purge action dispatched.', 'primer-better-woocommerce-endpoints'),
            ];
        }

        return [
            'tag' => __('LiteSpeed Cache', 'primer-better-woocommerce-endpoints'),
            'url' => '',
            'success' => true,
            'status' => 204,
            'body' => __('LiteSpeed Cache is not installed or active; no LiteSpeed cache was cleared.', 'primer-better-woocommerce-endpoints'),
        ];
    }

    private static function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);
        $settings = is_array($settings) ? $settings : [];

        return [
            'next_revalidate_secret' => (string) ($settings['next_revalidate_secret'] ?? ''),
            'next_revalidate_url' => (string) ($settings['next_revalidate_url'] ?? ''),
        ];
    }

    private static function render_results(array $results): void
    {
        if (empty($results)) return;
        ?>
        <div class="pbe-cache-response-list" aria-live="polite">
            <h3><?php echo esc_html__('Revalidation response', 'primer-better-woocommerce-endpoints'); ?></h3>
            <?php foreach ($results as $result) : ?>
                <div class="pbe-cache-response <?php echo !empty($result['success']) ? '' : 'pbe-cache-response--error'; ?>">
                    <strong><?php echo !empty($result['success']) ? '✅' : '❌'; ?> <?php echo esc_html($result['tag'] ?? ''); ?></strong>
                    — <?php echo !empty($result['success']) ? esc_html__('Request completed', 'primer-better-woocommerce-endpoints') : esc_html__('Request failed', 'primer-better-woocommerce-endpoints'); ?>
                    (HTTP <?php echo esc_html((string) ($result['status'] ?? 0)); ?>)
                    <?php if (!empty($result['url'])) : ?>
                        <p><code><?php echo esc_html($result['url']); ?></code></p>
                    <?php endif; ?>
                    <pre class="pbe-cache-response__body"><?php echo esc_html((string) ($result['body'] ?? '')); ?></pre>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function redirect_with_results(array $results, bool $clean_cache = false): void
    {
        $result_key = 'primer_cache_' . wp_generate_uuid4();
        set_transient($result_key, [
            'results' => $results,
            'clean_cache' => $clean_cache,
        ], 5 * MINUTE_IN_SECONDS);

        $url = add_query_arg([
            'page' => self::PAGE_SLUG,
            'primer_cache_result' => $result_key,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }
}

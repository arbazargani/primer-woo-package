<?php
/**
 * Independent card-to-card WooCommerce gateway and receipt module.
 *
 * This file intentionally contains the complete feature and has no external dependencies.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PWC_Card_To_Card_Module')) {
    final class PWC_Card_To_Card_Module
    {
        private const OPTION_ENABLED = 'pwc_card_to_card_enabled';
        private const OPTION_APP_KEY = 'pwc_card_to_card_app_key';
        private const NAMESPACE = 'primer-card-to-card/v1';
        private const META_PREFIX = '_pwc_card_receipt_';
        private const META_KEYS = [
            'type', 'text', 'attachment_id', 'attachment_url', 'submitted_at',
            'submitted_by', 'status',
        ];

        public static function boot(): void
        {
            add_filter('woocommerce_payment_gateways', [self::class, 'register_gateway']);
            add_action('rest_api_init', [self::class, 'register_routes']);
            add_action('admin_menu', [self::class, 'register_settings_page'], 20);
            add_action('admin_footer', [self::class, 'render_api_endpoints_rows']);
            add_action('admin_post_pwc_card_receipt_action', [self::class, 'handle_admin_action']);
            add_action('plugins_loaded', [self::class, 'define_gateway_class'], 20);
            add_action('woocommerce_loaded', [self::class, 'define_gateway_class'], 20);
            add_action('woocommerce_blocks_payment_method_type_registration', [self::class, 'register_blocks_payment_method']);
            add_action('woocommerce_admin_order_data_after_order_details', [self::class, 'render_admin_order_panel']);
        }

        public static function register_gateway(array $gateways): array
        {
            self::define_gateway_class();
            if (!in_array('PWC_Card_To_Card_Gateway', $gateways, true)) {
                $gateways[] = 'PWC_Card_To_Card_Gateway';
            }
            return $gateways;
        }

        public static function define_gateway_class(): void
        {
            pwc_card_to_card_define_gateway_class();
        }

        public static function is_feature_enabled(): bool
        {
            $value = get_option(self::OPTION_ENABLED, 'yes');
            return $value === 'yes' || $value === true || $value === 1 || $value === '1';
        }

        public static function register_blocks_payment_method($payment_method_registry): void
        {
            if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
                return;
            }
            pwc_card_to_card_register_blocks_integration($payment_method_registry);
        }

        public static function register_settings_page(): void
        {
            add_submenu_page(
                'primer-wc-api',
                'کارت به کارت',
                'کارت به کارت',
                'manage_woocommerce',
                'pwc-card-to-card',
                [self::class, 'render_settings_page']
            );
        }

        private static function get_app_key(): string
        {
            return (string) get_option(self::OPTION_APP_KEY, '');
        }

        private static function request_app_key(WP_REST_Request $request): string
        {
            $key = (string) $request->get_header('X-App-Key');
            if ($key !== '') {
                return trim($key);
            }
            $authorization = trim((string) $request->get_header('Authorization'));
            if (stripos($authorization, 'AppKey ') === 0) {
                return trim(substr($authorization, 7));
            }
            return '';
        }

        private static function has_valid_app_key(WP_REST_Request $request): bool
        {
            $provided = self::request_app_key($request);
            $configured = self::get_app_key();
            return $provided !== '' && $configured !== '' && hash_equals($configured, $provided);
        }

        public static function render_settings_page(): void
        {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'woocommerce'));
            }
            if (isset($_POST['pwc_save_settings'])) {
                check_admin_referer('pwc_card_settings');
                update_option(self::OPTION_ENABLED, isset($_POST['pwc_enabled']) ? 'yes' : 'no');
                if (isset($_POST['pwc_app_key'])) {
                    update_option(self::OPTION_APP_KEY, sanitize_text_field(wp_unslash($_POST['pwc_app_key'])), false);
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('تنظیمات ذخیره شد.', 'woocommerce') . '</p></div>';
            }
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__('تنظیمات کارت به کارت', 'woocommerce'); ?></h1>
                <p><?php echo esc_html__('این گزینه مستقل از فعال/غیرفعال بودن درگاه در تنظیمات پرداخت ووکامرس است.', 'woocommerce'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('pwc_card_settings'); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="pwc_app_key">App key</label></th>
                            <td>
                                <input type="text" id="pwc_app_key" name="pwc_app_key" class="large-text code" value="<?php echo esc_attr(self::get_app_key()); ?>" autocomplete="off">
                                <p class="description">کلید را خودتان وارد کنید و سپس ذخیره کنید. این کلید را در فرانت‌اند با header به نام <code>X-App-Key</code> یا <code>Authorization: AppKey YOUR_KEY</code> ارسال کنید.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pwc_enabled">فعال بودن قابلیت</label></th>
                            <td><label><input type="checkbox" id="pwc_enabled" name="pwc_enabled" value="1" <?php checked(self::is_feature_enabled()); ?>> فعال</label></td>
                        </tr>
                    </tbody></table>
                    <p><button type="submit" name="pwc_save_settings" class="button button-primary">ذخیره تغییرات</button></p>
                </form>
            </div>
            <?php
        }

        public static function render_api_endpoints_rows(): void
        {
            if (!current_user_can('manage_options') || !is_admin()) {
                return;
            }

            $page = (string) ($_GET['page'] ?? '');
            if (!in_array($page, ['primer-wc-api', 'primer-wc-api-endpoints'], true)) {
                return;
            }

            $base = esc_url_raw(rest_url(self::NAMESPACE));
            $rows = [
                [
                    'label' => 'Payment method',
                    'method' => 'GET',
                    'path' => $base . '/payment-method',
                    'description' => 'تنظیمات و وضعیت درگاه کارت به کارت',
                ],
                [
                    'label' => 'Order receipt',
                    'method' => 'GET',
                    'path' => $base . '/orders/{order_id}/receipt',
                    'description' => 'دریافت رسید سفارش',
                ],
                [
                    'label' => 'Submit receipt',
                    'method' => 'POST',
                    'path' => $base . '/orders/{order_id}/receipt',
                    'description' => 'ثبت یا جایگزینی رسید متنی یا تصویری',
                ],
            ];
            ?>
            <script>
            (function () {
                var table = document.querySelector('.wrap table.widefat');
                if (!table) return;
                var body = table.querySelector('tbody');
                if (!body || body.querySelector('[data-pwc-card-to-card-row="1"]')) return;
                var rows = <?php echo wp_json_encode($rows); ?>;
                rows.forEach(function (item) {
                    var row = document.createElement('tr');
                    row.setAttribute('data-pwc-card-to-card-row', '1');
                    row.innerHTML = '<td><strong></strong><p class="description"></p></td>' +
                        '<td><code></code></td><td><code></code></td><td><a class="button" target="_blank" rel="noopener noreferrer">Open</a></td>';
                    row.querySelector('strong').textContent = item.label;
                    row.querySelector('.description').textContent = item.description;
                    row.querySelectorAll('code')[0].textContent = item.method;
                    row.querySelectorAll('code')[1].textContent = item.path;
                    row.querySelector('a').href = item.path.replace('{order_id}', '1');
                    body.appendChild(row);
                });
            }());
            </script>
            <?php
        }

        public static function register_routes(): void
        {
            register_rest_route(self::NAMESPACE, '/payment-method', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'get_payment_method'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route(self::NAMESPACE, '/orders/(?P<order_id>\d+)/receipt', [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [self::class, 'get_receipt'],
                    'permission_callback' => [self::class, 'permission_for_order'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [self::class, 'save_receipt'],
                    'permission_callback' => [self::class, 'permission_for_order'],
                ],
            ]);
        }

        public static function get_payment_method(): WP_REST_Response
        {
            $gateway = class_exists('PWC_Card_To_Card_Gateway') ? new PWC_Card_To_Card_Gateway() : null;
            return new WP_REST_Response([
                'success' => true,
                'enabled' => self::is_feature_enabled() && $gateway && $gateway->enabled === 'yes',
                'title' => $gateway ? wp_strip_all_tags($gateway->get_title()) : 'کارت به کارت',
                'description' => $gateway ? wp_strip_all_tags($gateway->get_description()) : '',
                'card_number' => $gateway ? $gateway->get_option('card_number') : '',
                'account_holder' => $gateway ? $gateway->get_option('account_holder') : '',
            ]);
        }

        public static function permission_for_order(WP_REST_Request $request)
        {
            $has_app_key = self::has_valid_app_key($request);
            $order = self::get_order($request);
            if (!$order) {
                return new WP_Error('pwc_order_not_found', 'Order not found.', ['status' => 404]);
            }
            // WordPress Application Password authentication populates the current user.
            // The capability/ownership check below prevents an authenticated user from accessing another order.
            if (is_user_logged_in() && current_user_can('manage_woocommerce')) {
                return true;
            }
            if (is_user_logged_in() && get_current_user_id() && (int) $order->get_user_id() === get_current_user_id()) {
                return true;
            }
            $order_key = sanitize_text_field((string) ($request->get_param('order_key') ?: $request->get_header('X-WC-Order-Key')));
            if ($order_key && hash_equals((string) $order->get_order_key(), $order_key)) {
                return $has_app_key || $order_key !== '';
            }
            if (!$has_app_key) {
                return new WP_Error('pwc_invalid_authentication', 'Use WordPress Application Password authentication or a valid app key.', ['status' => 401]);
            }
            return new WP_Error('pwc_forbidden', 'You are not allowed to access this order.', ['status' => 403]);
        }

        private static function get_order(WP_REST_Request $request): WC_Order|bool
        {
            if (!function_exists('wc_get_order')) {
                return null;
            }
            $id = absint($request['order_id']);
            
            return wc_get_order($id);
        }

        public static function get_receipt(WP_REST_Request $request): WP_REST_Response
        {
            $order = self::get_order($request);
            return new WP_REST_Response(['success' => true, 'order_id' => $order->get_id(), 'receipt' => self::receipt_response($order)]);
        }

        public static function save_receipt(WP_REST_Request $request)
        {
            $order = self::get_order($request);
            if (!$order) {
                return new WP_Error('pwc_order_not_found', 'Order not found.', ['status' => 404]);
            }
            if (!in_array($order->get_payment_method(), ['pwc_card_to_card'], true)) {
                return new WP_Error('pwc_invalid_payment_method', 'This order does not use card-to-card payment.', ['status' => 400]);
            }
            $type = sanitize_key((string) $request->get_param('receipt_type'));
            if (!in_array($type, ['text', 'image'], true)) {
                return new WP_Error('pwc_invalid_receipt_type', 'receipt_type must be text or image.', ['status' => 400]);
            }
            $text = sanitize_textarea_field((string) $request->get_param('receipt_text'));
            $attachment_id = 0;
            $attachment_url = '';
            if ($type === 'text' && $text === '') {
                return new WP_Error('pwc_missing_receipt', 'receipt_text is required.', ['status' => 400]);
            }
            if ($type === 'image') {
                $file = $request->get_file_params()['receipt_image'] ?? null;
                if (!$file || !empty($file['error'])) {
                    return new WP_Error('pwc_invalid_image', 'A valid receipt_image file is required.', ['status' => 400]);
                }
                $upload = self::upload_receipt($file);
                if (is_wp_error($upload)) {
                    return $upload;
                }
                $attachment_id = (int) $upload;
                $attachment_url = (string) wp_get_attachment_url($attachment_id);
            }
            self::delete_previous_attachment($order);
            self::set_meta($order, 'type', $type);
            self::set_meta($order, 'text', $type === 'text' ? $text : '');
            self::set_meta($order, 'attachment_id', $attachment_id);
            self::set_meta($order, 'attachment_url', $attachment_url);
            self::set_meta($order, 'submitted_at', current_time('mysql', true));
            self::set_meta($order, 'submitted_by', get_current_user_id());
            self::set_meta($order, 'status', 'pending');
            $order->add_order_note('کارت به کارت: رسید جدید ثبت شد و در انتظار بررسی است.');
            $order->save();
            return new WP_REST_Response(['success' => true, 'order_id' => $order->get_id(), 'receipt' => self::receipt_response($order)], 201);
        }

        private static function upload_receipt(array $file)
        {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            if (!is_uploaded_file($file['tmp_name'])) {
                return new WP_Error('pwc_invalid_upload', 'Invalid uploaded file.', ['status' => 400]);
            }
            if ((int) $file['size'] > 10 * 1024 * 1024) {
                return new WP_Error('pwc_file_too_large', 'Receipt image is too large.', ['status' => 400]);
            }
            $check = wp_check_filetype_and_ext($file['tmp_name'], sanitize_file_name($file['name']), [
                'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            ]);
            if (empty($check['type']) || !file_is_displayable_image($file['tmp_name'])) {
                return new WP_Error('pwc_invalid_image', 'Only valid JPG, PNG or WebP images are accepted.', ['status' => 400]);
            }
            $file['name'] = sanitize_file_name($file['name']);
            $attachment_id = media_handle_sideload($file, 0, 'Card-to-card payment receipt');
            return is_wp_error($attachment_id) ? new WP_Error('pwc_upload_failed', $attachment_id->get_error_message(), ['status' => 500]) : $attachment_id;
        }

        private static function receipt_response(WC_Order $order): ?array
        {
            $type = (string) $order->get_meta(self::META_PREFIX . 'type', true);
            if (!$type) return null;
            $user_id = absint($order->get_meta(self::META_PREFIX . 'submitted_by', true));
            return [
                'type' => $type,
                'status' => (string) $order->get_meta(self::META_PREFIX . 'status', true) ?: 'pending',
                'text' => $type === 'text' ? (string) $order->get_meta(self::META_PREFIX . 'text', true) : null,
                'image_url' => $type === 'image' ? (string) $order->get_meta(self::META_PREFIX . 'attachment_url', true) : null,
                'attachment_id' => $type === 'image' ? absint($order->get_meta(self::META_PREFIX . 'attachment_id', true)) : 0,
                'submitted_at' => (string) $order->get_meta(self::META_PREFIX . 'submitted_at', true),
                'submitted_by' => $user_id,
            ];
        }

        private static function set_meta(WC_Order $order, string $key, $value): void
        {
            $order->update_meta_data(self::META_PREFIX . $key, $value);
        }

        private static function delete_previous_attachment(WC_Order $order): void
        {
            $old_id = absint($order->get_meta(self::META_PREFIX . 'attachment_id', true));
            if ($old_id) {
                wp_delete_attachment($old_id, true);
            }
        }


        public static function render_admin_order_panel(WC_Order $order): void
        {
            $receipt = self::receipt_response($order);
            if (!$receipt) return;
            $user = $receipt['submitted_by'] ? get_user_by('id', $receipt['submitted_by']) : false;
            ?>
            <?php
            $status = in_array($receipt['status'], ['pending', 'approved', 'rejected'], true) ? $receipt['status'] : 'pending';
            $status_labels = [
                'pending' => 'در انتظار بررسی',
                'approved' => 'تأیید شده',
                'rejected' => 'رد شده',
            ];
            $status_label = $status_labels[$status];
            ?>
            <div class="pwc-card-receipt-panel" style="margin:20px 0;padding:0;border:1px solid #c3c4c7;border-radius:6px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden;max-width:760px">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;background:#f6f7f7;border-bottom:1px solid #dcdcde">
                    <h3 style="margin:0;font-size:15px;color:#1d2327"><?php echo esc_html__('رسید کارت به کارت', 'woocommerce'); ?></h3>
                    <span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.5;<?php echo esc_attr($status === 'approved' ? 'background:#d1e7dd;color:#0f5132' : ($status === 'rejected' ? 'background:#f8d7da;color:#842029' : 'background:#fff3cd;color:#664d03')); ?>">
                        <?php echo esc_html($status_label); ?>
                    </span>
                </div>
                <div style="padding:16px 18px">
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px">
                        <div><span style="display:block;color:#646970;font-size:12px;margin-bottom:3px"><?php echo esc_html__('نوع رسید', 'woocommerce'); ?></span><strong><?php echo esc_html($receipt['type'] === 'image' ? 'تصویر' : 'متن'); ?></strong></div>
                        <div><span style="display:block;color:#646970;font-size:12px;margin-bottom:3px"><?php echo esc_html__('زمان ارسال', 'woocommerce'); ?></span><strong><?php echo esc_html($receipt['submitted_at']); ?></strong></div>
                        <div><span style="display:block;color:#646970;font-size:12px;margin-bottom:3px"><?php echo esc_html__('ثبت‌کننده', 'woocommerce'); ?></span><strong><?php echo esc_html($user ? $user->user_login : (string) $receipt['submitted_by']); ?></strong></div>
                    </div>
                    <?php if ($receipt['text']) : ?>
                        <div style="padding:12px 14px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;white-space:pre-wrap;word-break:break-word"><?php echo esc_html($receipt['text']); ?></div>
                    <?php endif; ?>
                    <?php if ($receipt['image_url']) : ?>
                        <div style="padding:10px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;text-align:center">
                            <a href="<?php echo esc_url($receipt['image_url']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr__('مشاهده تصویر در اندازه بزرگ‌تر', 'woocommerce'); ?>">
                                <img src="<?php echo esc_url($receipt['image_url']); ?>" alt="<?php echo esc_attr__('تصویر رسید کارت به کارت', 'woocommerce'); ?>" style="display:block;max-width:100%;max-height:360px;width:auto;height:auto;margin:0 auto;border-radius:3px;cursor:zoom-in">
                            </a>
                            <p style="margin:8px 0 0;color:#646970;font-size:12px"><?php echo esc_html__('برای مشاهده اندازه بزرگ‌تر روی تصویر کلیک کنید.', 'woocommerce'); ?></p>
                        </div>
                    <?php endif; ?>
                <?php if (current_user_can('manage_woocommerce')) : ?>
                    <?php
                    $action_url = static function (string $receipt_action) use ($order): string {
                        return wp_nonce_url(
                            add_query_arg([
                                'action' => 'pwc_card_receipt_action',
                                'order_id' => $order->get_id(),
                                'receipt_action' => $receipt_action,
                            ], admin_url('admin-post.php')),
                            'pwc_receipt_action_' . $order->get_id()
                        );
                    };
                    ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid #dcdcde">
                        <a href="<?php echo esc_url($action_url('approve')); ?>" style="display:inline-flex;align-items:center;background:#00a32a;color:#fff;border-color:#008a20;padding:5px 12px;border-radius:3px;text-decoration:none;font-weight:600;line-height:1.8">
                            تأیید رسید
                        </a>
                        <a href="<?php echo esc_url($action_url('reject')); ?>" style="display:inline-flex;align-items:center;background:#d63638;color:#fff;border-color:#b32d2e;padding:5px 12px;border-radius:3px;text-decoration:none;font-weight:600;line-height:1.8">
                            رد رسید
                        </a>
                        <?php if (!$order->has_status('processing')) : ?>
                            <a href="<?php echo esc_url($action_url('approve_processing')); ?>" style="display:inline-flex;align-items:center;background:#2271b1;color:#fff;border-color:#135e96;padding:5px 12px;border-radius:3px;text-decoration:none;font-weight:600;line-height:1.8">
                                تأیید و انتقال سفارش به در حال انجام
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }


        public static function handle_admin_action(): void
        {
            $order_id = absint($_REQUEST['order_id'] ?? 0);
            if (!current_user_can('manage_woocommerce') || !$order_id) {
                wp_die('Unauthorized', 403);
            }
            check_admin_referer('pwc_receipt_action_' . $order_id);
            $order = wc_get_order($order_id);
            $action = sanitize_key((string) ($_REQUEST['receipt_action'] ?? ''));
            if ($order && in_array($action, ['approve', 'reject', 'approve_processing'], true)) {
                $status = $action === 'reject' ? 'rejected' : 'approved';
                $order->update_meta_data(self::META_PREFIX . 'status', $status);
                if ($action === 'approve_processing' && $order->has_status(['on-hold', 'pending'])) $order->update_status('processing', 'رسید کارت به کارت توسط ادمین تأیید شد.');
                else $order->add_order_note('وضعیت رسید کارت به کارت: ' . $status . '.');
                $order->save();
            }
            wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order'));
            exit;
        }
    }
}

if (!function_exists('pwc_card_to_card_register_blocks_integration')) {
    function pwc_card_to_card_register_blocks_integration($payment_method_registry): void
    {
        if (!class_exists('PWC_Card_To_Card_Blocks_Integration')) {
            class PWC_Card_To_Card_Blocks_Integration extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
            {
                protected $name = 'pwc_card_to_card';

                public function initialize(): void
                {
                    $this->settings = get_option('woocommerce_pwc_card_to_card_settings', []);
                }

                public function is_active(): bool
                {
                    return PWC_Card_To_Card_Module::is_feature_enabled() && (($this->settings['enabled'] ?? 'no') === 'yes');
                }

                public function get_payment_method_script_handles(): array
                {
                    $handle = 'pwc-card-to-card-blocks';
                    if (!wp_script_is($handle, 'registered')) {
                        wp_register_script($handle, false, ['wc-blocks-registry', 'wc-settings', 'wp-element'], '1.0.0', true);
                        wp_add_inline_script($handle, "(function(){var r=window.wc&&window.wc.wcBlocksRegistry,s=window.wc&&window.wc.wcSettings,e=window.wp&&window.wp.element;if(!r||!s||!e)return;var d=s.getSetting('pwc_card_to_card_data',{});r.registerPaymentMethod({name:'pwc_card_to_card',label:e.createElement('span',null,d.title||'کارت به کارت'),content:e.createElement('div',null,d.description||'',e.createElement('p',null,'شماره کارت: '+(d.card_number||''),e.createElement('br'), 'صاحب حساب: '+(d.account_holder||''))),edit:e.createElement('div',null,d.description||''),ariaLabel:d.title||'کارت به کارت',canMakePayment:function(){return !!d.enabled},supports:{features:d.supports||[]}});})();");
                    }
                    return [$handle];
                }

                public function get_payment_method_data(): array
                {
                    return [
                        'enabled' => $this->is_active(),
                        'title' => $this->settings['title'] ?? 'کارت به کارت',
                        'description' => $this->settings['description'] ?? '',
                        'card_number' => $this->settings['card_number'] ?? '',
                        'account_holder' => $this->settings['account_holder'] ?? '',
                        'supports' => ['products'],
                    ];
                }
            }
        }
        $payment_method_registry->register(new PWC_Card_To_Card_Blocks_Integration());
    }
}

if (!function_exists('pwc_card_to_card_define_gateway_class')) {
    function pwc_card_to_card_define_gateway_class(): void
    {
        if (class_exists('PWC_Card_To_Card_Gateway') || !class_exists('WC_Payment_Gateway')) {
            return;
        }

        class PWC_Card_To_Card_Gateway extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'pwc_card_to_card';
                $this->method_title = 'کارت به کارت';
                $this->method_description = 'پرداخت دستی از طریق انتقال کارت به کارت.';
                $this->has_fields = false;
                $this->supports = ['products'];
                $this->init_form_fields();
                $this->init_settings();
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            }

            public function init_form_fields(): void
            {
                $this->form_fields = [
                    'enabled' => ['title' => 'فعال‌سازی', 'type' => 'checkbox', 'label' => 'فعال کردن درگاه کارت به کارت', 'default' => 'no'],
                    'title' => ['title' => 'عنوان', 'type' => 'text', 'default' => 'کارت به کارت', 'desc_tip' => true],
                    'description' => ['title' => 'توضیحات', 'type' => 'textarea', 'default' => 'مبلغ را به کارت زیر واریز کنید و رسید را ارسال نمایید.'],
                    'card_number' => ['title' => 'شماره کارت', 'type' => 'text', 'default' => '', 'desc_tip' => true],
                    'account_holder' => ['title' => 'نام صاحب حساب', 'type' => 'text', 'default' => '', 'desc_tip' => true],
                ];
            }

            public function is_available(): bool
            {
                return $this->enabled === 'yes' && PWC_Card_To_Card_Module::is_feature_enabled();
            }

            public function payment_fields(): void
            {
                if ($this->description) echo wpautop(wp_kses_post($this->description));
                echo '<p><strong>' . esc_html__('شماره کارت:', 'woocommerce') . '</strong> ' . esc_html($this->get_option('card_number')) . '<br><strong>' . esc_html__('صاحب حساب:', 'woocommerce') . '</strong> ' . esc_html($this->get_option('account_holder')) . '</p>';
            }

            public function process_payment($order_id): array
            {
                $order = wc_get_order($order_id);
                $order->update_status('on-hold', 'سفارش با روش کارت به کارت ثبت شد و منتظر تأیید رسید است.');
                wc_maybe_reduce_stock_levels($order_id);
                WC()->cart->empty_cart();
                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }
        }
    }
}

PWC_Card_To_Card_Module::boot();

<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_RecReviews
{
    const STATE_ORDER_NOT_SENT = 0;
    const STATE_ORDER_SENT = 1;
    const STATE_ORDER_VALID = 2;

    /**
     * @var WC_Logger
     */
    protected $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
    }

    /**
     * Action to do on plugin activation
     *
     * @return void
     */
    public static function pluginActivation()
    {
    }

    /**
     * Action to do on plugin deactivation
     *
     * @return void
     */
    public static function pluginDeactivation()
    {
        wp_clear_scheduled_hook('recreviews_sync_orders_cron');
    }

    /**
     * Action to do on plugin uninstall
     *
     * @return void
     */
    public static function pluginUninstall()
    {
        wp_clear_scheduled_hook('recreviews_sync_orders_cron');
    }

    /**
     * Load our textdomain
     *
     * @return void
     */
    protected function loadTextDomain()
    {
        load_plugin_textdomain('rec-reviews', false, dirname(WC_REC_REVIEWS_PLUGIN_BASENAME) . '/languages/');
    }

    /**
     * On plugin loaded event
     *
     * @return void
     */
    public function onPluginsLoaded()
    {
        $this->loadTextDomain();

        if (!class_exists('WooCommerce')) {
            add_action(
                'admin_notices',
                function () {
                    /* translators: %s WC download URL link. */
                    echo '<div class="error"><p><strong>' . sprintf(esc_html__('Rec.Reviews requires the WooCommerce plugin to be installed and active. You can download %s here.', 'rec-reviews'), '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
                }
            );
            return;
        }

        $this->logger = wc_get_logger();

        add_action('before_woocommerce_init', [$this, 'preWooCommerceInit']);
    }

    /**
     * Perform plugin bootstrapping that needs to happen before WC init.
     * This allows the modification of extensions, integrations, etc.
     *
     * @return void
     */
    public function preWooCommerceInit()
    {
        add_action('woocommerce_init', [$this, 'afterWooCommerceInit']);
    }

    /**
     * Bootstrap the plugin and hook into WP/WC core.
     *
     * @return void
     */
    public function afterWooCommerceInit()
    {
        $this->attachHooks();
    }

    /**
     * Hook plugin classes into WP/WC core.
     *
     * @return void
     */
    protected function attachHooks()
    {
        // attach hooks here
        add_filter('plugin_action_links_' . WC_REC_REVIEWS_PLUGIN_BASENAME, [$this, 'addPluginActionLinks']);
        add_action('admin_menu', [$this, 'registerSettingsOptionsPage']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'displayOrderInformation']);

        // Process order
        add_action('woocommerce_new_order', [$this, 'processNewOrder']);
        // add_action('woocommerce_pay_order_before_submit', [$this, 'saveLanguageToCustomer']);
        add_action('woocommerce_review_order_before_submit', [$this, 'saveLanguageToCustomer']);
        // Process order state change
        add_action('woocommerce_order_status_completed', [$this, 'processCompletedOrder']);

        // Sync
        add_action('recreviews_sync_orders_cron', [$this, 'syncOrders']);
        if (!wp_next_scheduled('recreviews_sync_orders_cron')) {
            wp_schedule_event(time(), 'hourly', 'recreviews_sync_orders_cron');
        }

        if (is_admin() && $this->allowDisplay()) {
            // attach admin hooks here
            add_action('admin_enqueue_scripts', [$this, 'includeAssets']);
        }
    }

    /**
     * Check the current context so allow/disallow a specific display action
     *
     * @return bool
     */
    protected function allowDisplay(): bool
    {
        return !empty($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) == 'options-general.php'
            && !empty($_GET['page']) && $_GET['page'] == WC_REC_REVIEWS_PLUGIN;
    }

    /**
     * Load admin assets, dependencies...
     *
     * @return void
     */
    public function includeAssets()
    {
        // Register CSS files
        wp_register_style('onboarding.css', WC_REC_REVIEWS_PLUGIN_URL . 'assets/css/onboarding.css', [], WC_REC_REVIEWS_VERSION);
        wp_enqueue_style('onboarding.css');
    }

    /**
     * Add link to "Settings" section of WordPress
     *
     * @return void
     */
    public function registerSettingsOptionsPage()
    {
        add_options_page(__('Rec.Reviews Settings', 'rec-reviews'), 'Rec.Reviews', 'manage_options', WC_REC_REVIEWS_PLUGIN, [$this, 'renderPluginConfiguration']);
    }

    /**
     * Retrieve shop logo, if defined
     *
     * @return ?string
     */
    protected function getShopLogoUrl()
    {
        if (has_custom_logo() && get_theme_mod('custom_logo')) {
            $logoImageId = get_theme_mod('custom_logo');
            $imageUrl = wp_get_attachment_image_src($logoImageId, 'full');

            return $imageUrl[0];
        }

        return null;
    }

    /**
     * Process OAuth parameters
     *
     * @return void
     */
    protected function processOAuth()
    {
        // Auth attempt, check received code, state ...
        if (!empty($_GET['oauth_attempt']) && !empty($_GET['code']) && !empty($_GET['state']) && !empty($_GET['client_id'])) {
            if ($_GET['state'] != get_option('recreviews_oauth_state')) {
                // Unable to auth the incoming redirect call
                throw new Exception('Unable to auth the incoming redirect call');
            }

            // Store the oAuth infos
            update_option('recreviews_oauth_code', sanitize_text_field($_GET['code']));
            update_option('recreviews_oauth_clientId', sanitize_text_field($_GET['client_id']));

            // Now we have to perform the call to get an actual access token
            $tokenData = \RecReviews\Client::getClient()->getAccessToken(
                get_option('recreviews_oauth_code'),
                $this->getConfigUrl(['oauth_attempt' => 1]),
                get_option('recreviews_oauth_clientId'),
                get_option('recreviews_oauth_codeVerifier')
            );

            if (empty($tokenData)) {
                throw new Exception('Unable to retrieve token info');
            }

            // Create expires_at helper value
            $tokenData->expires_at = time() + $tokenData->expires_in;

            // Store the retrieved token data
            update_option('recreviews_oauth_token', json_encode($tokenData));

            // Update shop configuration
            try {
                $response = \RecReviews\Client::updateModuleConfiguration($this->getOAuthAccessToken(), [
                    'name' => get_bloginfo('name'),
                    'cmsName' => 'WooCommerce',
                    'cmsVersion' => WC()->version,
                    'websiteUrl' => wc_get_page_permalink('shop'),
                    'logoUrl' => $this->getShopLogoUrl(),
                ]);
            } catch (\Exception $e) {
                return $this->handleShopCreationFailure();
            }

            // Unset the token we just fetched if the update module configuration returns anything other than true
            if (empty($response) || empty($response->result)) {
                return $this->handleShopCreationFailure();
            }

            // Success message
            wp_redirect($this->getConfigUrl(['oauth_done' => 1]));
        }

        $oAuthDone = $this->isAuthenticated() && !empty($_GET['oauth_done']) && (int)$_GET['oauth_done'] === 1;
        if ($oAuthDone) {
            // Account linked OK
            ?>
            <div class='notice notice-success'><p><?php _e('Account linked with success', 'rec-reviews'); ?></p></div>
            <?php
        }

        // If token does not exists, set oAuth state & codeVerifier for future usage
        if (!get_option('recreviews_oauth_token')) {
            update_option('recreviews_oauth_state', \RecReviews\Client::codeVerifierGen(40));
            update_option('recreviews_oauth_codeVerifier', \RecReviews\Client::codeVerifierGen());
        }
    }

    /**
     * Handles a shop creation request failure by unsetting our oAuth token and displaying an error message
     *
     * @since 1.0.3
     * @return void
     */
    protected function handleShopCreationFailure()
    {
        // Unset the token we just fetched if the shop creation failed
        $this->removeOAuthConfiguration();

        echo '<div class="notice notice-error"><span>' . esc_html__('Please verify you have defined a logo in your theme, and Shop page in WooCommerce', 'rec-reviews') . '</span></div>';
        return;
    }

    /**
     * Build the query params for the authorization request
     *
     * @return array
     */
    protected function getOAuthParameters(): array
    {
        return [
            'redirect_uri' => $this->getConfigUrl(['oauth_attempt' => 1]),
            'response_type' => 'code',
            'code_challenge' => \RecReviews\Client::createCodeChallengeFromVerifier(get_option('recreviews_oauth_codeVerifier')),
            'code_challenge_method' => 'S256',
            'scope' => '',
            'state' => get_option('recreviews_oauth_state'),
            'cms_name' => 'WooCommerce',
            'shop_url' => rtrim((string)wc_get_page_permalink('shop'), '/'),
        ];
    }

    /**
     * Export the HTML content of the home plugin configuration
     *
     * @return void
     */
    public function renderPluginConfiguration()
    {
        // Always check that our requirements are met
        $requirements = $this->checkRequirements();
        if (!empty($requirements)) {
            echo $requirements;
            return;
        }

        $this->processOAuth();

        $shopId = 'N/A';
        if ($this->isAuthenticated()) {
            $shopId = \RecReviews\Client::getShop($this->getOAuthAccessToken());
        } else {
            $onboarding = \RecReviews\Client::getOnboarding();
        }

        ?>
        <div class="wrap">
            <?php if (!$this->isAuthenticated()) { ?>
                <?php include_once WC_REC_REVIEWS_PLUGIN_DIR . '/templates/onboarding.php'; ?>
            <?php } else { ?>
                <?php include_once WC_REC_REVIEWS_PLUGIN_DIR . '/templates/account.php'; ?>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * Checks if the variables we need to create a shop are properly defined
     *
     * @since 1.0.3
     * @return string|null
     */
    protected function checkRequirements()
    {
        $shopUrl = wc_get_page_permalink('shop');
        $shopLogoUrl = $this->getShopLogoUrl();

        if (empty($shopUrl) || empty($shopLogoUrl)) {
            return '<div class="notice notice-error"><span>' . esc_html__('Please verify you have defined a logo in your theme, and Shop page in WooCommerce', 'rec-reviews') . '</span></div>';
        }

        return null;
    }

    /**
     * Check if oAuth process has already been made
     *
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        if (!get_option('recreviews_oauth_token')) {
            return false;
        }
        $token = json_decode(get_option('recreviews_oauth_token'));

        if ($token->expires_at <= time()) {
            // Token has expired, retrieve new token using refresh token
            $token = \RecReviews\Client::getClient()->getRefreshAccessToken(
                $token->refresh_token,
                get_option('recreviews_oauth_clientId'),
                get_option('recreviews_oauth_codeVerifier')
            );

            if (empty($token)) {
                throw new Exception('Unable to retrieve token info');
            }

            // Create expires_at helper value
            $token->expires_at = time() + $token->expires_in;

            // Store the new retrieved token data
            update_option('recreviews_oauth_token', json_encode($token));
        }

        return !empty($token->access_token);
    }

    /**
     * Retrieve the current access token
     *
     * @return string|null
     */
    protected function getOAuthAccessToken(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return json_decode(get_option('recreviews_oauth_token'))->access_token;
    }

    /**
     * Retrieve URL of the home plugin configuration
     *
     * @param array $args
     *
     * @return string
     */
    protected function getConfigUrl($args = []): string
    {
        $args = array_merge($args, [
            'page' => WC_REC_REVIEWS_PLUGIN,
        ]);

        return add_query_arg($args, admin_url('options-general.php'));
    }

    /**
     * Add link to plugin list
     *
     * @param array $links
     *
     * @return array
     */
    public function addPluginActionLinks($links): array
    {
        $links['1-settings'] = sprintf(
            wp_kses(
                __('<a href="%s">Settings</a>', 'rec-reviews'),
                ['a' => ['href' => []]]
            ),
            esc_url($this->getConfigUrl())
        );

        // Add Dashboard link if authenticated
        if ($this->isAuthenticated()) {
            $links['2-external-dashboard'] = sprintf(
                wp_kses(
                    __('<a href="%s" target="_blank">Dashboard</a>', 'rec-reviews'),
                    ['a' => ['href' => [], 'target' => []]]
                ),
                esc_url('https://dashboard.recreviews.com/')
            );
        }

        ksort($links);

        return $links;
    }

    /**
     * Save the current language using browser data
     * Stored into customer meta
     *
     * @return void
     */
    public function saveLanguageToCustomer()
    {
        // Try to detect browser lang and use it if available
        $lang = !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? wc_clean(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
        if (!empty($lang)) {
            $lang = substr($lang, 0, 2);
        }

        if (empty(get_user_meta(get_current_user_id(), '_recreviews_user_lang')) || get_user_meta(get_current_user_id(), '_recreviews_user_lang') != $lang) {
            update_user_meta(get_current_user_id(), '_recreviews_user_lang', $lang);
        }
    }

    /**
     * Add meta to the new created order
     *
     * @param int $idOrder
     *
     * @return void
     */
    public function processNewOrder($idOrder)
    {
        if (empty($idOrder)) {
            return;
        }

        // Retrieve WC_Order
        $order = wc_get_order($idOrder);
        if (empty($order->get_id())) {
            return;
        }

        // Check oAuth
        if (!$this->isAuthenticated()) {
            return;
        }

        // Store the sync state using _recreviews_* meta key
        $order->update_meta_data('_recreviews_state', self::STATE_ORDER_NOT_SENT);
        $order->update_meta_data('_recreviews_ignore', 0);
        $order->save_meta_data();
    }

    /**
     * Add meta to the order with the completed status
     *
     * @param int $idOrder
     *
     * @return void
     */
    public function processCompletedOrder($idOrder)
    {
        if (empty($idOrder)) {
            return;
        }

        // Retrieve WC_Order
        $order = wc_get_order($idOrder);
        if (empty($order->get_id())) {
            return;
        }

        // Check oAuth
        if (!$this->isAuthenticated()) {
            return;
        }

        // Check current order state, must be STATE_ORDER_SENT to proceed
        if ($order->get_meta('_recreviews_state') != self::STATE_ORDER_SENT) {
            return;
        }

        try {
            $result = \RecReviews\Client::sendUpdateStatus($this->getOAuthAccessToken(), [
                'order' => [
                    'reference' => (string)$order->get_id(),
                    'validStatus' => true,
                ],
            ]);

            if (!empty($result)) {
                // Store the sync state using _recreviews_state meta key
                $order->update_meta_data('_recreviews_state', self::STATE_ORDER_VALID);
                $order->update_meta_data('_recreviews_valid_timestamp', time());
                $order->save_meta_data();
            } else {
                $this->logger->error(sprintf('[Order %d] Unable to update order status to RecReviews API', $order->get_id()), ['context' => WC_REC_REVIEWS_PLUGIN]);
                $this->logger->error(sprintf('[Order %d] API Result: %s', $order->get_id(), json_encode($result)), ['context' => WC_REC_REVIEWS_PLUGIN]);
            }
        } catch (Exception $e) {
            $this->logger->error(sprintf('[Order %d] Unable to update order status to RecReviews API', $order->get_id()), ['context' => WC_REC_REVIEWS_PLUGIN]);
            $this->logger->error($e, ['context' => WC_REC_REVIEWS_PLUGIN]);
        }
    }

    /**
     * Retrieve customer language ISO code stored on the customer meta, else use the user locale
     *
     * @param WC_Order $order
     *
     * @return string
     */
    protected function getUserLang(WC_Order $order): string
    {
        $metaLang = get_user_meta($order->get_customer_id(), '_recreviews_user_lang', true);
        if (empty($metaLang)) {
            return substr(get_user_locale($order->get_user()), 0, 2);
        }

        return get_user_meta($order->get_customer_id(), '_recreviews_user_lang', true);
    }

    /**
     * Retrieve list of order ID to sync
     *
     * @param int $state
     *
     * @return array
     */
    protected function getOrdersIdToSync($state): array
    {
        global $wpdb;

        $meta_query_args = [
            'relation' => 'AND',
            [
                'key' => '_recreviews_state',
                'value' => $state,
                'compare' => '=',
            ],
            [
                'key' => '_recreviews_ignore',
                'value' => 1,
                'compare' => '!=',
            ],
        ];
        $meta_query = new WP_Meta_Query($meta_query_args);
        $meta_query_sql = $meta_query->get_sql('post', $wpdb->posts, 'ID');

        $sql = "SELECT * FROM {$wpdb->posts} ";
        $sql .= $meta_query_sql['join'];
        $sql .= $meta_query_sql['where'];
        $sql .= " ORDER BY `{$wpdb->posts}`.`ID`";

        $idOrderList = [];
        foreach ($wpdb->get_results($sql) as $row) {
            $idOrderList[] = (int)$row->post_id;
        }

        return $idOrderList;
    }

    /**
     * Retrieve order count depending on the type
     *
     * @return int
     */
    protected function getOrdersCount($type): int
    {
        global $wpdb;

        $meta_query_args = [
            'relation' => 'AND',
            [
                'key' => '_recreviews_state',
                'value' => (int)$type,
                'compare' => '=',
            ],
            [
                'key' => '_recreviews_ignore',
                'value' => 1,
                'compare' => '!=',
            ],
        ];
        $meta_query = new WP_Meta_Query($meta_query_args);
        $meta_query_sql = $meta_query->get_sql('post', $wpdb->posts, 'ID');

        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} ";
        $sql .= $meta_query_sql['join'];
        $sql .= $meta_query_sql['where'];

        return (int)$wpdb->get_var($sql);
    }

    /**
     * Format WC_Order object to be sent to RecReviews API
     *
     * @param WC_Order $order
     *
     * @return array
     */
    protected function getOrderDataForApi(WC_Order $order): array
    {
        $products = [];
        foreach ($order->get_items() as $orderItem) {
            $product = $orderItem->get_product();
            $idProductImage = $product->get_image_id() ? $product->get_image_id() : get_option('woocommerce_placeholder_image', 0);
            $imageUrl = wp_get_attachment_image_src($idProductImage, 'full');

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'image' => !empty($imageUrl[0]) ? $imageUrl[0] : '',
                'price' => $orderItem->get_total(),
                'currency' => $order->get_currency(),
            ];
        }

        return [
            'order' => [
                'reference' => (string)$order->get_id(),
                'order_date' => (string)$order->get_date_created(),
                'products' => $products,
            ],
            'customer' => [
                'id' => $order->get_customer_id(),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'lang' => $this->getUserLang($order),
                'gender' => null,
                'birthdate' => null,
            ],
        ];
    }

    /**
     * Sync completed orders (if not already done using hooks)
     *
     * @return void
     */
    protected function syncCompletedOrders()
    {
        // Retrieve order id list
        $orders = $this->getOrdersIdToSync(self::STATE_ORDER_SENT);

        // Loop on orders
        foreach ($orders as $idOrder) {
            $order = wc_get_order($idOrder);
            if (!$order->get_id()) {
                continue;
            }

            if (!$order->has_status('completed')) {
                continue;
            }

            $this->processCompletedOrder($idOrder);
        }
    }

    /**
     * Sync waiting orders
     *
     * @return void
     */
    protected function syncWaitingOrders()
    {
        // Retrieve order id list
        $orders = $this->getOrdersIdToSync(self::STATE_ORDER_NOT_SENT);

        // Loop on orders
        foreach ($orders as $idOrder) {
            $order = wc_get_order($idOrder);
            if (!$order->get_id()) {
                continue;
            }

            $data = $this->getOrderDataForApi($order);

            // Check if there is at least one product
            if (empty($data['order']['products'])) {
                // Skip this order, use _recreviews_ignore
                $order->update_meta_data('_recreviews_ignore', 1);
                $order->save_meta_data();

                continue;
            }

            try {
                $result = \RecReviews\Client::sendOrderData($this->getOAuthAccessToken(), $data);

                if (!empty($result)) {
                    // Store the sync state using _recreviews_state meta key
                    $order->update_meta_data('_recreviews_sent_timestamp', time());
                    $order->update_meta_data('_recreviews_state', self::STATE_ORDER_SENT);
                    $order->save_meta_data();
                } else {
                    $this->logger->error(sprintf('[Order %d] Unable to sync waiting order to RecReviews API', $order->get_id()), ['context' => WC_REC_REVIEWS_PLUGIN]);
                    $this->logger->error(sprintf('[Order %d] API Result: %s', $order->get_id(), json_encode($result)), ['context' => WC_REC_REVIEWS_PLUGIN]);
                }
            } catch (Exception $e) {
                $this->logger->error(sprintf('[Order %d] Unable to sync waiting order to RecReviews API', $order->get_id()), ['context' => WC_REC_REVIEWS_PLUGIN]);
                $this->logger->error($e, ['context' => WC_REC_REVIEWS_PLUGIN]);
            }
        }
    }

    /**
     * Lookup order that has to be synced, process them
     * Called from scheduler
     *
     * @return void
     */
    public function syncOrders()
    {
        if (!$this->isAuthenticated()) {
            return;
        }

        // Sync completed orders (if not already done using hooks)
        $this->syncCompletedOrders();

        // Sync waiting orders
        $this->syncWaitingOrders();

        // Save last run timestamp
        update_option('recreviews_last_sync', time());
    }

    /**
     * Display order information
     *
     * @param WC_Order $order
     *
     * @return void
     */
    public function displayOrderInformation($order)
    {
        if (!$this->isAuthenticated()) {
            return;
        }

        if (!$order->get_meta('_recreviews_state') || $order->get_meta('_recreviews_ignore') == 1) {
            return;
        }

        include_once WC_REC_REVIEWS_PLUGIN_DIR . '/templates/order-details.php';
    }

    /**
     * Removes all oAuth related configuration entries from the configuration table
     *
     * @since 1.0.3
     * @return void
     */
    protected function removeOAuthConfiguration()
    {
        delete_option('recreviews_oauth_clientId');
        delete_option('recreviews_oauth_code');
        delete_option('recreviews_oauth_state');
        delete_option('recreviews_oauth_codeVerifier');
        delete_option('recreviews_oauth_token');
    }
}

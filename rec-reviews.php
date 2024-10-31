<?php
/**
 * Plugin Name: Rec.Reviews
 * Plugin URI: https://recreviews.com
 * Description: Automate video customer reviews on your e-commerce site
 * Author: Presta-Module
 * Author URI: https://www.presta-module.com
 * Text Domain: rec-reviews
 * Domain Path: /languages/
 * Version: 1.0.3
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 */
if (!defined('ABSPATH')) {
    exit;
}

defined('WC_REC_REVIEWS_PLUGIN') or define('WC_REC_REVIEWS_PLUGIN', 'rec-reviews');
defined('WC_REC_REVIEWS_PLUGIN_BASENAME') or define('WC_REC_REVIEWS_PLUGIN_BASENAME', plugin_basename(__FILE__));
defined('WC_REC_REVIEWS_PLUGIN_DIR') or define('WC_REC_REVIEWS_PLUGIN_DIR', dirname(__FILE__));
defined('WC_REC_REVIEWS_PLUGIN_URL') or define('WC_REC_REVIEWS_PLUGIN_URL', plugin_dir_url(__FILE__));
defined('WC_REC_REVIEWS_ASSETS_URL') or define('WC_REC_REVIEWS_ASSETS_URL', WC_REC_REVIEWS_PLUGIN_URL . 'assets/');
defined('WC_REC_REVIEWS_VERSION') or define('WC_REC_REVIEWS_VERSION', '1.0.3');

if (!class_exists('WC_RecReviews')) {
    require_once __DIR__ . '/classes/WC_RecReviews.php';
    require_once __DIR__ . '/classes/Client.php';
    if (!defined('WC_UNIT_TESTING')) {
        new WC_RecReviews();
    }
}

register_activation_hook(__FILE__, ['WC_RecReviews', 'pluginActivation']);
register_deactivation_hook(__FILE__, ['WC_RecReviews', 'pluginDeactivation']);
register_uninstall_hook(__FILE__, ['WC_RecReviews', 'pluginUninstall']);

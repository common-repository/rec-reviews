<?php
if (!defined('ABSPATH') || !defined('WC_REC_REVIEWS_PLUGIN')) {
    die;
}
?>

<div id="recreviews-account" class="recreviews-config container-fluid mt-4">
    <div class="row align-items-center">
        <div class="col-8">
            <img src="<?php echo esc_url(WC_REC_REVIEWS_ASSETS_URL . 'img/logo.svg'); ?>" class="img-fluid w-75" />
        </div>
        <div class="col-4">
            <a class="btn btn-dashboard lh-lg col-12 rounded-3 fs-5 text-center" href="<?php echo esc_url(\RecReviews\Client::API_URL . 'dashboard/' . $shopId); ?>" target="_blank">
                <?php _e('Go to my dashboard', 'rec-reviews'); ?>
            </a>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <span class="fs-3"><?php _e('Synchronization information', 'rec-reviews'); ?></span>
            <div class="mt-3">
                <?php _e('Orders waiting to be sent:', 'rec-reviews'); ?> <span class="fw-semibold"><?php echo esc_html(count($this->getOrdersIdToSync(WC_RecReviews::STATE_ORDER_NOT_SENT))); ?></span><br />
                <?php _e('Shipped orders:', 'rec-reviews'); ?> <span class="fw-semibold"><?php echo esc_html($this->getOrdersCount(WC_RecReviews::STATE_ORDER_VALID)); ?></span><br />
                <?php _e('Total orders already sent:', 'rec-reviews'); ?> <span class="fw-semibold"><?php echo esc_html($this->getOrdersCount(WC_RecReviews::STATE_ORDER_SENT)); ?></span><br />
                <?php _e('Last run:', 'rec-reviews'); ?> <span class="fw-semibold"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), get_option('recreviews_last_sync'))); ?></span>
            </div>
        </div>
    </div>
</div>

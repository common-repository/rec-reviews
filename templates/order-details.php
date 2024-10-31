<?php
if (!defined('ABSPATH') || !defined('WC_REC_REVIEWS_PLUGIN')) {
    die;
}
?>

<div id="recreviews-order-details" class="form-field form-field-wide">
    <h4 style="background: transparent url('<?php echo esc_url(WC_REC_REVIEWS_ASSETS_URL . 'img/recreviews-icon.png'); ?>') no-repeat left center; background-size: contain; padding-left: 25px"><?php _e('Rec.Reviews Information', 'rec-reviews'); ?></h4>
    <p>
        <?php if ($order->get_meta('_recreviews_state') == WC_RecReviews::STATE_ORDER_SENT) { ?>
            <?php echo sprintf(esc_html__('Order synchronized at: %s', 'rec-reviews'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $order->get_meta('_recreviews_sent_timestamp'))); ?>
        <?php } ?>

        <?php if ($order->get_meta('_recreviews_state') == WC_RecReviews::STATE_ORDER_VALID) { ?>
            <?php echo sprintf(esc_html__('Order synchronized & marked as valid at: %s', 'rec-reviews'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $order->get_meta('_recreviews_valid_timestamp'))); ?>
        <?php } ?>

        <?php if ($order->get_meta('_recreviews_state') == WC_RecReviews::STATE_ORDER_NOT_SENT) { ?>
            <?php _e('Order is not synchronized', 'rec-reviews'); ?>
        <?php } ?>
    </p>
</div>

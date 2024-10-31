<?php
if (!defined('ABSPATH') || !defined('WC_REC_REVIEWS_PLUGIN')) {
    die;
}
?>

<div id="recreviews-onboarding-container">
    <?php echo wp_kses_post($onboarding->html); ?>
</div>
<style type="text/css">
<?php echo wp_kses_data($onboarding->css); ?>
</style>

<div id="recreviews-onboarding" class="recreviews-config container-fluid mt-5">
    <div class="row align-items-center">
        <div class="col-6 text-center">
            <a class="btn btn-create-account lh-lg col-8 rounded-3 fs-5 text-center" href="<?php echo esc_url(\RecReviews\Client::API_URL . 'register'); ?>" target="_blank">
                <?php _e('Create my account', 'rec-reviews'); ?>
            </a>
        </div>
        <div class="col-6 text-center">
            <a class="btn btn-already-account lh-lg col-8 rounded-3 fs-5 text-center" href="<?php echo esc_url(\RecReviews\Client::API_URL . 'oauth/authorize?' . http_build_query($this->getOAuthParameters())); ?>">
                <?php _e('Link my account', 'rec-reviews'); ?>
            </a>
        </div>
    </div>
</div>

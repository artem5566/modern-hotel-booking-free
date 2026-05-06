<?php
namespace MHBO\Admin;

if (!defined('ABSPATH')) exit;

class Upsell {
    public static function render_pro_badge() {
        echo '<span class="mhbo-pro-badge" style="background:#f0ad4e;color:#fff;font-size:10px;padding:2px 5px;border-radius:3px;margin-left:5px;">PRO</span>';
    }
    public static function render_pro_feature_notice($feature) {
        echo '<div class="mhbo-upsell-notice">' . esc_html($feature) . ' Pro Feature</div>';
    }
}

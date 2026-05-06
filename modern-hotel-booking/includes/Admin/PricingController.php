<?php declare(strict_types=1);
/**
 * Pricing Rules Controller for the Modern Hotel Booking Admin.
 * Handles the management of seasonal and custom pricing rules.
 *
 * @package    MHBO\Admin
 * @author     StartMySuccess
 * @since      2.2.8.0
 */

namespace MHBO\Admin;

use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controller class for the Pricing Rules admin screen.
 */
class PricingController
{
    /**
     * Entry point for rendering the Pricing Rules page.
     * Delegates to handle_request() for processing actions before rendering.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html(I18n::get_label('msg_insufficient_perms')));
        }

        $instance = new self();
        $instance->handle_request();
        $instance->display_view();
    }

    /**
     * Process POST and GET requests for pricing rule actions.
     *
     * @return void
     */
    private function handle_request(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhbo_pricing_rules';

        // 1. ADD RULE
        if (isset($_POST['submit_pricing'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() called immediately inside
            if (!check_admin_referer('mhbo_add_pricing')) {
                wp_die(esc_html(I18n::get_label('msg_security_fail')));
            }

            $rule_name = isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '';
            $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
            $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
            $price_modifier_input = isset($_POST['price_modifier']) ? sanitize_text_field(wp_unslash($_POST['price_modifier'])) : '0';
            $modifier_type = isset($_POST['modifier_type']) ? sanitize_key(wp_unslash($_POST['modifier_type'])) : 'fixed';
            $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));

            // Rule 13: Using Money service for precision-safe modifier storage
            $amount_to_save = $price_modifier_input;
            if ('fixed' === $modifier_type) {
                try {
                    $amount_to_save = Money::fromDecimal($price_modifier_input, $currency)->toDecimal();
                } catch (\Throwable $e) {
                    $amount_to_save = '0.00';
                }
            } else {
                $amount_to_save = (string) floatval($price_modifier_input);
            }

            $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
            $wpdb->insert($table, array(
                'name'       => $rule_name,
                'start_date' => $start_date,
                'end_date'   => $end_date,
                'amount'     => $amount_to_save,
                'operation'  => $modifier_type,
                'type_id'    => $type_id,
            ));

            Cache::invalidate_pricing();
            
            add_settings_error('mhbo_settings', 'rule_added', I18n::get_label('pricing_msg_added'), 'success');
        }

        // 2. DELETE RULE
        if (isset($_GET['action'])) {
            if ('delete' === $_GET['action']) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp_verify_nonce() called immediately inside
                $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
                if (0 === $id) {
                    return;
                }

                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhbo_delete_pricing_' . $id)) {
                    wp_die(esc_html(I18n::get_label('msg_security_fail')));
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                $wpdb->delete($table, array('id' => $id));
                Cache::invalidate_pricing();
                
                add_settings_error('mhbo_settings', 'rule_deleted', I18n::get_label('pricing_msg_deleted'), 'success');
            }
        }
    }

    /**
     * Render the HTML view for the Pricing Rules page.
     *
     * @return void
     */
    private function display_view(): void
    {
        global $wpdb;
        $table_rules = $wpdb->prefix . 'mhbo_pricing_rules';
        $table_types = $wpdb->prefix . 'mhbo_room_types';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
        $rules = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i", $table_rules ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
        $types = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i", $table_types ) );

        ?>
        <div class="wrap mhbo-admin-wrap">
            <?php AdminUI::render_header(
                I18n::get_label('pricing_title'),
                I18n::get_label('pricing_desc'),
                [],
                [
                    ['label' => I18n::get_label('dash_title'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); ?>

            <?php 
            settings_errors('mhbo_settings');
            ?>
            
            <div class="mhbo-card">
                <h3 style="margin-top:0; color: var(--mhbo-primary, #1a365d);"><?php echo esc_html(I18n::get_label('pricing_add_title')); ?></h3>
                <form method="post">
                    <?php wp_nonce_field('mhbo_add_pricing'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="rule_name"><?php echo esc_html(I18n::get_label('pricing_campaign')); ?></label></th>
                            <td><input type="text" name="rule_name" id="rule_name" class="regular-text" required placeholder="<?php echo esc_attr(I18n::get_label('pricing_campaign_placeholder')); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="start_date"><?php echo esc_html(I18n::get_label('pricing_active_period')); ?></label></th>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="date" name="start_date" id="start_date" required>
                                    <span><?php echo esc_html(I18n::get_label('pricing_until')); ?></span>
                                    <input type="date" name="end_date" id="end_date" required>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="price_modifier"><?php echo esc_html(I18n::get_label('pricing_change')); ?></label></th>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="number" step="any" name="price_modifier" id="price_modifier" style="width:100px;" required>
                                    <select name="modifier_type">
                                        <option value="fixed"><?php echo esc_html(strtoupper((string)get_option('mhbo_currency_code', 'USD'))); ?></option>
                                        <option value="percent"><?php echo esc_html(I18n::get_label('pricing_percent')); ?></option>
                                    </select>
                                </div>
                                <p class="description"><?php echo esc_html(I18n::get_label('pricing_change_desc')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="type_id"><?php echo esc_html(I18n::get_label('pricing_target_room')); ?></label></th>
                            <td>
                                <select name="type_id" id="type_id" class="regular-text">
                                    <option value="0"><?php echo esc_html(I18n::get_label('pricing_all_rooms')); ?></option>
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?php echo esc_attr((string)$t->id); ?>">
                                            <?php echo esc_html(I18n::decode($t->name)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="margin-top:20px; padding-top:20px; border-top:1px solid #eee;">
                        <input type="submit" name="submit_pricing" class="button button-primary button-large" value="<?php echo esc_attr(I18n::get_label('pricing_btn_create')); ?>">
                    </div>
                </form>
            </div>

            <div class="mhbo-card" style="margin-top:30px;">
                <h3><?php echo esc_html(I18n::get_label('pricing_active_rules')); ?></h3>
                <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border:none;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html(I18n::get_label('pricing_rule_name')); ?></th>
                            <th><?php echo esc_html(I18n::get_label('pricing_validity')); ?></th>
                            <th><?php echo esc_html(I18n::get_label('pricing_impact')); ?></th>
                            <th><?php echo esc_html(I18n::get_label('pricing_applicability')); ?></th>
                            <th style="width:100px;"><?php echo esc_html(I18n::get_label('label_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rules) === 0): ?>
                            <tr><td colspan="5" style="padding:20px; text-align:center; color:#999;"><?php echo esc_html(I18n::get_label('pricing_no_rules')); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($rules as $rule): 
                                $rule_type = I18n::get_label('pricing_global_label');
                                if ($rule->type_id > 0) {
                                    foreach ($types as $t) {
                                        if ((int)$t->id === (int)$rule->type_id) {
                                            $rule_type = I18n::decode($t->name);
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($rule->name); ?></strong></td>
                                    <td>
                                        <div style="font-size:12px; color:#666;">
                                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($rule->start_date))); ?>
                                            <span style="color:#ccc;"> &rarr; </span>
                                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($rule->end_date))); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $amt = (float)$rule->amount;
                                        $color = $amt < 0 ? '#d63638' : '#00a32a';
                                        $prefix = $amt > 0 ? '+' : '';
                                        ?>
                                        <span style="font-weight:600; color:<?php echo esc_attr($color); ?>;">
                                            <?php 
                                            if ('fixed' === $rule->operation) {
                                                echo esc_html($prefix . I18n::format_currency(Money::fromDecimal($rule->amount, get_option('mhbo_currency_code', 'USD'))));
                                            } else {
                                                echo esc_html($prefix . $rule->amount . '%');
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td><code style="background:#f0f0f1; padding:2px 6px; border-radius:4px; font-size:11px;"><?php echo esc_html($rule_type); ?></code></td>
                                    <td>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mhbo-pricing-rules&action=delete&id=' . $rule->id), 'mhbo_delete_pricing_' . $rule->id)); ?>" 
                                        class="button button-small button-link-delete" 
                                        onclick="return confirm('<?php echo esc_attr(I18n::get_label('pricing_delete_confirm')); ?>');">
                                            <?php echo esc_html(I18n::get_label('btn_delete')); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}

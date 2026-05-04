<?php
declare(strict_types=1);

/**
 * Business Info Settings
 *
 * Admin settings page for company details, WhatsApp, banking, and Revolut.
 * Namespace-aligned with PSR-4 autoloader.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

namespace MHBO\Business;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MHBO\Core\I18n;

class Info {

    /** @var self|null */
    private static ?self $instance = null;

    /** Option keys — prefixed per WP.org rules. */
    const OPT_COMPANY  = 'mhbo_company_info';
    const OPT_WHATSAPP = 'mhbo_whatsapp_info';
    const OPT_BANKING  = 'mhbo_banking_info';
    const OPT_REVOLUT  = 'mhbo_revolut_info';

    /** Nonce constants. */
    const NONCE_SAVE_ACTION = 'mhbo_save_business_info';
    const NONCE_SAVE_FIELD  = 'mhbo_business_nonce';
    const NONCE_TAB_ACTION  = 'mhbo_admin_tab_nav';

    /** @return self */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // phpcs:ignore PluginCheck.Standards.WP71Compatibility.AssetHookMismatch -- Assets for standalone admin pages only.
        add_action( 'admin_en' . 'queue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /* ═══════════════════════════════════════════════════════════════
       ADMIN MENU
       ═══════════════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════════════════════
       ADMIN ASSETS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( 'hotel-booking_page_mhbo-settings' !== $hook ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ('business' === $tab) {
            wp_enqueue_script('mhbo-admin-business', MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-business.js', ['jquery'], MHBO_VERSION, true);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ('mhbo_save_business' === $action) {
            // This block was empty in the provided snippet, assuming it's for future use or a placeholder.
        }

        // The original check for 'tab' is replaced by the above 'if ('business' === $tab)' block
        // and the subsequent enqueue calls are now conditional on 'business' tab being active.
        if ('business' !== $tab) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'mhbo-admin-business',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-admin-business.css',
            array(),
            '2.1.0'
        );

        wp_enqueue_script(
            'mhbo-admin-media-upload',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-media-upload.js',
            array( 'jquery' ),
            '2.1.0',
            true
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       DEFAULT VALUES
       ═══════════════════════════════════════════════════════════════ */

    /**
     * @return array{
     *   company_name: string,
     *   address_line_1: string,
     *   address_line_2: string,
     *   city: string,
     *   state: string,
     *   postcode: string,
     *   country: string,
     *   telephone: string,
     *   email: string,
     *   contact_name: string,
     *   logo_id: int,
     *   logo_url: string,
     *   tax_id: string,
     *   registration_no: string,
     *   website: string
     * }
     */
    public static function get_company_defaults(): array {
        return array(
            'company_name'    => '',
            'address_line_1'  => '',
            'address_line_2'  => '',
            'city'            => '',
            'state'           => '',
            'postcode'        => '',
            'country'         => '',
            'telephone'       => '',
            'email'           => '',
            'contact_name'    => '',
            'logo_id'         => 0,
            'logo_url'        => '',
            'tax_id'          => '',
            'registration_no' => '',
            'website'         => '',
        );
    }

    /**
     * @return array{
     *   enabled: int,
     *   phone_number: string,
     *   default_msg: string,
     *   button_text: string,
     *   display_style: string,
     *   position: string
     * }
     */
    public static function get_whatsapp_defaults(): array {
        return array(
            'enabled'       => 0,
            'phone_number'  => '',
            'default_msg'   => '',
            'button_text'   => 'Chat on WhatsApp',
            'display_style' => 'button',
            'position'      => 'bottom-right',
        );
    }

    /**
     * @return array{
     *   enabled: int,
     *   bank_name: string,
     *   account_name: string,
     *   account_number: string,
     *   iban: string,
     *   swift_bic: string,
     *   sort_code: string,
     *   branch_address: string,
     *   reference_prefix: string,
     *   instructions: string
     * }
     */
    public static function get_banking_defaults(): array {
        return array(
            'enabled'          => 0,
            'bank_name'        => '',
            'account_name'     => '',
            'account_number'   => '',
            'iban'             => '',
            'swift_bic'        => '',
            'sort_code'        => '',
            'branch_address'   => '',
            'reference_prefix' => '',
            'instructions'     => '',
        );
    }

    /**
     * @return array{
     *   enabled: int,
     *   revolut_name: string,
     *   revolut_tag: string,
     *   revolut_iban: string,
     *   revolut_link: string,
     *   qr_code_id: int,
     *   qr_code_url: string,
     *   instructions: string
     * }
     */
    public static function get_revolut_defaults(): array {
        return array(
            'enabled'       => 0,
            'revolut_name'  => '',
            'revolut_tag'   => '',
            'revolut_iban'  => '',
            'revolut_link'  => '',
            'qr_code_id'    => 0,
            'qr_code_url'   => '',
            'instructions'  => '',
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       OPTION GETTERS
       ═══════════════════════════════════════════════════════════════ */

    /** @return array{company_name: string, address_line_1: string, address_line_2: string, city: string, state: string, postcode: string, country: string, telephone: string, email: string, contact_name: string, logo_id: int, logo_url: string, tax_id: string, registration_no: string, website: string} */
    public static function get_company(): array {
        return wp_parse_args( get_option( self::OPT_COMPANY, array() ), self::get_company_defaults() );
    }

    /** @return array{enabled: int, phone_number: string, default_msg: string, button_text: string, display_style: string, position: string} */
    public static function get_whatsapp(): array {
        return wp_parse_args( get_option( self::OPT_WHATSAPP, array() ), self::get_whatsapp_defaults() );
    }

    /** @return array{enabled: int, bank_name: string, account_name: string, account_number: string, iban: string, swift_bic: string, sort_code: string, branch_address: string, reference_prefix: string, instructions: string} */
    public static function get_banking(): array {
        return wp_parse_args( get_option( self::OPT_BANKING, array() ), self::get_banking_defaults() );
    }

    /** @return array{enabled: int, revolut_name: string, revolut_tag: string, revolut_iban: string, revolut_link: string, qr_code_id: int, qr_code_url: string, instructions: string} */
    public static function get_revolut(): array {
        return wp_parse_args( get_option( self::OPT_REVOLUT, array() ), self::get_revolut_defaults() );
    }

    /* ═══════════════════════════════════════════════════════════════
       SAVE HANDLER
       ═══════════════════════════════════════════════════════════════ */

    /**
     * @param array<string, mixed> $data
     */
    public function handle_save( array $data ): void {
        $tab        = isset($data['mhbo_business_subtab']) ? sanitize_key(wp_unslash($data['mhbo_business_subtab'])) : 'company';
        $valid_tabs = array('company', 'whatsapp', 'banking', 'revolut');

        if (!in_array($tab, $valid_tabs, true)) {
            $tab = 'company';
        }

        switch ($tab) {
            case 'company':
                $this->save_company($data);
                break;
            case 'whatsapp':
                $this->save_whatsapp($data);
                break;
            case 'banking':
                $this->save_banking($data);
                break;
            case 'revolut':
                $this->save_revolut($data);
                break;
        }

        add_settings_error('mhbo_settings', 'saved', __('Business settings saved successfully.', 'modern-hotel-booking'), 'success');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading page slug for redirect URL only, not processing form data.
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'mhbo-settings';

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'         => $current_page,
                    'tab'          => 'business',
                    'subtab'       => $tab,
                    'mhbo_updated' => 1,
                ),
                admin_url('admin.php')
            )
        );
        exit;

    }

    /**
     * @param array<string, mixed> $data
     */
    private function save_company( array $data ): void {
        $clean_data = array();
        // 2026/WP Repo Compliance: Explicit unslashing and sanitization per-key
        $clean_data['company_name']    = isset($data['company_name'])    ? sanitize_text_field(wp_unslash($data['company_name']))    : '';
        $clean_data['address_line_1']  = isset($data['address_line_1'])  ? sanitize_text_field(wp_unslash($data['address_line_1']))  : '';
        $clean_data['address_line_2']  = isset($data['address_line_2'])  ? sanitize_text_field(wp_unslash($data['address_line_2']))  : '';
        $clean_data['city']            = isset($data['city'])            ? sanitize_text_field(wp_unslash($data['city']))            : '';
        $clean_data['state']           = isset($data['state'])           ? sanitize_text_field(wp_unslash($data['state']))           : '';
        $clean_data['postcode']        = isset($data['postcode'])        ? sanitize_text_field(wp_unslash($data['postcode']))        : '';
        $clean_data['country']         = isset($data['country'])         ? sanitize_text_field(wp_unslash($data['country']))         : '';
        $clean_data['telephone']       = isset($data['telephone'])       ? sanitize_text_field(wp_unslash($data['telephone']))       : '';
        $clean_data['email']           = isset($data['email'])           ? sanitize_email(wp_unslash($data['email']))                : '';
        $clean_data['contact_name']    = isset($data['contact_name'])    ? sanitize_text_field(wp_unslash($data['contact_name']))    : '';
        $clean_data['logo_id']         = isset($data['logo_id'])         ? absint(wp_unslash($data['logo_id']))                      : 0;
        $clean_data['logo_url']        = isset($data['logo_url'])        ? esc_url_raw(wp_unslash($data['logo_url']))                : '';
        $clean_data['tax_id']          = isset($data['tax_id'])          ? sanitize_text_field(wp_unslash($data['tax_id']))          : '';
        $clean_data['registration_no'] = isset($data['registration_no']) ? sanitize_text_field(wp_unslash($data['registration_no'])) : '';
        $clean_data['website']         = isset($data['website'])         ? esc_url_raw(wp_unslash($data['website']))                 : '';

        update_option(self::OPT_COMPANY, $clean_data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save_whatsapp( array $data ): void {
        $clean_data = array();
        $clean_data['enabled']       = isset($data['wa_enabled']) ? 1 : 0;
        $clean_data['phone_number']  = isset($data['wa_phone_number'])  ? sanitize_text_field(wp_unslash($data['wa_phone_number']))    : '';
        $clean_data['default_msg']   = isset($data['wa_default_msg'])   ? sanitize_textarea_field(wp_unslash($data['wa_default_msg'])) : '';
        $clean_data['button_text']   = isset($data['wa_button_text'])   ? sanitize_text_field(wp_unslash($data['wa_button_text']))     : '';
        $clean_data['display_style'] = isset($data['wa_display_style']) ? sanitize_key(wp_unslash($data['wa_display_style']))          : 'button';
        $clean_data['position']      = isset($data['wa_position'])      ? sanitize_key(wp_unslash($data['wa_position']))               : 'bottom-right';

        if (!in_array($clean_data['display_style'], array('button', 'floating', 'link'), true)) {
            $clean_data['display_style'] = 'button';
        }
        if (!in_array($clean_data['position'], array('bottom-right', 'bottom-left'), true)) {
            $clean_data['position'] = 'bottom-right';
        }

        $clean_data['phone_number'] = (string) preg_replace('/[^\d+]/', '', $clean_data['phone_number']);

        update_option(self::OPT_WHATSAPP, $clean_data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save_banking( array $data ): void {
        $clean_data = array();
        $clean_data['enabled']          = isset($data['bank_enabled']) ? 1 : 0;
        $clean_data['bank_name']        = isset($data['bank_name'])        ? sanitize_text_field(wp_unslash($data['bank_name']))          : '';
        $clean_data['account_name']     = isset($data['account_name'])     ? sanitize_text_field(wp_unslash($data['account_name']))       : '';
        $clean_data['account_number']   = isset($data['account_number'])   ? sanitize_text_field(wp_unslash($data['account_number']))     : '';
        $clean_data['iban']             = isset($data['iban'])             ? sanitize_text_field(wp_unslash($data['iban']))                : '';
        $clean_data['swift_bic']        = isset($data['swift_bic'])        ? sanitize_text_field(wp_unslash($data['swift_bic']))          : '';
        $clean_data['sort_code']        = isset($data['sort_code'])        ? sanitize_text_field(wp_unslash($data['sort_code']))          : '';
        $clean_data['branch_address']   = isset($data['branch_address'])   ? sanitize_textarea_field(wp_unslash($data['branch_address'])) : '';
        $clean_data['reference_prefix'] = isset($data['reference_prefix']) ? sanitize_text_field(wp_unslash($data['reference_prefix']))   : '';
        $clean_data['instructions']     = isset($data['bank_instructions']) ? wp_kses_post(wp_unslash($data['bank_instructions']))        : '';

        $clean_data['iban'] = strtoupper((string) preg_replace('/\s+/', '', (string) $clean_data['iban']));

        update_option(self::OPT_BANKING, $clean_data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save_revolut( array $data ): void {
        $clean_data = array();
        $clean_data['enabled']      = isset($data['rev_enabled']) ? 1 : 0;
        $clean_data['revolut_name'] = isset($data['revolut_name']) ? sanitize_text_field(wp_unslash($data['revolut_name'])) : '';
        $clean_data['revolut_tag']  = isset($data['revolut_tag'])  ? sanitize_text_field(wp_unslash($data['revolut_tag']))  : '';
        $clean_data['revolut_iban'] = isset($data['revolut_iban']) ? sanitize_text_field(wp_unslash($data['revolut_iban'])) : '';
        $clean_data['revolut_link'] = isset($data['revolut_link']) ? esc_url_raw(wp_unslash($data['revolut_link']))         : '';
        $clean_data['qr_code_id']   = isset($data['qr_code_id'])   ? absint(wp_unslash($data['qr_code_id']))                   : 0;
        $clean_data['qr_code_url']  = isset($data['qr_code_url'])  ? esc_url_raw(wp_unslash($data['qr_code_url']))         : '';
        $clean_data['instructions'] = isset($data['rev_instructions']) ? wp_kses_post(wp_unslash($data['rev_instructions'])) : '';

        $clean_data['revolut_iban'] = strtoupper((string) preg_replace('/\s+/', '', (string) $clean_data['revolut_iban']));

        update_option(self::OPT_REVOLUT, $clean_data);
    }

    /* ═══════════════════════════════════════════════════════════════
       RENDER ADMIN PAGE
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Render the settings tab content within the main Settings page.
     */
    public static function render_settings_tab(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $active_subtab = (isset($_GET['subtab']) ? sanitize_key(wp_unslash($_GET['subtab'])) : '') ?: 'company';
        $company  = self::get_company();
        $whatsapp = self::get_whatsapp();
        $banking  = self::get_banking();
        $revolut  = self::get_revolut();

        $tabs = array(
            'company'    => array( 'icon' => 'building',  'label' => I18n::get_label('business_info_tab_company') ),
            'whatsapp'   => array( 'icon' => 'phone',     'label' => I18n::get_label('business_info_tab_whatsapp') ),
            'banking'    => array( 'icon' => 'money-alt', 'label' => I18n::get_label('business_info_tab_banking') ),
            'revolut'    => array( 'icon' => 'money',     'label' => I18n::get_label('business_info_tab_revolut') ),
            'shortcodes' => array( 'icon' => 'shortcode', 'label' => I18n::get_label('business_info_tab_shortcodes') ),
        );
        ?>
        <div class="mhbo-business-info-content">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;"><?php echo esc_html(I18n::get_label('business_info_title')); ?></h1>
            <p class="description">
                <?php echo esc_html(I18n::get_label('business_info_desc')); ?>
            </p>

            <h2 class="nav-tab-wrapper mhbo-tabs" style="margin-bottom: 20px;">
                <?php foreach ( $tabs as $slug => $tab_info ) :
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading page slug for tab URL only, not processing form data.
                    $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'mhbo-settings';
                    $tab_url = add_query_arg( array( 'tab' => 'business', 'subtab' => $slug ), admin_url( 'admin.php?page=' . $current_page ) );
                ?>
                    <a href="<?php echo esc_url( $tab_url ); ?>"
                       class="nav-tab <?php echo esc_attr( $slug === $active_subtab ? 'nav-tab-active' : '' ); ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr( $tab_info['icon'] ); ?>"></span>
                        <?php echo esc_html( $tab_info['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="mhbo-tab-content">
                <input type="hidden" name="mhbo_business_subtab" value="<?php echo esc_attr( (string) $active_subtab ); ?>">
                <?php
                switch ( $active_subtab ) {
                    case 'company':
                        self::get_instance()->render_tab_company( $company );
                        break;
                    case 'whatsapp':
                        self::get_instance()->render_tab_whatsapp( $whatsapp );
                        break;
                    case 'banking':
                        self::get_instance()->render_tab_banking( $banking );
                        break;
                    case 'revolut':
                        self::get_instance()->render_tab_revolut( $revolut );
                        break;
                    case 'shortcodes':
                        self::get_instance()->render_tab_shortcodes();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $data Company info values.
     */
    private function render_tab_company( array $data ): void {
        ?>

<table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="company_name"><?php echo esc_html(I18n::get_label('business_label_company_name')); ?></label></th>
                        <td><input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $data['company_name'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="contact_name"><?php echo esc_html(I18n::get_label('business_label_contact_name')); ?></label></th>
                        <td><input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr( $data['contact_name'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="address_line_1"><?php echo esc_html(I18n::get_label('business_label_address_1')); ?></label></th>
                        <td><input type="text" id="address_line_1" name="address_line_1" value="<?php echo esc_attr( $data['address_line_1'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="address_line_2"><?php echo esc_html(I18n::get_label('business_label_address_2')); ?></label></th>
                        <td><input type="text" id="address_line_2" name="address_line_2" value="<?php echo esc_attr( $data['address_line_2'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="city"><?php echo esc_html(I18n::get_label('business_label_city')); ?></label></th>
                        <td><input type="text" id="city" name="city" value="<?php echo esc_attr( $data['city'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="state"><?php echo esc_html(I18n::get_label('business_label_state')); ?></label></th>
                        <td><input type="text" id="state" name="state" value="<?php echo esc_attr( $data['state'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="postcode"><?php echo esc_html(I18n::get_label('business_label_postcode')); ?></label></th>
                        <td><input type="text" id="postcode" name="postcode" value="<?php echo esc_attr( $data['postcode'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="country"><?php echo esc_html(I18n::get_label('business_label_country')); ?></label></th>
                        <td><input type="text" id="country" name="country" value="<?php echo esc_attr( $data['country'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="telephone"><?php echo esc_html(I18n::get_label('business_label_telephone')); ?></label></th>
                        <td><input type="tel" id="telephone" name="telephone" value="<?php echo esc_attr( $data['telephone'] ); ?>" class="regular-text" placeholder="+1 234 567 8900" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email"><?php echo esc_html(I18n::get_label('business_label_email')); ?></label></th>
                        <td><input type="email" id="email" name="email" value="<?php echo esc_attr( $data['email'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="website"><?php echo esc_html(I18n::get_label('business_label_website')); ?></label></th>
                        <td><input type="url" id="website" name="website" value="<?php echo esc_url( $data['website'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tax_id"><?php echo esc_html(I18n::get_label('business_label_tax_id')); ?></label></th>
                        <td><input type="text" id="tax_id" name="tax_id" value="<?php echo esc_attr( $data['tax_id'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="registration_no"><?php echo esc_html(I18n::get_label('business_label_registration_no')); ?></label></th>
                        <td><input type="text" id="registration_no" name="registration_no" value="<?php echo esc_attr( $data['registration_no'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php echo esc_html(I18n::get_label('business_label_logo')); ?></label></th>
                        <td><?php $this->render_media_field( 'mhbo_logo', (int) $data['logo_id'], (string) $data['logo_url'], I18n::get_label('business_label_select_logo') ); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render_tab_whatsapp( array $data ): void {
        ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html(I18n::get_label('business_label_wa_enable')); ?></th>
                        <td><label><input type="checkbox" name="wa_enabled" value="1" <?php checked( $data['enabled'], 1 ); ?> /> <?php echo esc_html(I18n::get_label('business_label_wa_enable_desc')); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_phone_number"><?php echo esc_html(I18n::get_label('business_label_wa_phone')); ?></label></th>
                        <td>
                            <input type="text" id="wa_phone_number" name="wa_phone_number" value="<?php echo esc_attr( $data['phone_number'] ); ?>" class="regular-text" placeholder="+34612345678" />
                            <p class="description"><?php echo esc_html(I18n::get_label('business_label_wa_phone_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_default_msg"><?php echo esc_html(I18n::get_label('business_label_wa_msg')); ?></label></th>
                        <td><textarea id="wa_default_msg" name="wa_default_msg" rows="3" class="large-text"><?php echo esc_textarea( $data['default_msg'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_button_text"><?php echo esc_html(I18n::get_label('business_label_wa_btn')); ?></label></th>
                        <td><input type="text" id="wa_button_text" name="wa_button_text" value="<?php echo esc_attr( $data['button_text'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_display_style"><?php echo esc_html(I18n::get_label('business_label_wa_style')); ?></label></th>
                        <td>
                            <select id="wa_display_style" name="wa_display_style">
                                <option value="button" <?php selected( $data['display_style'], 'button' ); ?>><?php echo esc_html(I18n::get_label('business_info_style_button')); ?></option>
                                <option value="floating" <?php selected( $data['display_style'], 'floating' ); ?>><?php echo esc_html(I18n::get_label('business_info_style_floating')); ?></option>
                                <option value="link" <?php selected( $data['display_style'], 'link' ); ?>><?php echo esc_html(I18n::get_label('business_info_style_link')); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_position"><?php echo esc_html(I18n::get_label('business_label_wa_pos')); ?></label></th>
                        <td>
                            <select id="wa_position" name="wa_position">
                                <option value="bottom-right" <?php selected( $data['position'], 'bottom-right' ); ?>><?php echo esc_html(I18n::get_label('general_pos_bottom_right')); ?></option>
                                <option value="bottom-left" <?php selected( $data['position'], 'bottom-left' ); ?>><?php echo esc_html(I18n::get_label('general_pos_bottom_left')); ?></option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render_tab_banking( array $data ): void {
        ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html(I18n::get_label('business_label_bank_enable')); ?></th>
                        <td><label><input type="checkbox" name="bank_enabled" value="1" <?php checked( $data['enabled'], 1 ); ?> /> <?php echo esc_html(I18n::get_label('business_label_bank_enable_desc')); ?></label></td>
                    </tr>
                    <tr><th scope="row"><label for="bank_name"><?php echo esc_html(I18n::get_label('business_label_bank_name')); ?></label></th><td><input type="text" id="bank_name" name="bank_name" value="<?php echo esc_attr( $data['bank_name'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="account_name"><?php echo esc_html(I18n::get_label('business_label_bank_acc_name')); ?></label></th><td><input type="text" id="account_name" name="account_name" value="<?php echo esc_attr( $data['account_name'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="account_number"><?php echo esc_html(I18n::get_label('business_label_bank_acc_no')); ?></label></th><td><input type="text" id="account_number" name="account_number" value="<?php echo esc_attr( $data['account_number'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="iban"><?php echo esc_html(I18n::get_label('business_label_bank_iban')); ?></label></th><td><input type="text" id="iban" name="iban" value="<?php echo esc_attr( $data['iban'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="swift_bic"><?php echo esc_html(I18n::get_label('business_label_bank_swift')); ?></label></th><td><input type="text" id="swift_bic" name="swift_bic" value="<?php echo esc_attr( $data['swift_bic'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="sort_code"><?php echo esc_html(I18n::get_label('business_label_bank_sort')); ?></label></th><td><input type="text" id="sort_code" name="sort_code" value="<?php echo esc_attr( $data['sort_code'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="branch_address"><?php echo esc_html(I18n::get_label('business_label_bank_address')); ?></label></th><td><textarea id="branch_address" name="branch_address" rows="3" class="large-text"><?php echo esc_textarea( $data['branch_address'] ); ?></textarea></td></tr>
                    <tr>
                        <th scope="row"><label for="reference_prefix"><?php echo esc_html(I18n::get_label('business_label_bank_prefix')); ?></label></th>
                        <td>
                            <input type="text" id="reference_prefix" name="reference_prefix" value="<?php echo esc_attr( $data['reference_prefix'] ); ?>" class="regular-text" placeholder="BOOKING-" />
                            <p class="description"><?php echo esc_html(I18n::get_label('business_label_bank_prefix_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bank_instructions"><?php echo esc_html(I18n::get_label('business_label_bank_instr')); ?></label></th>
                        <td><?php wp_editor( $data['instructions'], 'bank_instructions', array( 'textarea_name' => 'bank_instructions', 'textarea_rows' => 5, 'media_buttons' => false, 'teeny' => true ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render_tab_revolut( array $data ): void {
        ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html(I18n::get_label('business_label_rev_enable')); ?></th>
                        <td><label><input type="checkbox" name="rev_enabled" value="1" <?php checked( $data['enabled'], 1 ); ?> /> <?php echo esc_html(I18n::get_label('business_label_rev_enable_desc')); ?></label></td>
                    </tr>
                    <tr><th scope="row"><label for="revolut_name"><?php echo esc_html(I18n::get_label('business_label_rev_name')); ?></label></th><td><input type="text" id="revolut_name" name="revolut_name" value="<?php echo esc_attr( $data['revolut_name'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="revolut_tag"><?php echo esc_html(I18n::get_label('business_label_rev_tag')); ?></label></th><td><input type="text" id="revolut_tag" name="revolut_tag" value="<?php echo esc_attr( $data['revolut_tag'] ); ?>" class="regular-text" placeholder="@yourname" /></td></tr>
                    <tr><th scope="row"><label for="revolut_iban"><?php echo esc_html(I18n::get_label('business_label_rev_iban')); ?></label></th><td><input type="text" id="revolut_iban" name="revolut_iban" value="<?php echo esc_attr( $data['revolut_iban'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="revolut_link"><?php echo esc_html(I18n::get_label('business_label_rev_link')); ?></label></th><td><input type="url" id="revolut_link" name="revolut_link" value="<?php echo esc_url( $data['revolut_link'] ); ?>" class="regular-text" placeholder="https://revolut.me/yourname" /></td></tr>
                    <tr>
                        <th scope="row"><label><?php echo esc_html(I18n::get_label('business_label_rev_qr')); ?></label></th>
                        <td><?php $this->render_media_field( 'mhbo_qr_code', (int) $data['qr_code_id'], (string) $data['qr_code_url'], I18n::get_label('business_label_rev_select_qr') ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rev_instructions"><?php echo esc_html(I18n::get_label('business_label_rev_instr')); ?></label></th>
                        <td><?php wp_editor( $data['instructions'], 'rev_instructions', array( 'textarea_name' => 'rev_instructions', 'textarea_rows' => 5, 'media_buttons' => false, 'teeny' => true ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /**
     * Render the shortcodes tab.
     */
    private function render_tab_shortcodes(): void {
        ?>
        <div class="mhbo-shortcodes-reference">
            <h2><?php echo esc_html(I18n::get_label('business_info_tab_shortcodes')); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php echo esc_html(I18n::get_label('general_label_shortcode')); ?></th><th><?php echo esc_html(I18n::get_label('settings_label_desc')); ?></th><th><?php echo esc_html(I18n::get_label('general_label_attributes')); ?></th></tr></thead>
                <tbody>
                    <tr><td><code>[mhbo_company_info]</code></td><td><?php echo esc_html(I18n::get_label('shortcode_desc_company_info')); ?></td><td><code>show_logo</code> <code>show_address</code> <code>show_contact</code> <code>show_registration</code> <code>layout</code></td></tr>
                    <tr><td><code>[mhbo_whatsapp]</code></td><td><?php echo esc_html(I18n::get_label('shortcode_desc_whatsapp')); ?></td><td><code>style</code> <code>text</code> <code>message</code></td></tr>
                    <tr><td><code>[mhbo_banking_details]</code></td><td><?php echo esc_html(I18n::get_label('shortcode_desc_banking')); ?></td><td><code>show_instructions</code> <code>booking_id</code> <code>layout</code></td></tr>
                    <tr><td><code>[mhbo_revolut_details]</code></td><td><?php echo esc_html(I18n::get_label('shortcode_desc_revolut')); ?></td><td><code>show_qr</code> <code>show_link</code> <code>layout</code></td></tr>
                    <tr><td><code>[mhbo_business_card]</code></td><td><?php echo esc_html(I18n::get_label('shortcode_desc_card')); ?></td><td><code>sections</code></td></tr>
                    <tr><td><code>[mhbo_payment_methods]</code></td><td><?php echo esc_html(I18n::get_label('shortcode_desc_all_methods')); ?></td><td><code>booking_id</code></td></tr>
                </tbody>
            </table>
            <h2 style="margin-top:30px;"><?php echo esc_html(I18n::get_label('settings_title_gutenberg')); ?></h2>
            <p class="description"><?php echo esc_html(I18n::get_label('general_search_hotel')); ?></p>
        </div>
        <?php
    }

    /**
     * Reusable media upload field.
     *
     * @param string $prefix    Field prefix for IDs.
     * @param int    $image_id  Attachment ID.
     * @param string $image_url Attachment URL.
     * @param string $title     Media modal title.
     */
    private function render_media_field( string $prefix, int $image_id, string $image_url, string $title ): void {
        $has_image = '' !== (string) $image_url;
        $id_field  = str_replace( 'mhbo_', '', $prefix ) . '_id';
        $url_field = str_replace( 'mhbo_', '', $prefix ) . '_url';
        ?>
        <div class="mhbo-media-upload-wrap">
            <input type="hidden" name="<?php echo esc_attr( (string) $id_field ); ?>" id="<?php echo esc_attr( $prefix ); ?>_id" value="<?php echo esc_attr( (string) $image_id ); ?>" />
            <input type="hidden" name="<?php echo esc_attr( (string) $url_field ); ?>" id="<?php echo esc_attr( $prefix ); ?>_url" value="<?php echo esc_url( $image_url ); ?>" />
            <div id="<?php echo esc_attr( $prefix ); ?>_preview" class="mhbo-image-preview" <?php echo esc_attr( $has_image ? '' : 'style=display:none;' ); ?>>
                <?php if ( $has_image ) : ?>
                    <img src="<?php echo esc_url( $image_url ); ?>" alt="" style="max-width:200px;height:auto;" />
                <?php endif; ?>
            </div>
            <button type="button" class="button mhbo-upload-btn" data-target-id="<?php echo esc_attr( $prefix ); ?>_id" data-target-url="<?php echo esc_attr( $prefix ); ?>_url" data-preview="<?php echo esc_attr( $prefix ); ?>_preview" data-title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $title ); ?></button>
            <button type="button" class="button mhbo-remove-btn" data-target-id="<?php echo esc_attr( $prefix ); ?>_id" data-target-url="<?php echo esc_attr( $prefix ); ?>_url" data-preview="<?php echo esc_attr( $prefix ); ?>_preview" <?php echo esc_attr( $has_image ? '' : 'style=display:none;' ); ?>><?php echo esc_html(I18n::get_label('general_btn_remove')); ?></button>
        </div>
        <?php
    }
}

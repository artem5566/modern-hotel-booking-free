<?php declare(strict_types=1);

namespace MHBO\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AdminUI
 *
 * Provides standardized UI components for the MHBO admin interface.
 * Implements WordPress Best Practices 2026.
 */
class AdminUI
{
    /**
     * Renders a standardized page header.
     *
     * @param string $title       The main page title.
     * @param string $subtitle    Optional subtitle or description.
     * @param array<int, array<string, string>> $actions Optional array of action buttons [ ['label' => '', 'url' => '', 'class' => 'button-primary'] ].
     * @param array<int, array<string, string>> $breadcrumbs Optional array of breadcrumbs [ ['label' => '', 'url' => ''] ].
     */
    public static function render_header(string $title, string $subtitle = '', array $actions = [], array $breadcrumbs = []): void
    {
        ?>
        <div class="mhbo-admin-page-header mhbo-animate-in">
            <div class="mhbo-header-content">
                <?php if (count($breadcrumbs) > 0): ?>
                    <nav class="mhbo-breadcrumbs" style="margin-bottom: 12px;">
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <a href="<?php echo esc_url($crumb['url']); ?>"><?php echo esc_html($crumb['label']); ?></a>
                            <span class="mhbo-breadcrumb-sep">/</span>
                        <?php endforeach; ?>
                        <span class="mhbo-breadcrumb-current"><?php echo esc_html($title); ?></span>
                    </nav>
                <?php endif; ?>
                
                <h1><?php echo esc_html($title); ?></h1>
                <?php if ($subtitle): ?>
                    <span class="subtitle"><?php echo esc_html($subtitle); ?></span>
                <?php endif; ?>
            </div>
            <?php if (count($actions) > 0): ?>
                <div class="mhbo-header-actions">
                    <?php foreach ($actions as $action): ?>
                        <a href="<?php echo esc_url($action['url']); ?>" class="button <?php echo esc_attr($action['class'] ?? 'button-secondary'); ?>">
                            <?php echo esc_html($action['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the start of a stylized card.
     *
     * @param string $title Optional card title.
     * @param string $class Additional CSS classes.
     */
    public static function render_card_start(string $title = '', string $class = ''): void
    {
        $full_class = trim('mhbo-card ' . $class);
        ?>
        <div class="<?php echo esc_attr($full_class); ?> mhbo-animate-in">
            <?php if ($title): ?>
                <h3 class="mhbo-card-title"><?php echo esc_html($title); ?></h3>
            <?php endif; ?>
            <div class="mhbo-card-content">
        <?php
    }

    /**
     * Renders the end of a stylized card.
     */
    public static function render_card_end(): void
    {
        ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a tabbed navigation bar.
     *
     * @param array<string, string> $tabs Associative array of tabs [ 'slug' => 'Label' ].
     * @param string $active_tab The slug of the currently active tab.
     * @param string $base_url   The base URL for the tab links.
     * @param array<string, string> $icons Optional mapping of tab slugs to Dashicons [ 'slug' => 'dashicons-...' ].
     */
    public static function render_tabs(array $tabs, string $active_tab, string $base_url, array $icons = []): void
    {
        ?>
        <nav class="nav-tab-wrapper wp-clearfix" style="border-bottom: 2px solid #dcdcde; margin-bottom: 30px; display: flex; gap: 5px;">
            <?php foreach ($tabs as $slug => $label): ?>
                <?php $has_icon = isset( $icons[ $slug ] ) && '' !== $icons[ $slug ]; ?>
                <a href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>" 
                   class="nav-tab <?php echo esc_attr($active_tab === $slug ? 'nav-tab-active' : ''); ?>"
                   style="display: flex; align-items: center; gap: 8px;">
                    <?php if ( $has_icon ) : ?>
                        <span class="dashicons <?php echo esc_attr( $icons[ $slug ] ); ?>" style="font-size: 18px; width: 18px; height: 18px;"></span>
                    <?php endif; ?>
                    <span><?php echo esc_html($label); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Renders a status badge.
     *
     * @param string $text The badge text.
     * @param string $status The status slug (pending, confirmed, cancelled).
     */
    public static function render_status_badge(string $text, string $status): void
    {
        $status_class = 'mhbo-status-' . sanitize_html_class($status);
        ?>
        <span class="mhbo-status-badge <?php echo esc_attr($status_class); ?>">
            <?php echo esc_html($text); ?>
        </span>
        <?php
    }
}

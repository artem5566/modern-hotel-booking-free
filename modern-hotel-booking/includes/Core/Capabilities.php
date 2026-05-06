<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capabilities management class for MHBO.
 * 
 * Defines and registers granular abilities for the plugin using the modern 
 * WordPress 6.x Abilities API.
 * 
 * @package MHBOCore
 * @since   2.3.0
 */
class Capabilities
{
    /**
     * Ability to manage the entire booking system.
     */
    public const MANAGE_LHBO = 'mhbo_manage_bookings';

    /**
     * Ability to view analytics and reports.
     */
    public const VIEW_ANALYTICS = 'mhbo_view_analytics';

    /**
     * Ability to manage plugin settings.
     */
    public const MANAGE_SETTINGS = 'mhbo_manage_settings';

    /**
     * Register MHBO abilities and map roles on activation.
     */
    public static function register(): void
    {
        // RATIONALE: We use the standard 'init' hook to ensure roles are updated 
        // if they were modified by other plugins.
        add_action('init', [self::class, 'init_abilities'], 1);
    }

    /**
     * Initialize abilities and grant to Administrator role by default.
     * 
     * @internal
     */
    public static function init_abilities(): void
    {
        $role = get_role('administrator');

        if (!$role) {
            return;
        }

        $abilities = [
            self::MANAGE_LHBO,
            self::VIEW_ANALYTICS,
            self::MANAGE_SETTINGS,
        ];

        foreach ($abilities as $ability) {
            if (!$role->has_cap($ability)) {
                $role->add_cap($ability);
            }
        }
    }

    /**
     * Helper to check if the current user can perform an MHBO action.
     * 
     * @param string $ability The ability to check.
     * @return bool True if permitted, false otherwise.
     */
    public static function current_user_can(string $ability): bool
    {
        return \current_user_can($ability);
    }
}

<?php
/**
 * AI Visibility Management — Handles llms.txt and llms-full.txt files.
 *
 * Implements the 2026 standard for AI-discoverable hotel content.
 * Follows WP.org repo compliance (strictly user-initiated creation).
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

use MHBO\Business\Info;
use MHBO\Core\Pricing;
use MHBO\Core\License;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LlmFile {

    public const FILENAME_SUMMARY = 'llms.txt';
    public const FILENAME_FULL    = 'llms-full.txt';

    private const MARKER_START = '<!-- MHBO:START -->';
    private const MARKER_END   = '<!-- MHBO:END -->';

    /**
     * Generate and write the AI discovery files to the site root.
     *
     * @return array{success: bool, message: string}
     */
    public static function sync(): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            return [ 'success' => false, 'message' => __( 'Insufficient permissions.', 'modern-hotel-booking' ) ];
        }

        $summary_content = self::generate_summary_content();
        $full_content    = self::generate_full_content();

        $summary_wrote = self::write_file( self::FILENAME_SUMMARY, $summary_content );
        $full_wrote    = self::write_file( self::FILENAME_FULL, $full_content );

        if ( $summary_wrote && $full_wrote ) {
            update_option( 'mhbo_ai_discovery_last_sync', gmdate( 'Y-m-d H:i:s' ) );
            return [ 'success' => true, 'message' => __( 'AI Discovery files published successfully.', 'modern-hotel-booking' ) ];
        }

        return [ 'success' => false, 'message' => __( 'Failed to write files to site root. Please check permissions.', 'modern-hotel-booking' ) ];
    }

    /**
     * Remove the AI discovery files from the site root.
     *
     * @return array{success: bool, message: string}
     */
    public static function cleanup(): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            return [ 'success' => false, 'message' => __( 'Insufficient permissions.', 'modern-hotel-booking' ) ];
        }

        $s_deleted = self::delete_file( self::FILENAME_SUMMARY );
        $f_deleted = self::delete_file( self::FILENAME_FULL );

        delete_option( 'mhbo_ai_discovery_last_sync' );

        return [ 'success' => true, 'message' => __( 'AI Discovery files removed.', 'modern-hotel-booking' ) ];
    }

    /**
     * Check if MHBO discovery content is published in each file.
     *
     * @return array{summary: bool, full: bool}
     */
    public static function get_status(): array {
        return [
            'summary' => self::file_has_mhbo_content( self::FILENAME_SUMMARY ),
            'full'    => self::file_has_mhbo_content( self::FILENAME_FULL ),
        ];
    }

    /**
     * Return true if the file contains an active MHBO marked block.
     *
     * @param string $filename
     * @return bool
     */
    private static function file_has_mhbo_content( string $filename ): bool {
        $path = ABSPATH . $filename;
        if ( ! file_exists( $path ) ) {
            return false;
        }
        $contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $contents ) {
            return false;
        }
        $start = strpos( $contents, self::MARKER_START );
        $end   = strpos( $contents, self::MARKER_END );
        return false !== $start && false !== $end && $end > $start;
    }

    // -------------------------------------------------------------------------
    // Content Generation
    // -------------------------------------------------------------------------

    /**
     * Build the content for llms.txt (Summary).
     *
     * @return string
     */
    private static function generate_summary_content(): string {
        $company       = Info::get_company();
        $hotel_name    = $company['company_name'] ?: get_bloginfo( 'name' );
        $welcome       = get_option( 'mhbo_ai_welcome_message', \MHBO\Core\I18n::get_label( 'ai_discovery_summary_welcome_default' ) );
        $hotel_address = self::get_formatted_address( $company );
        $hotel_phone   = (string) ( $company['telephone'] ?? '' );
        
        $summary = [];
        
        $existing_content = '';
        $path = ABSPATH . self::FILENAME_SUMMARY;
        if ( file_exists( $path ) ) {
            $existing_content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( false !== $existing_content ) {
                $start = strpos( $existing_content, self::MARKER_START );
                $end   = strpos( $existing_content, self::MARKER_END );
                if ( false !== $start && false !== $end && $end > $start ) {
                    $existing_content = substr( $existing_content, 0, $start ) . substr( $existing_content, $end + strlen( self::MARKER_END ) );
                }
            } else {
                $existing_content = '';
            }
        }
        
        if ( ! preg_match( '/^#\s+/m', (string) $existing_content ) ) {
            $summary[] = sprintf( "# %s", $hotel_name );
        }
        
        if ( ! preg_match( '/^>\s+/m', (string) $existing_content ) && isset( $welcome ) && $welcome !== '' ) {
            $summary[] = "> " . $welcome;
        }
        
        $summary[] = '';
        $summary[] = "## Property Details";
        $summary[] = sprintf( "- Location: %s", $hotel_address );
        $summary[] = sprintf( "- Contact: %s", $hotel_phone );
        
        $summary[] = '';
        $summary[] = "## AI Services";
        $summary[] = sprintf( "- [Web Concierge](%s): AI chat assistant for booking and info.", home_url( '/#ai-concierge' ) );

$summary[] = '';
        $summary[] = "## Optional";
        $summary[] = sprintf( "- [Full Knowledge Base](%s): Complete context and policies.", home_url( '/' . self::FILENAME_FULL ) );
        
        $sitemap_url = get_option( 'mhbo_ai_sitemap_url' );
        if ( isset( $sitemap_url ) && $sitemap_url !== '' && $sitemap_url !== false ) {
            $summary[] = sprintf( "- [Sitemap](%s): XML sitemap for the property.", esc_url( $sitemap_url ) );
        }

$markdown = implode( "\n", array_filter( $summary, fn($line) => $line !== '' ) );
        return (string) apply_filters( 'mhbo_ai_llms_summary_content', $markdown );
    }

    /**
     * Build the content for llms-full.txt (Detailed).
     *
     * @return string
     */
    private static function generate_full_content(): string {
        $kb = SiteScanner::get_or_build();
        $kb = wp_strip_all_tags( $kb );
        return (string) apply_filters( 'mhbo_ai_llms_full_content', $kb );
    }

    // -------------------------------------------------------------------------
    // File I/O (Safe Utilities)
    // -------------------------------------------------------------------------

    /**
     * Write content to a file in the site root using WP_Filesystem.
     *
     * If the file already exists:
     *   - Markers found → replaces only the content between them.
     *   - No markers → appends a new marked section at the end.
     * If the file does not exist, creates it fresh with the marked section.
     *
     * @param string $filename
     * @param string $content
     * @return bool
     */
    private static function write_file( string $filename, string $content ): bool {
        $path = ABSPATH . $filename;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! WP_Filesystem() ) {
            return false;
        }

        global $wp_filesystem;

        $marked_block = self::MARKER_START . "\n" . $content . "\n" . self::MARKER_END;

        if ( ! $wp_filesystem->exists( $path ) ) {
            return (bool) $wp_filesystem->put_contents( $path, $marked_block . "\n", FS_CHMOD_FILE );
        }

        // File exists — update or append the marked section only.
        $existing = $wp_filesystem->get_contents( $path );
        if ( false === $existing ) {
            return false;
        }

        $start = strpos( $existing, self::MARKER_START );
        $end   = strpos( $existing, self::MARKER_END );

        if ( false !== $start && false !== $end && $end > $start ) {
            // Replace everything between (and including) the existing markers.
            $new_contents = substr( $existing, 0, $start )
                . $marked_block
                . substr( $existing, $end + strlen( self::MARKER_END ) );
        } else {
            // No markers yet — append our section, separated by a blank line.
            $new_contents = rtrim( $existing ) . "\n\n" . $marked_block . "\n";
        }

        return (bool) $wp_filesystem->put_contents( $path, $new_contents, FS_CHMOD_FILE );
    }

    /**
     * Remove only the MHBO marked section from a file in the site root.
     *
     * The file itself is never deleted — any content outside the MHBO markers
     * is preserved. If the file has no markers, it is left untouched.
     *
     * @param string $filename
     * @return bool
     */
    private static function delete_file( string $filename ): bool {
        $path = ABSPATH . $filename;
        if ( ! file_exists( $path ) ) {
            return true;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! WP_Filesystem() ) {
            return false;
        }

        global $wp_filesystem;

        $existing = $wp_filesystem->get_contents( $path );
        if ( false === $existing ) {
            return false;
        }

        $start = strpos( $existing, self::MARKER_START );
        $end   = strpos( $existing, self::MARKER_END );

        if ( false === $start || false === $end || $end <= $start ) {
            // No markers — file was not written by this plugin; leave it alone.
            return true;
        }

        // Strip the marked block (including any surrounding blank lines).
        $before = rtrim( substr( $existing, 0, $start ) );
        $after  = ltrim( substr( $existing, $end + strlen( self::MARKER_END ) ) );

        $remainder = trim( $before . "\n" . $after );

        // Always write the remainder back — never delete the file entirely.
        $new_contents = '' !== $remainder ? $remainder . "\n" : '';
        return (bool) $wp_filesystem->put_contents( $path, $new_contents, FS_CHMOD_FILE );
    }

    /**
     * Formatting helper.
     *
     * @param array<string, mixed> $company
     * @return string
     */
    private static function get_formatted_address( array $company ): string {
        $parts = array_filter( [
            (string) ( $company['address_line_1'] ?? '' ),
            (string) ( $company['city']           ?? '' ),
            (string) ( $company['country']        ?? '' ),
        ], fn( $val ) => '' !== $val );
        return implode( ', ', $parts );
    }
}

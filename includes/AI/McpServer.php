<?php
/**
 * MCP Server — Native Model Context Protocol (MCP) server for WordPress.
 *
 * Implements standard MCP HTTP / JSON-RPC 2.0 specification (2025/2026) for seamless
 * integration with ChatGPT, Claude, Cursor, and agentic AI tools.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MHBO\Core\License;
use MHBO\Core\I18n;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Throwable;

class McpServer {

    public const SERVER_ID    = 'modern-hotel-booking-mcp';
    public const SERVER_NS    = 'modern-hotel-booking';
    public const SERVER_ROUTE = 'mcp';

    /**
     * Initialise the MCP server hooks.
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

        // WP 7.0 native Connectors API forward compatibility.
        if ( function_exists( 'wp_register_mcp_server' ) ) {
            self::register_native();
        }

        // wordpress/mcp-adapter Composer package hook compatibility (WP 6.9).
        if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
            add_action( 'mcp_adapter_init', [ self::class, 'register_adapter' ] );
        }
    }

    /**
     * Register the REST API route for MCP JSON-RPC 2.0 communication.
     */
    public static function register_rest_routes(): void {
        register_rest_route(
            self::SERVER_NS,
            '/' . self::SERVER_ROUTE,
            [
                [
                    'methods'             => [ 'GET', 'POST', 'OPTIONS' ],
                    'callback'            => [ self::class, 'handle_request' ],
                    'permission_callback' => '__return_true', // Public readability for tools/list and availability
                ],
            ]
        );
    }

    /**
     * Dispatch incoming REST requests (GET, POST, OPTIONS).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // 1. Always set CORS headers for cross-origin LLM clients.
        self::set_cors_headers();

        $method = $request->get_method();

        // 2. Preflight OPTIONS request handler.
        if ( 'OPTIONS' === $method ) {
            return new WP_REST_Response( null, 200 );
        }

        // 3. Human-readable GET diagnostic handler.
        if ( 'GET' === $method ) {
            return new WP_REST_Response( [
                'name'         => 'Modern Hotel Booking MCP Server',
                'server_id'    => self::SERVER_ID,
                'version'      => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '2.4.9',
                'status'       => 'active',
                'protocol'     => 'JSON-RPC 2.0 over HTTP (MCP Streamable)',
                'endpoint'     => self::get_endpoint_url(),
                'llms_txt'     => home_url( '/llms.txt' ),
                'capabilities' => [
                    'tools'     => count( self::get_mcp_tools_list() ),
                    'resources' => 1,
                ],
            ], 200 );
        }

        // 4. Rate Limiting Check (30 req/hr Free, 200 req/hr Pro per IP).
        $ip     = self::get_client_ip();
        $ip_key = 'mhbo_mcp_rl_' . md5( (string) $ip );
        $rate   = ( defined( 'MHBO_IS_PRO' ) && MHBO_IS_PRO ) ? 200 : 30;

        $count = (int) get_transient( $ip_key );
        if ( $count >= $rate ) {
            return self::json_rpc_error( null, -32000, 'Rate limit exceeded. Please wait before sending more requests.' );
        }
        set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );

        // 5. POST JSON-RPC 2.0 Request processing.
        $body = $request->get_json_params();

        if ( ! is_array( $body ) || [] === $body ) {
            return self::json_rpc_error( null, -32700, 'Parse error: invalid JSON' );
        }

        $jsonrpc_ver = (string) ( $body['jsonrpc'] ?? '' );
        $rpc_method  = (string) ( $body['method']  ?? '' );
        $rpc_id      = $body['id'] ?? null;
        $params      = (array) ( $body['params']   ?? [] );

        // Validate JSON-RPC structure.
        if ( '2.0' !== $jsonrpc_ver && '' === $rpc_method ) {
            return self::json_rpc_error( $rpc_id, -32600, 'Invalid Request: jsonrpc must be "2.0"' );
        }

        switch ( $rpc_method ) {
            case 'initialize':
                return self::handle_initialize( $rpc_id );

            case 'notifications/initialized':
                return new WP_REST_Response( null, 200 );

            case 'ping':
                return new WP_REST_Response( [
                    'jsonrpc' => '2.0',
                    'id'      => $rpc_id,
                    'result'  => (object) [],
                ], 200 );

            case 'tools/list':
                return self::handle_tools_list( $rpc_id );

            case 'tools/call':
                return self::handle_tools_call( $rpc_id, $params );

            case 'resources/list':
                return self::handle_resources_list( $rpc_id );

            case 'resources/read':
                return self::handle_resources_read( $rpc_id, $params );

            default:
                return self::json_rpc_error( $rpc_id, -32601, 'Method not found: ' . $rpc_method );
        }
    }

    /**
     * Handle initialize JSON-RPC request.
     *
     * @param mixed $rpc_id
     * @return WP_REST_Response
     */
    private static function handle_initialize( mixed $rpc_id ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'result'  => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => [
                    'tools'     => (object) [],
                    'resources' => (object) [],
                ],
                'serverInfo'      => [
                    'name'    => 'Modern Hotel Booking MCP Server',
                    'version' => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '2.4.9',
                ],
            ],
        ], 200 );
    }

    /**
     * Handle tools/list JSON-RPC request.
     *
     * @param mixed $rpc_id
     * @return WP_REST_Response
     */
    private static function handle_tools_list( mixed $rpc_id ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'result'  => [
                'tools' => self::get_mcp_tools_list(),
            ],
        ], 200 );
    }

    /**
     * Handle tools/call JSON-RPC request.
     *
     * @param mixed $rpc_id
     * @param array<string, mixed> $params
     * @return WP_REST_Response
     */
    private static function handle_tools_call( mixed $rpc_id, array $params ): WP_REST_Response {
        $tool_name = (string) ( $params['name'] ?? '' );
        $arguments = (array) ( $params['arguments'] ?? [] );

        $map = self::get_ability_map();

        if ( ! isset( $map[ $tool_name ] ) ) {
            return self::json_rpc_error( $rpc_id, -32601, 'Tool not found: ' . $tool_name );
        }

        $class_name = $map[ $tool_name ];

        try {
            if ( ! method_exists( $class_name, 'execute' ) ) {
                return self::json_rpc_error( $rpc_id, -32603, 'Tool implementation error: missing execute method' );
            }

            // Enforce permission checks defined on ability class
            if ( method_exists( $class_name, 'get_definition' ) ) {
                $def = $class_name::get_definition();
                if ( isset( $def['permission_callback'] ) && is_callable( $def['permission_callback'] ) ) {
                    $permitted = call_user_func( $def['permission_callback'] );
                    if ( ! $permitted ) {
                        return self::json_rpc_error( $rpc_id, -32001, 'Permission denied: unauthorized to invoke tool ' . $tool_name );
                    }
                }
            }

            $output = $class_name::execute( $arguments );

            return new WP_REST_Response( [
                'jsonrpc' => '2.0',
                'id'      => $rpc_id,
                'result'  => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                        ],
                    ],
                ],
            ], 200 );
        } catch ( Throwable $e ) {
            return new WP_REST_Response( [
                'jsonrpc' => '2.0',
                'id'      => $rpc_id,
                'result'  => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Execution error: ' . $e->getMessage(),
                        ],
                    ],
                    'isError' => true,
                ],
            ], 200 );
        }
    }

    /**
     * Handle resources/list JSON-RPC request.
     *
     * @param mixed $rpc_id
     * @return WP_REST_Response
     */
    private static function handle_resources_list( mixed $rpc_id ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'result'  => [
                'resources' => [
                    [
                        'uri'         => 'mhbo://site-knowledge-base',
                        'name'        => 'Property Knowledge Base',
                        'description' => 'Complete markdown context of property amenities, rules, and policies.',
                        'mimeType'    => 'text/markdown',
                    ],
                ],
            ],
        ], 200 );
    }

    /**
     * Handle resources/read JSON-RPC request.
     *
     * @param mixed $rpc_id
     * @param array<string, mixed> $params
     * @return WP_REST_Response
     */
    private static function handle_resources_read( mixed $rpc_id, array $params ): WP_REST_Response {
        $uri = (string) ( $params['uri'] ?? '' );
        if ( 'mhbo://site-knowledge-base' !== $uri ) {
            return self::json_rpc_error( $rpc_id, -32602, 'Resource not found: ' . $uri );
        }

        $snapshot = class_exists( 'MHBO\AI\SiteScanner' ) ? SiteScanner::get_or_build() : '';

        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'result'  => [
                'contents' => [
                    [
                        'uri'      => 'mhbo://site-knowledge-base',
                        'mimeType' => 'text/markdown',
                        'text'     => $snapshot,
                    ],
                ],
            ],
        ], 200 );
    }

    /**
     * Return list of MCP tools formatted for tools/list.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_mcp_tools_list(): array {
        $tools         = [];
        $added_classes = [];

        // Define clean semantic tools list in logical order
        $semantic_tools = [
            'check_availability'  => Abilities\CheckAvailability::class,
            'get_hotel_info'      => Abilities\HotelInfo::class,
            'get_room_details'    => Abilities\RoomDetails::class,
            'get_policies'        => Abilities\Policies::class,
            'get_knowledge_base'  => Abilities\GetKnowledgeBase::class,
            'create_booking_link' => Abilities\CreateBookingLink::class,
            'get_business_card'   => Abilities\GetBusinessCard::class,
            'local_tips'          => Abilities\LocalTips::class,
            'get_price_breakdown' => Abilities\GetPriceBreakdown::class,
            'recommend_rooms'     => Abilities\RecommendRooms::class,
        ];

foreach ( $semantic_tools as $slug => $class_name ) {
            if ( in_array( $class_name, $added_classes, true ) ) {
                continue;
            }
            if ( ! class_exists( $class_name ) ) {
                continue;
            }
            $added_classes[] = $class_name;

            if ( method_exists( $class_name, 'get_definition' ) ) {
                $def     = $class_name::get_definition();
                $tools[] = [
                    'name'        => $slug,
                    'description' => (string) ( $def['description'] ?? '' ),
                    'inputSchema' => $def['input_schema'] ?? (object) [],
                ];
            }
        }

        return $tools;
    }

    /**
     * Map of registered ability slugs/names to their implementing PHP classes.
     * Supports clean semantic names, legacy mhbo_ names, and slashed mhbo/ names.
     *
     * @return array<string, class-string>
     */
    private static function get_ability_map(): array {
        $map = [
            'mhbo_check_availability'  => Abilities\CheckAvailability::class,
            'mhbo/check-availability'  => Abilities\CheckAvailability::class,
            'check_availability'       => Abilities\CheckAvailability::class,

            'mhbo_get_hotel_info'      => Abilities\HotelInfo::class,
            'mhbo/get-hotel-info'      => Abilities\HotelInfo::class,
            'get_hotel_info'           => Abilities\HotelInfo::class,

            'mhbo_get_room_details'    => Abilities\RoomDetails::class,
            'mhbo/get-room-details'    => Abilities\RoomDetails::class,
            'get_room_details'         => Abilities\RoomDetails::class,

            'mhbo_get_policies'        => Abilities\Policies::class,
            'mhbo/get-policies'        => Abilities\Policies::class,
            'get_policies'             => Abilities\Policies::class,

            'mhbo_get_knowledge_base'  => Abilities\GetKnowledgeBase::class,
            'mhbo/get-knowledge-base'  => Abilities\GetKnowledgeBase::class,
            'get_knowledge_base'       => Abilities\GetKnowledgeBase::class,

            'mhbo_create_booking_link' => Abilities\CreateBookingLink::class,
            'mhbo/create-booking-link' => Abilities\CreateBookingLink::class,
            'create_booking_link'      => Abilities\CreateBookingLink::class,

            'mhbo_get_business_card'   => Abilities\GetBusinessCard::class,
            'mhbo/get-business-card'   => Abilities\GetBusinessCard::class,
            'get_business_card'        => Abilities\GetBusinessCard::class,

            'mhbo_local_tips'          => Abilities\LocalTips::class,
            'mhbo/local-tips'          => Abilities\LocalTips::class,
            'local_tips'               => Abilities\LocalTips::class,

            'mhbo_get_price_breakdown' => Abilities\GetPriceBreakdown::class,
            'mhbo/get-price-breakdown' => Abilities\GetPriceBreakdown::class,
            'get_price_breakdown'      => Abilities\GetPriceBreakdown::class,

            'mhbo_recommend_rooms'     => Abilities\RecommendRooms::class,
            'mhbo/recommend-rooms'     => Abilities\RecommendRooms::class,
            'recommend_rooms'          => Abilities\RecommendRooms::class,
        ];

return $map;
    }

    /**
     * WP 7.0 native registration.
     */
    private static function register_native(): void {
        if ( ! function_exists( 'wp_register_mcp_server' ) ) {
            return;
        }

        call_user_func( 'wp_register_mcp_server', [
            'id'          => self::SERVER_ID,
            'name'        => I18n::get_label( 'ai_mcp_name' ),
            'description' => I18n::get_label( 'ai_mcp_description' ),
            'version'     => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '2.4.9',
            'abilities'   => array_keys( self::get_ability_map() ),
            'resources'   => [ self::SERVER_ID . '/site-knowledge-base' ],
        ] );
    }

    /**
     * MCP Adapter registration (WP 6.9 experimental package).
     *
     * @param mixed $adapter The McpAdapter instance.
     */
    public static function register_adapter( $adapter ): void {
        if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
            return;
        }

        try {
            $adapter->create_server(
                self::SERVER_ID,
                self::SERVER_NS,
                self::SERVER_ROUTE,
                I18n::get_label( 'ai_mcp_name' ),
                I18n::get_label( 'ai_mcp_description' ),
                defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '2.4.9',
                [ 'WP\\MCP\\Transport\\Http\\RestTransport' ],
                null,
                null,
                array_keys( self::get_ability_map() )
            );
        } catch ( Throwable $e ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Rationale: Critical exception logging for 3rd-party MCP integrations.
                error_log( 'MHBO MCP Error: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Get client IP safely for rate limiting.
     *
     * @return string
     */
    private static function get_client_ip(): string {
        if ( class_exists( 'MHBO\Core\Security' ) ) {
            return \MHBO\Core\Security::get_client_ip();
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Fallback when Security class is unavailable.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return function_exists( 'sanitize_text_field' ) ? \sanitize_text_field( (string) $ip ) : (string) preg_replace( '/[^0-9a-fA-F\:\.]/', '', (string) $ip );
    }

    /**
     * Helper to set CORS headers.
     */
    private static function set_cors_headers(): void {
        if ( ! headers_sent() ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Version, X-WP-Nonce' );
            header( 'Access-Control-Max-Age: 86400' );
        }
    }

    /**
     * Format a standard JSON-RPC error response.
     *
     * @param mixed $rpc_id
     * @param int $code
     * @param string $message
     * @return WP_REST_Response
     */
    private static function json_rpc_error( mixed $rpc_id, int $code, string $message ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ], 200 );
    }

    /**
     * Return the MCP endpoint URL for display in the admin settings.
     *
     * @return string
     */
    public static function get_endpoint_url(): string {
        return rest_url( self::SERVER_NS . '/' . self::SERVER_ROUTE );
    }
}

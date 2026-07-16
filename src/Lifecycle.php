<?php

declare(strict_types=1);

namespace WPFlame;

/**
 * WordPress hook-to-phase maps used by both early and degraded capture.
 */
class Lifecycle
{
    public static function initial_phase( bool $early_capture ): string
    {
        return $early_capture ? 'Bootstrap' : 'Observed Plugin Bootstrap';
    }

    /**
     * @return array<int, array{hook: string, phase: string}>
     */
    public static function transitions( string $request_type ): array
    {
        $common = [
            [
				'hook' => 'after_setup_theme',
				'phase' => 'WordPress Initialization',
			],
        ];

        switch ( $request_type ) {
            case 'frontend':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'Request Parsing',
					],
                    [
						'hook' => 'parse_request',
						'phase' => 'Main Query Preparation',
					],
                    [
						'hook' => 'pre_get_posts',
						'phase' => 'Main Query',
					],
                    [
						'hook' => 'wp',
						'phase' => 'Template Redirect',
					],
                ] );
            case 'rest':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'REST Routing',
					],
                ] );
            case 'graphql':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'GraphQL Routing',
					],
                ] );
            case 'ajax':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'AJAX Bootstrap',
					],
                    [
						'hook' => 'admin_init',
						'phase' => 'AJAX Dispatch',
					],
                ] );
            case 'admin':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'Admin Bootstrap',
					],
                    [
						'hook' => 'admin_init',
						'phase' => 'Admin Page Execution',
					],
                ] );
            case 'cron':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'Cron Bootstrap',
					],
                    [
						'hook' => 'wp_loaded',
						'phase' => 'Cron Dispatch',
					],
                ] );
            case 'cli':
                return array_merge( $common, [
                    [
						'hook' => 'init',
						'phase' => 'Command Execution',
					],
                ] );
            default:
                return $common;
        }
    }
}

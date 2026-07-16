<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
    }

    public function test_multisite_uninstall_restores_blog_context_in_finally(): void
    {
        $this->assertMatchesRegularExpression(
            '/switch_to_blog\(\s*\(int\)\s*\$blog_id\s*\);\s*try\s*\{\s*wp_flame_uninstall_site\(\);\s*\}\s*finally\s*\{\s*restore_current_blog\(\);\s*\}/s',
            $this->source
        );
    }

    public function test_multisite_uninstall_uses_checkpointed_site_batches(): void
    {
        $this->assertStringContainsString('function wp_flame_uninstall_site_batch( int $offset, int $limit = 10 ): array', $this->source);
        $this->assertStringContainsString("'offset' => max( 0, \$offset )", $this->source);
        $this->assertStringContainsString("get_site_option( 'wp_flame_uninstall_state', [] )", $this->source);
        $this->assertStringContainsString("update_site_option( 'wp_flame_uninstall_state'", $this->source);
        $this->assertStringContainsString('} while ( $wp_flame_uninstall_has_more );', $this->source);
    }

    public function test_mu_plugin_directory_access_is_guarded_on_uninstall(): void
    {
        $this->assertMatchesRegularExpression(
            "/function wp_flame_uninstall_mu_plugin_dir\\(\\): string \\{\\s*return defined\\( 'WPMU_PLUGIN_DIR' \\) && is_string\\( WPMU_PLUGIN_DIR \\).*?\\}/s",
            $this->source
        );

        $unguarded = preg_replace(
            "/function wp_flame_uninstall_mu_plugin_dir\\(\\): string \\{.*?\\n\\}/s",
            '',
            $this->source
        );

        $this->assertStringNotContainsString('WPMU_PLUGIN_DIR', (string) $unguarded);
        $this->assertStringContainsString('$mu_dir = wp_flame_uninstall_mu_plugin_dir();', $this->source);
    }

    public function test_all_capture_tables_are_removed_for_each_site(): void
    {
        $this->assertStringContainsString("[ 'flame_traces', 'flame_sessions', 'flame_environments', 'flame_rollups' ]", $this->source);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `{$table}`', $this->source);
    }

    public function test_uninstall_removes_only_the_last_verified_owned_mu_plugin(): void
    {
        $this->assertStringContainsString("get_option( 'wp_flame_mu_plugin_hash', '' )", $this->source);
        $this->assertStringContainsString('WPFlame\MuPluginManager::remove( $mu_file, $wp_flame_uninstall_mu_hash )', $this->source);
        $this->assertStringNotContainsString('@unlink( $mu_file )', $this->source);
    }
}

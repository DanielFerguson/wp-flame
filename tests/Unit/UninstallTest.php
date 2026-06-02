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

    public function test_multisite_uninstall_queries_all_site_ids_explicitly(): void
    {
        $this->assertStringContainsString('function wp_flame_uninstall_all_site_ids(): array', $this->source);
        $this->assertStringContainsString("'number' => 0", $this->source);
        $this->assertStringContainsString('foreach ( wp_flame_uninstall_all_site_ids() as $blog_id )', $this->source);
        $this->assertStringNotContainsString("get_sites( [ 'fields' => 'ids' ] )", $this->source);
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
}

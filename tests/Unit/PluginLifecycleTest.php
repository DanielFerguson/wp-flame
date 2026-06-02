<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PluginLifecycleTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(dirname(__DIR__, 2) . '/wp-flame.php');
    }

    public function test_mu_plugin_directory_access_is_centralized_behind_guarded_helper(): void
    {
        $this->assertMatchesRegularExpression(
            "/function wp_flame_mu_plugin_dir\\(\\): string \\{\\s*return defined\\( 'WPMU_PLUGIN_DIR' \\).*?\\}/s",
            $this->source
        );

        $unguarded = preg_replace(
            "/function wp_flame_mu_plugin_dir\\(\\): string \\{.*?\\n\\}/s",
            '',
            $this->source
        );

        $this->assertStringNotContainsString('WPMU_PLUGIN_DIR', (string) $unguarded);
    }

    public function test_mu_plugin_install_records_limited_mode_when_directory_is_unavailable(): void
    {
        $this->assertMatchesRegularExpression(
            '/function wp_flame_install_mu_plugin\(\): void \{\s*\$mu_dir = wp_flame_mu_plugin_dir\(\);\s*if \( \$mu_dir === \'\' \) \{\s*update_option\( \'wp_flame_mu_plugin_failed\', true \);\s*return;\s*\}/s',
            $this->source
        );
    }

    public function test_multisite_lifecycle_queries_all_site_ids_explicitly(): void
    {
        $this->assertStringContainsString("function wp_flame_all_site_ids(): array", $this->source);
        $this->assertStringContainsString("'number' => 0", $this->source);
        $this->assertStringContainsString('foreach ( wp_flame_all_site_ids() as $blog_id )', $this->source);
        $this->assertStringNotContainsString("get_sites( [ 'fields' => 'ids' ] )", $this->source);
    }

    public function test_deactivation_skips_mu_plugin_removal_when_directory_is_unavailable(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$mu_dir = wp_flame_mu_plugin_dir\(\);\s*if \( \$mu_dir === \'\' \) \{\s*return;\s*\}\s*\$mu_file = \$mu_dir \. \'\/wp-flame-early-hooks\.php\';/s',
            $this->source
        );
    }
}

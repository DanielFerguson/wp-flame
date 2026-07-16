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
        $this->assertStringContainsString('function wp_flame_install_mu_plugin(): void', $this->source);
        $this->assertStringContainsString('wp_flame_record_mu_plugin_state( WPFlame\MuPluginManager::WRITE_FAILED );', $this->source);
    }

    public function test_mu_plugin_lifecycle_uses_verified_manager_operations(): void
    {
        $this->assertStringContainsString('WPFlame\MuPluginManager::install(', $this->source);
        $this->assertStringContainsString('WPFlame\MuPluginManager::remove(', $this->source);
        $this->assertStringContainsString("'wp_flame_mu_plugin_hash'", $this->source);
        $this->assertStringContainsString('function wp_flame_legacy_mu_plugin_hashes(): array', $this->source);
        $this->assertStringContainsString('wp_flame_legacy_mu_plugin_hashes() );', $this->source);
        $this->assertStringNotContainsString('@copy( $mu_src, $mu_dest )', $this->source);
        $this->assertStringNotContainsString('@unlink( $mu_file )', $this->source);
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

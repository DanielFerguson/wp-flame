<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    public function test_multisite_uninstall_restores_blog_context_in_finally(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');

        $this->assertMatchesRegularExpression(
            '/switch_to_blog\(\s*\(int\)\s*\$blog_id\s*\);\s*try\s*\{\s*wp_flame_uninstall_site\(\);\s*\}\s*finally\s*\{\s*restore_current_blog\(\);\s*\}/s',
            $source
        );
    }
}

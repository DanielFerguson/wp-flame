<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseMetadataTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_release_versions_stay_in_sync(): void
    {
        $mainPlugin = $this->file('wp-flame.php');
        $readme = $this->file('readme.txt');
        $muPlugin = $this->file('mu-plugin/wp-flame-early-hooks.php');
        $changelog = $this->file('CHANGELOG.md');

        $pluginHeader = $this->match('/^\s*\*\s+Version:\s*([^\s]+)/m', $mainPlugin);
        $pluginConstant = $this->match("/define\\( 'WP_FLAME_VERSION', '([^']+)' \\);/", $mainPlugin);
        $stableTag = $this->match('/^Stable tag:\s*([^\s]+)/m', $readme);
        $muVersion = $this->match("/define\\( 'WP_FLAME_MU_VERSION', '([^']+)' \\);/", $muPlugin);

        $this->assertMatchesRegularExpression(
            '/^[0-9]+(\.[0-9]+){2}([-.][0-9A-Za-z][0-9A-Za-z.-]*)?$/',
            $pluginHeader
        );
        $this->assertSame($pluginHeader, $pluginConstant);
        $this->assertSame($pluginHeader, $stableTag);
        $this->assertSame($pluginHeader, $muVersion);
        $this->assertStringContainsString('## [' . $pluginHeader . ']', $changelog);
    }

    public function test_compat_smoke_sql_helpers_validate_dynamic_values(): void
    {
        $script = $this->file('bin/compat-smoke.sh');

        $this->assertStringContainsString('sql_uint()', $script);
        $this->assertStringContainsString('sql_like_literal()', $script);
        $this->assertStringContainsString('after_id="$(sql_uint "$after_id")"', $script);
        $this->assertStringContainsString('pattern="$(sql_like_literal "$pattern")"', $script);
        $this->assertStringContainsString("ESCAPE '\\\\\\\\'", $script);
    }

    public function test_ci_workflow_keeps_release_readiness_gates(): void
    {
        $workflow = $this->file('.github/workflows/ci.yml');

        foreach ([
            "php: ['7.4', '8.1', '8.3', '8.4']",
            "wp: '6.0'",
            'composer validate --strict',
            'composer audit',
            'composer lint',
            'composer test:unit',
            'WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite integration --do-not-cache-result',
            'npm ci',
            'npm audit',
            'node --check assets/js/admin.js',
            'node --check assets/js/admin-bar.js',
            'node --check assets/js/flame-graph.js',
            'npm run test:js',
            'npm run compat:smoke',
            'npm run wp-env:stop',
            'WP_FLAME_ALLOW_UNRELEASED_CHANGELOG=1 bash bin/build-zip.sh',
            'unzip -t wp-flame-*.zip',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
    }

    public function test_build_script_rejects_untracked_packaged_runtime_files(): void
    {
        $script = $this->file('bin/build-zip.sh');

        $this->assertStringContainsString('git ls-files --others --exclude-standard --', $script);
        $this->assertStringContainsString('wp-flame.php uninstall.php readme.txt README.md CHANGELOG.md composer.json composer.lock', $script);
        $this->assertStringContainsString('src assets mu-plugin', $script);
        $this->assertStringContainsString('UNTRACKED_RUNTIME_FILES', $script);
        $this->assertStringContainsString('Refusing to build with untracked files in packaged runtime paths:', $script);
    }

    public function test_build_script_allows_dirty_local_build_escape_hatch_for_packaged_untracked_files(): void
    {
        $script = $this->file('bin/build-zip.sh');

        $this->assertStringContainsString('"${WP_FLAME_ALLOW_DIRTY_BUILD:-}" != "1" && -n "$UNTRACKED_RUNTIME_FILES"', $script);
        $this->assertStringContainsString('WP_FLAME_ALLOW_DIRTY_BUILD=1 for a local test build', $script);
    }

    public function test_build_script_validates_final_zip_manifest(): void
    {
        $script = $this->file('bin/build-zip.sh');

        $this->assertStringContainsString('ZIP_MANIFEST=$(unzip -Z1 "$OUTFILE")', $script);
        $this->assertStringContainsString('(^|/)(tests|node_modules|\\.github|bin)/', $script);
        $this->assertStringContainsString('(^|/)(\\.DS_Store|\\.gitignore|\\.phpunit\\.result\\.cache|\\.wp-env\\.json|package\\.json|package-lock\\.json|composer\\.json|composer\\.lock)$', $script);
        $this->assertStringContainsString('Build artifact contains dev-only directories.', $script);
        $this->assertStringContainsString('Build artifact contains dev-only files.', $script);
        $this->assertStringContainsString(
            "if echo \"\$ZIP_MANIFEST\" | grep -Eq '(^|/)(tests|node_modules|\\.github|bin)/'; then\n    rm -f \"\$OUTFILE\"",
            $script
        );
        $this->assertStringContainsString(
            "if echo \"\$ZIP_MANIFEST\" | grep -Eq '(^|/)(\\.DS_Store|\\.gitignore|\\.phpunit\\.result\\.cache|\\.wp-env\\.json|package\\.json|package-lock\\.json|composer\\.json|composer\\.lock)$'; then\n    rm -f \"\$OUTFILE\"",
            $script
        );
    }

    private function file(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        $this->assertIsString($contents, $path . ' should be readable');

        return $contents;
    }

    private function match(string $pattern, string $contents): string
    {
        $matched = preg_match($pattern, $contents, $matches);
        $this->assertSame(1, $matched, 'Expected metadata pattern to match');

        return $matches[1];
    }
}

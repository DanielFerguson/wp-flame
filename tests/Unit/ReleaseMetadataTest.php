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
        $this->assertStringContainsString('= ' . $pluginHeader . ' =', $readme);
        $this->assertStringContainsString('License URI: https://www.gnu.org/licenses/gpl-2.0.html', $mainPlugin);
        $this->assertStringContainsString('class_exists( WPFlame\\Lifecycle::class )', $muPlugin);
        $this->assertStringContainsString("? WPFlame\\Lifecycle::initial_phase( true )\n    : 'Bootstrap';", $muPlugin);
    }

    public function test_compat_smoke_sql_helpers_validate_dynamic_values(): void
    {
        $script = $this->file('bin/compat-smoke.sh');

        $this->assertStringContainsString('sql_uint()', $script);
        $this->assertStringContainsString('sql_result_uint()', $script);
        $this->assertStringContainsString('sql_like_literal()', $script);
        $this->assertStringContainsString('after_id="$(sql_uint "$after_id")"', $script);
        $this->assertStringContainsString('pattern="$(sql_like_literal "$pattern")"', $script);
        $this->assertStringContainsString('sql_result_uint "$(wp_cli db query "SELECT COUNT(*) FROM ${prefix}flame_traces" --skip-column-names)"', $script);
        $this->assertStringContainsString('sql_result_uint "$(wp_cli db query "SELECT COALESCE(MAX(id), 0) FROM ${prefix}flame_traces" --skip-column-names)"', $script);
        $this->assertStringContainsString("ESCAPE '\\\\\\\\'", $script);
        $this->assertStringContainsString('wp_cli plugin deactivate "$PLUGIN_SLUG"', $script);
        $this->assertStringContainsString('WP_FLAME_MU_VERSION !== WP_FLAME_VERSION', $script);

        $browser = $this->file('tests/e2e/wp-flame.spec.js');
        $this->assertStringContainsString('test.beforeAll(() => {', $browser);
        $this->assertStringContainsString("wpEnv(['plugin', 'deactivate', 'wp-flame']);", $browser);
        $this->assertStringContainsString('WP_FLAME_MU_VERSION !== WP_FLAME_VERSION', $browser);
    }

    public function test_ci_workflow_keeps_release_readiness_gates(): void
    {
        $workflow = $this->file('.github/workflows/ci.yml');

        foreach ([
            "php: ['7.4', '8.1', '8.3', '8.4', '8.5']",
            "tags: ['v*']",
            "wp: '6.0'",
            'mariadb:11.4',
            'composer validate --strict',
            'composer audit',
            'composer lint',
            'composer standards',
            'composer analyse',
            'composer test:coverage',
            'composer test:unit',
            'WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite integration --exclude-group multisite --fail-on-skipped --do-not-cache-result',
            'WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_MULTISITE=1 ./vendor/bin/phpunit --testsuite integration --fail-on-skipped --do-not-cache-result',
            'npm ci',
            'npm audit',
            'node --check assets/js/admin.js',
            'node --check assets/js/admin-bar.js',
            'node --check assets/js/flame-graph.js',
            'npm run test:js',
            'npm run compat:smoke',
            'npm run test:e2e',
            'npm run test:performance',
            'fetch-depth: 0',
            'bin/build-previous-package.sh e58bef570e8e6b53925796de693937957765b581 build/previous/wp-flame-1.2.0.zip',
            'bash bin/package-lifecycle-smoke.sh build/previous/wp-flame-1.2.0.zip wp-flame-1.3.0-rc.1.zip',
            'npm run wp-env:stop',
            'wordpress/plugin-check-action@v1.1.7',
            'bash bin/build-zip.sh',
            'bin/release-package.sh',
            'actions/upload-artifact@v4',
            'path: dist/**',
            'unzip -t wp-flame-*.zip',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
    }

    public function test_build_script_rejects_untracked_packaged_runtime_files(): void
    {
        $script = $this->file('bin/build-zip.sh');

        $this->assertStringContainsString('git ls-files --others --exclude-standard --', $script);
        $this->assertStringContainsString('wp-flame.php uninstall.php readme.txt README.md CHANGELOG.md LICENSE SECURITY.md composer.json composer.lock', $script);
        $this->assertStringContainsString('src assets mu-plugin docs/FEATURE-GUIDE.md docs/QUICKSTART.md docs/MODE-AND-OVERHEAD.md', $script);
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

    public function test_release_package_contains_the_gpl_license(): void
    {
        $license = $this->file('LICENSE');
        $script = $this->file('bin/build-zip.sh');

        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $license);
        $this->assertStringContainsString('Version 2, June 1991', $license);
        $this->assertStringContainsString('CHANGELOG.md LICENSE SECURITY.md composer.json composer.lock', $script);
        $this->assertStringContainsString('"LICENSE"', $script);
    }

    public function test_release_package_contains_every_linked_operator_document(): void
    {
        $readme = $this->file('README.md');
        $script = $this->file('bin/build-zip.sh');
        $packageDocuments = [
            'README.md',
            'SECURITY.md',
            'docs/FEATURE-GUIDE.md',
            'docs/QUICKSTART.md',
            'docs/MODE-AND-OVERHEAD.md',
            'docs/PRIVACY.md',
            'docs/SUPPORT.md',
            'docs/UPDATE-AND-LICENSE-POLICY.md',
            'docs/architecture/RC-DISTRIBUTION.md',
            'docs/benchmarks/M6-OVERHEAD-RESULTS.md',
            'docs/compatibility/MATRIX.md',
        ];

        foreach ($packageDocuments as $path) {
            $this->assertFileExists($this->root . '/' . $path);
            $this->assertStringContainsString($path, $script, $path . ' must be copied into the release package');

            preg_match_all('/\]\(([^)]+)\)/', $this->file($path), $matches);
            foreach (array_unique($matches[1]) as $target) {
                if ($target === '' || $target[0] === '#' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
                    continue;
                }

                $target = explode('#', $target, 2)[0];
                $resolved = realpath($this->root . '/' . dirname($path) . '/' . $target);
                $this->assertIsString($resolved, $path . ' has a missing relative link: ' . $target);
                $relative = substr($resolved, strlen($this->root) + 1);
                $this->assertContains($relative, $packageDocuments, $path . ' links to an unpackaged document: ' . $relative);
            }
        }

        $this->assertStringContainsString('docs/QUICKSTART.md', $readme);
        $this->assertStringNotContainsString('TERMS-OF-SALE-DRAFT.md', $readme);
        $this->assertStringNotContainsString('PUBLICATION-INPUTS.md', $readme);
    }

    public function test_release_package_requires_a_clean_exact_tag_and_proves_reproducibility(): void
    {
        $script = $this->file('bin/release-package.sh');
        $builder = $this->file('bin/build-zip.sh');

        foreach ([
            'git status --porcelain --untracked-files=all',
            'git describe --tags --exact-match HEAD',
            'Repeated builds from the same tag were not byte-identical.',
            'shasum -a 256',
            'wp-flame-release-provenance.v1',
            'repeated_build_identical',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }

        $this->assertStringContainsString('SOURCE_DATE_EPOCH', $builder);
        $this->assertStringContainsString('LC_ALL=C sort', $builder);
        $this->assertStringContainsString('zip -q -X', $builder);
    }

    public function test_package_lifecycle_gate_covers_install_upgrade_rollback_and_cleanup(): void
    {
        $script = $this->file('bin/package-lifecycle-smoke.sh');

        foreach ([
            'Clean candidate install, deactivate/reactivate, and uninstall.',
            'Forward upgrade, application-package rollback, and return to the candidate.',
            'wp_cli flame migrate --until-complete',
            'assert_table_state present',
            'assert_table_state absent',
            'deactivation mu-plugin cleanup',
            'forward upgrade legacy early-loader migration',
            'rollback early-loader version',
            'rollback data',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }

        $builder = $this->file('bin/build-previous-package.sh');
        $this->assertStringContainsString('e58bef570e8e6b53925796de693937957765b581', $builder);
        $this->assertStringContainsString('git archive "$PREVIOUS_REF"', $builder);
        $this->assertStringContainsString('WP_FLAME_ALLOW_UNRELEASED_CHANGELOG=1', $builder);
    }

    public function test_public_claims_match_the_measurement_contract(): void
    {
        $readme = explode('== Changelog ==', $this->file('readme.txt'), 2)[0];
        $surfaces = [
            'plugin header and bootstrap' => $this->file('wp-flame.php'),
            'repository readme'           => $this->file('README.md'),
            'WordPress.org description'   => $readme,
            'feature guide'               => $this->file('docs/FEATURE-GUIDE.md'),
            'settings copy'               => $this->file('src/Settings.php'),
            'score labels'                => $this->file('src/Score.php'),
            'dashboard labels'            => $this->file('src/Admin/ListView.php'),
            'flame graph labels'          => $this->file('assets/js/flame-graph.js'),
            'early HTTP insight'          => $this->file('src/Rules/HttpDuringEarlyPhases.php'),
        ];

        $prohibited = [
            '/see exactly where/i',
            '/tells? you exactly (?:what|which)/i',
            '/instruments? the full request lifecycle/i',
            '/times? every individual plugin and theme function/i',
            '/captures? every SQL query/i',
            '/tracks? every external HTTP call/i',
            '/<\s*1ms per request/i',
            '/all other features work normally/i',
            '/disable for GDPR compliance/i',
            '/max page load time/i',
            '/blocks page load/i',
            '/>Full request</i',
        ];

        foreach ($surfaces as $name => $contents) {
            foreach ($prohibited as $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $contents),
                    $name . ' must not contain unsupported claim ' . $pattern
                );
            }
        }

        $this->assertStringContainsString('observed WordPress request time', $surfaces['plugin header and bootstrap']);
        $this->assertStringContainsString('Observed lifecycle spans', $surfaces['WordPress.org description']);
        $this->assertStringContainsString('not a complete PHP call-stack profiler', $surfaces['feature guide']);
        $this->assertStringContainsString('Max observed request duration', $surfaces['settings copy']);
        $this->assertStringContainsString('Observed Duration', $surfaces['score labels']);
        $this->assertStringContainsString('Observed request', $surfaces['flame graph labels']);
    }

    public function test_community_and_pro_share_the_measurement_core(): void
    {
        $boundary = $this->file('docs/architecture/COMMUNITY-PRO-BOUNDARY.md');

        foreach ([
            'WP Flame Community and WP Flame Pro must share the same measurement truth.',
            'Community owns the measurement core',
            'Pro may add recurring workflow value',
            'Pro guidance may add domain context, but it cannot withhold an essential finding',
            'Licensing and update failures do not interrupt local tracing.',
            'Measurement truth may not.',
        ] as $required) {
            $this->assertStringContainsString($required, $boundary);
        }
    }

    public function test_rc_distribution_has_no_wp_flame_service_dependency(): void
    {
        $decision = $this->file('docs/architecture/RC-DISTRIBUTION.md');
        $policy = $this->file('docs/UPDATE-AND-LICENSE-POLICY.md');
        $terms = $this->file('docs/TERMS-OF-SALE-DRAFT.md');

        foreach ([
            'one complete GPLv2 plugin package',
            'no Community/Pro code split',
            'no runtime licensing dependency',
            'SHA-256 checksum',
            'supplied manually',
        ] as $required) {
            $this->assertStringContainsString($required, $decision);
        }

        $this->assertStringContainsString('has no software entitlement check or WP Flame update client', $policy);
        $this->assertStringContainsString('does not remove rights granted by the GPL', $terms);
        $this->assertStringContainsString('does not remotely disable installed code', $terms);
        $this->assertStringContainsString('A vulnerability scanner or security-monitoring service.', $terms);

        $runtimeFiles = [
            $this->root . '/wp-flame.php',
            $this->root . '/uninstall.php',
            $this->root . '/mu-plugin/wp-flame-early-hooks.php',
        ];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $runtimeFiles[] = $item->getPathname();
            }
        }

        $initiators = [
            '/\bwp_remote_(?:get|post|head|request)\s*\(/i',
            '/\bRequests::(?:request|get|post)\s*\(/i',
            '/\bcurl_exec\s*\(/i',
            '/\bfsockopen\s*\(/i',
            '/\bstream_socket_client\s*\(/i',
        ];

        foreach ($runtimeFiles as $path) {
            $contents = file_get_contents($path);
            $this->assertIsString($contents, $path . ' should be readable');
            foreach ($initiators as $pattern) {
                $this->assertSame(0, preg_match($pattern, $contents), $path . ' must not initiate an outbound service request');
            }
        }
    }

    public function test_foundational_quality_gates_use_committed_baselines(): void
    {
        $composer = $this->file('composer.json');
        $phpcs = $this->file('quality/phpcs-baseline.json');
        $phpstan = $this->file('quality/phpstan-baseline.neon');
        $coverage = $this->file('quality/coverage-baseline.json');

        foreach ([
            'bin/check-phpcs-baseline.php',
            'quality/phpstan.neon.dist',
            'bin/check-coverage-baseline.php',
        ] as $required) {
            $this->assertStringContainsString($required, $composer);
        }

        $this->assertStringContainsString('"schema_version": 1', $phpcs);
        $this->assertStringContainsString('ignoreErrors:', $phpstan);
        $this->assertStringContainsString('"minimum_statement_coverage_percent": 63.41', $coverage);
    }

    public function test_force_trace_success_and_stored_action_follow_successful_insert(): void
    {
        $bootstrap = $this->file('wp-flame.php');
        $shutdown = explode('function wp_flame_shutdown(): void {', $bootstrap, 2)[1];
        $shutdown = explode('// --- Cron handler ---', $shutdown, 2)[0];

        $save = strpos($shutdown, '$storage_result = $storage->save_trace(');
        $guard = strpos($shutdown, 'if ( ! $storage_result->success )');
        $notice = strpos($shutdown, "set_transient( 'wp_flame_last_force_trace_");
        $action = strpos($shutdown, "do_action( 'wp_flame_trace_stored'");

        $this->assertIsInt($save);
        $this->assertIsInt($guard);
        $this->assertIsInt($notice);
        $this->assertIsInt($action);
        $this->assertLessThan($guard, $save);
        $this->assertLessThan($notice, $guard);
        $this->assertLessThan($action, $notice);
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

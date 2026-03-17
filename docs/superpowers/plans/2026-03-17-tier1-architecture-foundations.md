# Tier 1: Architecture Foundations — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 14 pre-launch architectural foundations that unblock the roadmap (site profiles, knowledge base, N+1 detection, telemetry).

**Architecture:** Small, independent changes to the existing codebase. No new subsystems — just extension hooks, hardening, and correctness fixes. Each task produces a working, testable commit.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, PHPUnit (existing test suite)

**Spec:** `docs/superpowers/specs/2026-03-17-architecture-review-design.md`

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `wp-flame.php` | Version constant fix, hooks, Config wiring, IP/user meta removal, mu-plugin version check |
| Modify | `src/Storage.php` | Schema versioning, encode/insert checks, get_trace() column augmentation |
| Modify | `src/Trace.php` | JSON schema version |
| Modify | `src/Score.php` | Score factors filter hook |
| Modify | `src/Admin.php` | Read user/IP from DB columns, insight filter hook |
| Modify | `src/Http.php` | http_api_debug fallback for WP_Error |
| Modify | `src/DB.php` | Use SourceResolver, use Config |
| Modify | `src/GraphQL.php` | Use SourceResolver, use Config |
| Modify | `src/CLI.php` | Use Config |
| Modify | `src/Settings.php` | Full query text privacy warning |
| Modify | `mu-plugin/wp-flame-early-hooks.php` | Version constant, version drift support |
| Modify | `uninstall.php` | Transient + cron cleanup |
| Create | `src/Config.php` | Centralized settings with per-request overrides |
| Create | `src/SourceResolver.php` | Shared backtrace-based source attribution |
| Create | `src/Privacy.php` | GDPR data export and erasure |
| Create | `tests/Unit/ConfigTest.php` | Config class tests |
| Create | `tests/Unit/SourceResolverTest.php` | SourceResolver tests |
| Create | `tests/Unit/PrivacyTest.php` | Privacy hook registration tests |

---

## Execution Order

Tasks are ordered by dependencies. Independent tasks are grouped where they touch the same file.

```
Task 1  (1.14 — version constant)
   │
   ▼
Task 2  (1.12 — mu-plugin version drift)
Task 3  (1.5 — JSON schema version)          ← independent
Task 4  (1.3 — schema version tracking)      ← independent
Task 5  (1.11 — uninstall cleanup)           ← independent
Task 6  (1.6 + 1.7 — storage hardening)      ← independent
Task 7  (1.4 — remove IP/user from meta)     ← depends on understanding Storage
Task 8  (1.8 — GDPR hooks)                   ← depends on Task 7
Task 9  (1.13 — score extension hook)        ← independent
Task 10 (1.10 — SourceResolver)              ← independent
Task 11 (1.9 — HTTP error handling)          ← independent
Task 12 (1.2 — Config class)                 ← independent
Task 13 (1.1 — core hooks/filters)           ← independent
Task 14 (3.14 — full query text warning)     ← independent, tiny
```

---

## Task 1: Fix WP_FLAME_VERSION Constant (Spec 1.14)

**Files:**
- Modify: `wp-flame.php:21`

- [ ] **Step 1: Fix the version constant**

In `wp-flame.php`, line 21 currently reads:

```php
define( 'WP_FLAME_VERSION', '1.0.0' );
```

Change to match the plugin header (line 7: `Version: 1.1.1`):

```php
define( 'WP_FLAME_VERSION', '1.1.1' );
```

- [ ] **Step 2: Verify no tests break**

Run: `cd /Users/danielferguson/repositories/wp-flame && ./vendor/bin/phpunit --testsuite unit`
Expected: All tests pass (this is a constant-only change).

- [ ] **Step 3: Commit**

```bash
git add wp-flame.php
git commit -m "fix: align WP_FLAME_VERSION constant with plugin header (1.1.1)"
```

---

## Task 2: mu-plugin Version Drift Detection (Spec 1.12)

**Files:**
- Modify: `mu-plugin/wp-flame-early-hooks.php:16` (add version constant)
- Modify: `wp-flame.php` (add version check in init, add admin notice)

- [ ] **Step 1: Add version constant to mu-plugin**

In `mu-plugin/wp-flame-early-hooks.php`, after line 16 (`$wp_flame_request_start = microtime( true );`), add:

```php
define( 'WP_FLAME_MU_VERSION', '1.1.1' );
```

- [ ] **Step 2: Add version drift check and auto-update to wp_flame_init()**

In `wp-flame.php`, inside `wp_flame_init()` after the enabled check (after line 119), add:

```php
	// Check for mu-plugin version drift and auto-update if needed.
	if ( defined( 'WP_FLAME_MU_VERSION' ) && WP_FLAME_MU_VERSION !== WP_FLAME_VERSION ) {
		$mu_source = WP_FLAME_DIR . 'mu-plugin/wp-flame-early-hooks.php';
		$mu_dest   = WPMU_PLUGIN_DIR . '/wp-flame-early-hooks.php';
		if ( file_exists( $mu_source ) ) {
			@copy( $mu_source, $mu_dest );
		}
	}
```

- [ ] **Step 3: Verify no tests break**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 4: Commit**

```bash
git add mu-plugin/wp-flame-early-hooks.php wp-flame.php
git commit -m "feat: add mu-plugin version drift detection and auto-update"
```

---

## Task 3: Add JSON Schema Version to Trace Data (Spec 1.5)

**Files:**
- Modify: `src/Trace.php:64-80` (toArray), `src/Trace.php:82-101` (fromArray)
- Modify: `tests/Unit/TraceTest.php`

- [ ] **Step 1: Write failing test for schema version in serialization**

Add to `tests/Unit/TraceTest.php`:

```php
public function test_to_array_includes_schema_version(): void
{
    $trace = new Trace(
        'test-id', '/test', 'GET', '2024-01-01T00:00:00Z',
        100.0, 1024, '8.1', '6.4', [], []
    );
    $arr = $trace->toArray();
    $this->assertArrayHasKey('v', $arr);
    $this->assertSame(1, $arr['v']);
}

public function test_from_array_handles_missing_version(): void
{
    $data = [
        'id' => 'test-id', 'url' => '/test', 'method' => 'GET',
        'timestamp' => '2024-01-01T00:00:00Z', 'total_ms' => 100.0,
        'peak_memory' => 1024, 'php_version' => '8.1', 'wp_version' => '6.4',
        'spans' => [], 'meta' => [],
    ];
    // No 'v' key — should still deserialize fine (default to v1)
    $trace = Trace::fromArray($data);
    $this->assertSame('test-id', $trace->id);
}
```

- [ ] **Step 2: Run tests to verify the first test fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter test_to_array_includes_schema_version`
Expected: FAIL — no 'v' key in output.

- [ ] **Step 3: Add schema version to Trace**

In `src/Trace.php`, in `toArray()` (line 64), add `'v' => 1` as the first key in the returned array:

```php
    public function toArray(): array
    {
        return [
            'v'            => 1,
            'id'           => $this->id,
```

In `fromArray()` (line 82), add version extraction at the top of the method (before existing logic):

```php
    public static function fromArray( array $data ): self
    {
        $version = isset( $data['v'] ) ? (int) $data['v'] : 1;
        // Future: if ( $version < 2 ) { $data = self::migrate_v1_to_v2( $data ); }

        $spans = array_map(
```

- [ ] **Step 4: Run all unit tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass including new tests.

- [ ] **Step 5: Commit**

```bash
git add src/Trace.php tests/Unit/TraceTest.php
git commit -m "feat: add JSON schema version (v:1) to trace serialization"
```

---

## Task 4: Schema Version Tracking in Storage (Spec 1.3)

**Files:**
- Modify: `src/Storage.php` (add constant + maybe_upgrade method)
- Modify: `wp-flame.php` (call maybe_upgrade at init)

- [ ] **Step 1: Add schema version constant and maybe_upgrade() to Storage**

In `src/Storage.php`, add a class constant after line 13:

```php
    const SCHEMA_VERSION = 1;
```

Add a new public method after `create_table()` (after line 48):

```php
    /**
     * Run schema migrations if needed.
     */
    public function maybe_upgrade(): void
    {
        $current = (int) get_option( 'wp_flame_schema_version', 0 );
        if ( $current >= self::SCHEMA_VERSION ) {
            return;
        }

        $this->create_table();

        update_option( 'wp_flame_schema_version', self::SCHEMA_VERSION );
    }
```

- [ ] **Step 2: Call maybe_upgrade() from wp_flame_init()**

In `wp-flame.php`, inside `wp_flame_init()`, after the `$storage` variable is first created (find where `$storage = new WPFlame\Storage( $wpdb );` appears — it's used in the admin/settings/shutdown contexts). The most appropriate place is right after the enabled check and before other logic. Add after the mu-plugin version drift check:

```php
	// Run schema migrations if needed (cheap no-op when current).
	if ( (int) get_option( 'wp_flame_schema_version', 0 ) < WPFlame\Storage::SCHEMA_VERSION ) {
		$upgrade_storage = new WPFlame\Storage( $wpdb );
		$upgrade_storage->maybe_upgrade();
		unset( $upgrade_storage );
	}
```

- [ ] **Step 3: Also set schema version on activation**

In `wp-flame.php`, in `wp_flame_activate()`, after `$storage->create_table()` (line 40), add:

```php
	update_option( 'wp_flame_schema_version', WPFlame\Storage::SCHEMA_VERSION );
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add src/Storage.php wp-flame.php
git commit -m "feat: add schema version tracking with maybe_upgrade() migration runner"
```

---

## Task 5: Fix uninstall.php Cleanup (Spec 1.11)

**Files:**
- Modify: `uninstall.php`

- [ ] **Step 1: Add transient and cron cleanup**

In `uninstall.php`, before the final closing (after the mu-plugin removal block, around line 31), add:

```php
// Clear scheduled cron events.
wp_clear_scheduled_hook( 'wp_flame_prune_traces' );

// Delete transients (not matched by the wp_flame_% option cleanup above).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wp\_flame\_%' OR option_name LIKE '\_transient\_timeout\_wp\_flame\_%'" );
```

Also add `wp_flame_schema_version` to the cleanup. The existing `DELETE ... WHERE option_name LIKE 'wp\_flame\_%'` at line 22-25 already covers this since `wp_flame_schema_version` matches the pattern. Verify this is correct by checking the existing query.

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 3: Commit**

```bash
git add uninstall.php
git commit -m "fix: clean up transients and cron schedule on uninstall"
```

---

## Task 6: Storage Hardening — Encode and Insert Checks (Spec 1.6, 1.7)

**Files:**
- Modify: `src/Storage.php:50-72` (save_trace method)

- [ ] **Step 1: Add encode check and insert error handling**

Replace the `save_trace()` method body. Current code at lines 50-72 builds `$data` and calls `$this->wpdb->insert()`. Modify the method to:

1. Check `wp_json_encode()` return value with UTF-8 fallback
2. Check `$this->wpdb->insert()` return value

In `src/Storage.php`, replace the line that builds `trace_data` in the `$data` array and the insert call. Find the current pattern:

```php
            'trace_data'  => wp_json_encode( $trace->toArray() ),
```

Replace the method body while preserving the existing signature and `current_time()` call:

```php
    public function save_trace( Trace $trace, ?int $score = null, int $user_id = 0, string $ip_address = '' ): void
    {
        $json = wp_json_encode( $trace->toArray() );
        if ( $json === false ) {
            $json = wp_json_encode( $trace->toArray(), JSON_INVALID_UTF8_SUBSTITUTE );
            if ( $json === false ) {
                error_log( 'WP Flame: Failed to encode trace ' . $trace->id );
                return;
            }
        }

        $data    = [
            'trace_id'    => $trace->id,
            'url'         => $trace->url,
            'method'      => $trace->method,
            'total_ms'    => $trace->total_ms,
            'query_count' => $trace->query_count,
            'peak_memory' => $trace->peak_memory,
            'created_at'  => current_time( 'mysql', true ),
            'user_id'     => $user_id,
            'ip_address'  => $ip_address,
            'trace_data'  => $json,
        ];
        $formats = [ '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%s', '%s' ];

        if ( $score !== null ) {
            $data['score'] = $score;
            $formats[]     = '%d';
        }

        $result = $this->wpdb->insert( $this->table, $data, $formats );
        if ( $result === false ) {
            error_log( 'WP Flame: Failed to save trace ' . $trace->id . ': ' . $this->wpdb->last_error );
        }
    }
```

Note: The signature preserves the existing default values (`$score = null`, `$user_id = 0`, `$ip_address = ''`). The `current_time('mysql', true)` call is preserved as-is — it's a WordPress function that returns UTC time.

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 3: Commit**

```bash
git add src/Storage.php
git commit -m "fix: check wp_json_encode and wpdb insert return values in save_trace"
```

---

## Task 7: Stop Storing IP/User ID in Trace Meta (Spec 1.4)

**Files:**
- Modify: `wp-flame.php:364-406` (shutdown handler meta building)
- Modify: `src/Storage.php:74-93` (get_trace must return row columns)
- Modify: `src/Admin.php` (read user/IP from new source)

- [ ] **Step 1: Remove user_id and ip_address from request_meta**

In `wp-flame.php`, in `wp_flame_shutdown()`, the metadata block at lines 364-382 currently builds `$request_meta` with `user_id` and `ip_address`. These values are also passed separately to `save_trace()` at lines 404-405.

Change the metadata block. Find and modify (around lines 374-382):

Currently:
```php
		$request_meta['user_id']    = get_current_user_id();
		$request_meta['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
			: '';

		$track_ips                    = get_option( 'wp_flame_track_ips', true );
		$request_meta['ip_address']   = $track_ips ? wp_flame_get_client_ip() : '';
```

Replace with:
```php
		$user_id = get_current_user_id();
		$request_meta['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
			: '';

		$track_ips  = get_option( 'wp_flame_track_ips', true );
		$ip_address = $track_ips ? wp_flame_get_client_ip() : '';
```

Then update the `save_trace()` call (around lines 401-406) to use the local variables instead of `$request_meta` keys:

```php
		$storage->save_trace( $trace, $score_result['score'], $user_id, $ip_address );
```

- [ ] **Step 2: Update Storage::get_trace() to return row columns**

In `src/Storage.php`, modify `get_trace()` (lines 74-93) to also SELECT the row-level columns and attach them to the Trace:

```php
    public function get_trace( string $trace_id ): ?Trace
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT trace_data, user_id, ip_address, score, created_at FROM {$this->table} WHERE trace_id = %s",
                $trace_id
            )
        );

        if ( ! $row || empty( $row->trace_data ) ) {
            return null;
        }

        $data = json_decode( $row->trace_data, true );
        if ( ! is_array( $data ) ) {
            error_log( 'WP Flame: Failed to decode trace ' . $trace_id . ': ' . json_last_error_msg() );
            return null;
        }

        $trace = Trace::fromArray( $data );

        // Attach row-level columns that are NOT stored in trace_data JSON.
        $trace->meta['_row_user_id']    = (int) $row->user_id;
        $trace->meta['_row_ip_address'] = (string) $row->ip_address;
        $trace->meta['_row_score']      = $row->score !== null ? (int) $row->score : null;
        $trace->meta['_row_created_at'] = (string) $row->created_at;

        return $trace;
    }
```

- [ ] **Step 3: Update Admin flame graph view to read from row columns**

In `src/Admin.php`, find where `$trace->meta['user_id']` and `$trace->meta['ip_address']` are referenced in the flame graph view. Replace all occurrences:

- `$trace->meta['user_id']` → `$trace->meta['_row_user_id'] ?? 0`
- `$trace->meta['ip_address']` → `$trace->meta['_row_ip_address'] ?? ''`

Use grep to find all occurrences and replace each one.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass. Some trace tests may need updating if they assert on meta keys.

- [ ] **Step 5: Commit**

```bash
git add wp-flame.php src/Storage.php src/Admin.php
git commit -m "fix: stop storing IP/user_id in trace meta, read from DB columns instead"
```

---

## Task 8: GDPR Data Export/Erasure Hooks (Spec 1.8)

**Files:**
- Create: `src/Privacy.php`
- Create: `tests/Unit/PrivacyTest.php`
- Modify: `wp-flame.php` (wire Privacy class)

- [ ] **Step 1: Write failing test for Privacy class**

Create `tests/Unit/PrivacyTest.php` (using a mock for Storage from the start since the constructor requires it):

```php
<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Privacy;
use WPFlame\Storage;

class PrivacyTest extends TestCase
{
    private function make_privacy(): Privacy
    {
        $storage = $this->createMock(Storage::class);
        return new Privacy($storage);
    }

    public function test_exporter_returns_correct_structure(): void
    {
        $result = $this->make_privacy()->get_exporter();

        $this->assertArrayHasKey('exporter_friendly_name', $result);
        $this->assertArrayHasKey('callback', $result);
        $this->assertSame('WP Flame Performance Data', $result['exporter_friendly_name']);
        $this->assertIsCallable($result['callback']);
    }

    public function test_eraser_returns_correct_structure(): void
    {
        $result = $this->make_privacy()->get_eraser();

        $this->assertArrayHasKey('eraser_friendly_name', $result);
        $this->assertArrayHasKey('callback', $result);
        $this->assertSame('WP Flame Performance Data', $result['eraser_friendly_name']);
        $this->assertIsCallable($result['callback']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter PrivacyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create Privacy class**

Create `src/Privacy.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Privacy
{
    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    /**
     * Register GDPR hooks with WordPress.
     */
    public function register(): void
    {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
    }

    /**
     * @param array $exporters
     * @return array
     */
    public function register_exporter( array $exporters ): array
    {
        $exporters['wp-flame'] = $this->get_exporter();
        return $exporters;
    }

    /**
     * @param array $erasers
     * @return array
     */
    public function register_eraser( array $erasers ): array
    {
        $erasers['wp-flame'] = $this->get_eraser();
        return $erasers;
    }

    /**
     * @return array{exporter_friendly_name: string, callback: callable}
     */
    public function get_exporter(): array
    {
        return [
            'exporter_friendly_name' => 'WP Flame Performance Data',
            'callback'               => [ $this, 'export_user_data' ],
        ];
    }

    /**
     * @return array{eraser_friendly_name: string, callback: callable}
     */
    public function get_eraser(): array
    {
        return [
            'eraser_friendly_name' => 'WP Flame Performance Data',
            'callback'             => [ $this, 'erase_user_data' ],
        ];
    }

    /**
     * Export user trace data for GDPR request.
     *
     * @param string $email_address
     * @param int    $page
     * @return array{data: array, done: bool}
     */
    public function export_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }

        $traces = $this->storage->list_traces( [
            'user_id'  => $user->ID,
            'per_page' => 50,
            'page'     => $page,
        ] );

        $export_items = [];
        foreach ( $traces as $trace ) {
            $export_items[] = [
                'group_id'          => 'wp-flame-traces',
                'group_label'       => 'WP Flame Performance Traces',
                'group_description' => 'Performance trace data collected by WP Flame.',
                'item_id'           => 'trace-' . $trace['trace_id'],
                'data'              => [
                    [ 'name' => 'URL', 'value' => $trace['url'] ],
                    [ 'name' => 'Method', 'value' => $trace['method'] ],
                    [ 'name' => 'Duration (ms)', 'value' => (string) $trace['total_ms'] ],
                    [ 'name' => 'Date', 'value' => $trace['created_at'] ],
                    [ 'name' => 'IP Address', 'value' => $trace['ip_address'] ],
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => count( $traces ) < 50,
        ];
    }

    /**
     * Erase user trace data for GDPR request.
     *
     * @param string $email_address
     * @param int    $page
     * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
     */
    public function erase_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user ) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $deleted = $this->storage->delete_traces_by_user( $user->ID );

        return [
            'items_removed'  => $deleted,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
```

- [ ] **Step 4: Add delete_traces_by_user() to Storage**

In `src/Storage.php`, add a new method after `delete_trace()` (after line 247):

```php
    /**
     * Delete all traces for a specific user.
     *
     * @param int $user_id
     * @return int Number of rows deleted.
     */
    public function delete_traces_by_user( int $user_id ): int
    {
        $result = $this->wpdb->delete( $this->table, [ 'user_id' => $user_id ], [ '%d' ] );
        return $result !== false ? $result : 0;
    }
```

- [ ] **Step 5: Wire Privacy in wp-flame.php**

In `wp-flame.php`, inside `wp_flame_init()`, in the admin block (around line 201), add:

```php
	$privacy = new WPFlame\Privacy( new WPFlame\Storage( $wpdb ) );
	$privacy->register();
```

- [ ] **Step 6: Run all tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 7: Commit**

```bash
git add src/Privacy.php src/Storage.php tests/Unit/PrivacyTest.php wp-flame.php
git commit -m "feat: add GDPR data export and erasure hooks"
```

---

## Task 9: Score Extension Hook (Spec 1.13)

**Files:**
- Modify: `src/Score.php:31-118` (calculate method)
- Modify: `tests/Unit/ScoreTest.php`

- [ ] **Step 1: Ensure apply_filters is stubbed in tests/bootstrap.php**

Add to `tests/bootstrap.php` if not already present:

```php
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}
```

- [ ] **Step 2: Add apply_filters hook to Score::calculate()**

In `src/Score.php`, in `calculate()`, the existing weighted sum at lines 64-70 is:

```php
        $overall = (int) round(
            $rt_score * 0.35 +
            $http_score * 0.20 +
            $qc_score * 0.15 +
            $db_ratio_score * 0.15 +
            $cb_score * 0.15
        );
```

Replace with a filter that passes the computed factor data, then uses it for the sum. The filter is a no-op by default (the stub returns the value unchanged), so existing tests continue to produce identical scores:

```php
        // Allow site profiles to adjust factor weights/scores.
        $computed_factors = apply_filters( 'wp_flame_score_factors', [
            'response_time'  => [ 'weight' => 0.35, 'score' => $rt_score ],
            'http_time'      => [ 'weight' => 0.20, 'score' => $http_score ],
            'query_count'    => [ 'weight' => 0.15, 'score' => $qc_score ],
            'db_ratio'       => [ 'weight' => 0.15, 'score' => $db_ratio_score ],
            'slow_callbacks' => [ 'weight' => 0.15, 'score' => $cb_score ],
        ], $trace );

        $overall = 0.0;
        foreach ( $computed_factors as $f ) {
            $overall += $f['score'] * $f['weight'];
        }
        $overall = max( 0, min( 100, (int) round( $overall ) ) );
```

**Important:** The keys match the existing factor keys in the return array (`response_time`, `http_time`, `query_count`, `db_ratio`, `slow_callbacks`). The default weights sum to exactly 1.0, producing identical results to the original hardcoded sum. There is no `/ $total_weight` normalization — if a filter changes weights so they don't sum to 1.0, that's their responsibility.

- [ ] **Step 3: Run all unit tests to verify no score changes**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: ALL existing ScoreTest tests pass with identical scores. The filter is a no-op.

- [ ] **Step 4: Commit**

```bash
git add src/Score.php tests/bootstrap.php
git commit -m "feat: add wp_flame_score_factors filter for site profile scoring overrides"
```

---

## Task 10: Extract Shared SourceResolver (Spec 1.10)

**Files:**
- Create: `src/SourceResolver.php`
- Create: `tests/Unit/SourceResolverTest.php`
- Modify: `src/DB.php:92-122` (replace get_caller_source)
- Modify: `src/Http.php:100-130` (replace get_caller_source)
- Modify: `src/GraphQL.php:182-210` (replace get_caller_source)

- [ ] **Step 1: Write failing test for SourceResolver**

Create `tests/Unit/SourceResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\SourceResolver;
use WPFlame\Collector;

class SourceResolverTest extends TestCase
{
    public function test_from_backtrace_returns_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_backtrace($collector);
        $this->assertIsString($result);

        $collector->reset();
    }

    public function test_from_trace_array_returns_string(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $result = SourceResolver::from_trace_array($collector, $trace);
        $this->assertIsString($result);

        $collector->reset();
    }

    public function test_from_trace_array_with_empty_trace_returns_wordpress(): void
    {
        $collector = Collector::instance();
        $collector->start_request(microtime(true));

        $result = SourceResolver::from_trace_array($collector, []);
        $this->assertSame('wordpress', $result);

        $collector->reset();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter SourceResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create SourceResolver class**

Create `src/SourceResolver.php`. Copy the logic from `DB.php` lines 92-122 as the base, then adapt:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class SourceResolver
{
    /**
     * Capture a backtrace and resolve the calling source.
     *
     * @param Collector $collector
     * @param int       $skip  Additional frames to skip beyond the resolver itself.
     * @param int       $depth Max backtrace depth.
     * @return string Source attribution string.
     */
    public static function from_backtrace( Collector $collector, int $skip = 0, int $depth = 25 ): string
    {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $depth );

        return self::from_trace_array( $collector, $trace, $skip + 1 );
    }

    /**
     * Resolve calling source from a pre-captured backtrace.
     *
     * @param Collector $collector
     * @param array     $trace Pre-captured backtrace array.
     * @param int       $skip  Frames to skip.
     * @return string Source attribution string.
     */
    public static function from_trace_array( Collector $collector, array $trace, int $skip = 0 ): string
    {
        for ( $i = $skip; $i < count( $trace ); $i++ ) {
            $frame = $trace[ $i ];

            if ( ! isset( $frame['file'] ) ) {
                continue;
            }

            $file = $frame['file'];

            // Skip WordPress core directories.
            if ( strpos( $file, ABSPATH . 'wp-includes/' ) === 0 ) {
                continue;
            }
            if ( strpos( $file, ABSPATH . 'wp-admin/' ) === 0 ) {
                continue;
            }

            // Skip WP Flame's own files.
            if ( defined( 'WP_FLAME_DIR' ) && strpos( $file, WP_FLAME_DIR ) === 0 ) {
                continue;
            }

            $source = $collector->get_source_from_file( $file );

            return $source['source'];
        }

        return 'wordpress';
    }
}
```

- [ ] **Step 4: Replace get_caller_source() in DB.php**

In `src/DB.php`, replace the `get_caller_source()` method (lines 92-122) with:

```php
    private function get_caller_source(): string
    {
        return SourceResolver::from_backtrace( $this->collector, 1 );
    }
```

- [ ] **Step 5: Replace get_caller_source() in Http.php**

In `src/Http.php`, replace the `get_caller_source()` method (lines 100-130) with:

```php
    private function get_caller_source(): string
    {
        return SourceResolver::from_backtrace( $this->collector, 1 );
    }
```

- [ ] **Step 6: Replace get_caller_source() in GraphQL.php**

In `src/GraphQL.php`, replace the `get_caller_source(array $backtrace)` method (lines 182-210) with:

```php
    private function get_caller_source( array $backtrace ): string
    {
        return SourceResolver::from_trace_array( $this->collector, $backtrace );
    }
```

- [ ] **Step 7: Run all tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass. DB, Http, and GraphQL tests should still pass since the behavior is identical.

- [ ] **Step 8: Commit**

```bash
git add src/SourceResolver.php tests/Unit/SourceResolverTest.php src/DB.php src/Http.php src/GraphQL.php
git commit -m "refactor: extract shared SourceResolver from DB, Http, and GraphQL"
```

---

## Task 11: Fix HTTP Error Handling (Spec 1.9)

**Files:**
- Modify: `src/Http.php:18-24` (constructor — add http_api_debug hook)
- Modify: `src/Http.php` (add on_http_debug method)
- Modify: `tests/Unit/HttpTest.php`

- [ ] **Step 1: Add http_api_debug hook registration**

In `src/Http.php`, in the constructor (lines 18-24), add after the existing hook registrations:

```php
        add_action( 'http_api_debug', [ $this, 'on_http_debug' ], 9999, 5 );
```

- [ ] **Step 2: Add on_http_debug method**

Add after the existing `on_response()` method (after line 92):

```php
    /**
     * Fallback cleanup for HTTP requests where http_response did not fire
     * (WP_Error responses — timeouts, DNS failures, etc.).
     *
     * @param mixed  $response    Response or WP_Error.
     * @param string $context     'response' for WP_Http requests.
     * @param string $class       HTTP transport class name.
     * @param array  $parsed_args Request arguments.
     * @param string $url         Request URL.
     * @return void
     */
    public function on_http_debug( $response, $context, $class, $parsed_args, $url ): void
    {
        $key = md5( $url . ( $parsed_args['method'] ?? 'GET' ) );

        if ( ! isset( $this->pending_spans[ $key ] ) ) {
            return; // Already handled by on_response().
        }

        $span_id = $this->pending_spans[ $key ];
        unset( $this->pending_spans[ $key ] );

        $meta = [
            'url'    => $url,
            'method' => $parsed_args['method'] ?? 'GET',
        ];

        if ( is_wp_error( $response ) ) {
            $meta['status']     = 0;
            $meta['http_error'] = $response->get_error_message();
        } else {
            $meta['status'] = (int) wp_remote_retrieve_response_code( $response );
        }

        $this->collector->add_span_meta( $span_id, $meta );
        $this->collector->end_span( $span_id );
    }
```

- [ ] **Step 3: Add required stubs for tests**

The Http constructor calls `add_action()` (for `http_api_debug`). The existing `tests/Unit/HttpTest.php` stubs `WPFlame\add_filter` in a namespace block but does NOT stub `WPFlame\add_action`. Add to the `WPFlame` namespace block in `tests/Unit/HttpTest.php` (around line 14-19):

```php
    if (!function_exists('WPFlame\add_action')) {
        function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
        {
            return true;
        }
    }
```

Also add `is_wp_error` and `WP_Error` stubs to `tests/bootstrap.php` if not present:

```php
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof \WP_Error;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
```

- [ ] **Step 4: Run all tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add src/Http.php tests/bootstrap.php
git commit -m "fix: handle WP_Error HTTP responses via http_api_debug fallback"
```

---

## Task 12: Extract Config Class (Spec 1.2)

**Files:**
- Create: `src/Config.php`
- Create: `tests/Unit/ConfigTest.php`
- Modify: `wp-flame.php` (construct Config, pass to consumers, replace get_option calls)
- Modify: `src/DB.php` (use Config for full_query_text)
- Modify: `src/GraphQL.php` (use Config for full_query_text)
- Modify: `src/CLI.php` (use Config for retention_days)

- [ ] **Step 1: Write failing test for Config**

Create `tests/Unit/ConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Config;

class ConfigTest extends TestCase
{
    public function test_get_returns_option_value(): void
    {
        $config = new Config();
        // get_option is stubbed in bootstrap — returns default.
        $result = $config->get('wp_flame_enabled', true);
        $this->assertTrue($result);
    }

    public function test_override_takes_precedence(): void
    {
        $config = new Config();
        $config->set_override('wp_flame_sample_rate', 5);
        $this->assertSame(5, $config->get('wp_flame_sample_rate', 1));
    }

    public function test_override_with_false_value(): void
    {
        $config = new Config();
        $config->set_override('wp_flame_enabled', false);
        $this->assertFalse($config->get('wp_flame_enabled', true));
    }

    public function test_instance_returns_same_object(): void
    {
        Config::reset();
        $a = Config::instance();
        $b = Config::instance();
        $this->assertSame($a, $b);
        Config::reset();
    }

    public function test_reset_clears_instance(): void
    {
        $a = Config::instance();
        Config::reset();
        $b = Config::instance();
        $this->assertNotSame($a, $b);
        Config::reset();
    }
}
```

- [ ] **Step 2: Add get_option stub to bootstrap if needed**

In `tests/bootstrap.php`, add if not present:

```php
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit --testsuite unit --filter ConfigTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Create Config class**

Create `src/Config.php`:

```php
<?php

declare(strict_types=1);

namespace WPFlame;

class Config
{
    /** @var array<string, mixed> */
    private $overrides = [];

    /**
     * Get a configuration value.
     *
     * Overrides take precedence over stored options.
     *
     * @param string $key     Option name (e.g. 'wp_flame_sample_rate').
     * @param mixed  $default Default value if neither override nor option exists.
     * @return mixed
     */
    public function get( string $key, $default = false )
    {
        if ( array_key_exists( $key, $this->overrides ) ) {
            return $this->overrides[ $key ];
        }

        return get_option( $key, $default );
    }

    /**
     * Set a per-request override.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set_override( string $key, $value ): void
    {
        $this->overrides[ $key ] = $value;
    }
}
```

- [ ] **Step 5: Run Config tests**

Run: `./vendor/bin/phpunit --testsuite unit --filter ConfigTest`
Expected: All pass.

- [ ] **Step 6: Wire Config into wp-flame.php**

**Scoping challenge:** `wp_flame_init()` creates closures for the shutdown handler and other hooks. `wp_flame_shutdown()` is a standalone function (line 321) — it does NOT have access to variables from `wp_flame_init()`. The CLI registration (lines 474-480) is also outside `wp_flame_init()`.

**Solution:** Store the Config instance as a static property on Config itself, accessible via `Config::instance()`:

Add to `src/Config.php`:

```php
    /** @var self|null */
    private static $instance;

    /**
     * Get the global Config instance.
     * @return self
     */
    public static function instance(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the instance (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
```

In `wp_flame_init()`, create it early:

```php
	$config = WPFlame\Config::instance();
```

In `wp_flame_shutdown()` (standalone function at line 321), access it via:

```php
	$config = WPFlame\Config::instance();
```

Key replacements in `wp-flame.php` (`wp_flame_init()` AND `wp_flame_shutdown()`):
- `get_option( 'wp_flame_enabled', true )` → `$config->get( 'wp_flame_enabled', true )`
- `get_option( 'wp_flame_trace_audience', 'admins' )` → `$config->get( 'wp_flame_trace_audience', 'admins' )`
- `get_option( 'wp_flame_sample_rate', 1 )` → `$config->get( 'wp_flame_sample_rate', 1 )`
- `get_option( 'wp_flame_min_callback_ms', 0.5 )` → `$config->get( 'wp_flame_min_callback_ms', 0.5 )`
- `get_option( 'wp_flame_track_ips', true )` → `$config->get( 'wp_flame_track_ips', true )`
- `get_option( 'wp_flame_budget_max_ms', 500 )` → `$config->get( 'wp_flame_budget_max_ms', 500 )`
- `get_option( 'wp_flame_budget_max_queries', 100 )` → `$config->get( 'wp_flame_budget_max_queries', 100 )`

**Important:** Keep the explicit default on every call. Do NOT rely on Config's `false` default.

- [ ] **Step 7: Update DB.php to accept Config**

In `src/DB.php`, the `full_query_text` property is set in `from_wpdb()` (line 31) via `get_option('wp_flame_full_query_text', false)`. Change this to accept a boolean parameter or read from Config. Simplest approach: pass the value in from the caller.

In `from_wpdb()`, add a parameter:

```php
public static function from_wpdb( wpdb $original, Collector $collector, bool $full_query_text = false ): self
```

Remove the `get_option` call from inside `from_wpdb()` and use the parameter instead.

In `wp-flame.php`, update BOTH `DB::from_wpdb()` call sites to pass the config value:

1. Line 173 (normal path):
```php
$GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector, (bool) $config->get( 'wp_flame_full_query_text', false ) );
```

2. Line 313 (GraphQL false-positive fallback):
```php
$GLOBALS['wpdb'] = WPFlame\DB::from_wpdb( $wpdb, $collector, (bool) $config->get( 'wp_flame_full_query_text', false ) );
```

- [ ] **Step 8: Update GraphQL.php to accept full_query_text parameter**

In `src/GraphQL.php`, the constructor (line 26-31) reads `get_option('wp_flame_full_query_text', false)`. Change constructor to accept the value:

```php
public function __construct( Collector $collector, bool $full_query_text = false )
{
    $this->collector       = $collector;
    $this->full_query_text = $full_query_text;
    $this->register_db_hooks();
}
```

Update the call site in `wp-flame.php`:

```php
$graphql = new WPFlame\GraphQL( $collector, (bool) $config->get( 'wp_flame_full_query_text', false ) );
```

- [ ] **Step 9: Update CLI.php to use Config::instance()**

In `src/CLI.php`, the `prune` command (line 143) calls `get_option('wp_flame_retention_days', 7)`. Since CLI registration happens outside `wp_flame_init()` (lines 474-480), use `Config::instance()` directly in the method rather than constructor injection:

Replace line 143 in the `prune` method:

```php
$days = $assoc_args['days'] ?? Config::instance()->get( 'wp_flame_retention_days', 7 );
```

Add `use WPFlame\Config;` at the top of CLI.php if not present. No constructor changes needed — the CLI registration at line 477 stays as-is.

- [ ] **Step 10: Run all tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass. Some tests for DB, GraphQL, CLI may need constructor signature updates.

- [ ] **Step 11: Commit**

```bash
git add src/Config.php tests/Unit/ConfigTest.php wp-flame.php src/DB.php src/GraphQL.php src/CLI.php tests/bootstrap.php
git commit -m "feat: add Config class for centralized settings with per-request overrides"
```

---

## Task 13: Add Core Hooks/Filters (Spec 1.1)

**Files:**
- Modify: `wp-flame.php` (shutdown handler — 3 hooks, init — config filter)
- Modify: `src/Admin.php` (insight rendering — 1 hook)

- [ ] **Step 1: Add wp_flame_trace_meta filter**

In `wp-flame.php`, in `wp_flame_shutdown()`, AFTER the request metadata block is built (after the `$ip_address` assignment from Task 7) and BEFORE `$collector->get_trace()`:

```php
		$request_meta = apply_filters( 'wp_flame_trace_meta', $request_meta );
```

- [ ] **Step 2: Add wp_flame_should_store_trace filter**

In `wp_flame_shutdown()`, AFTER `Score::calculate()` and BEFORE `$collector->stop()`:

```php
		$should_store = apply_filters( 'wp_flame_should_store_trace', true, $trace );
		if ( ! $should_store ) {
			$collector->stop();
			return;
		}
```

- [ ] **Step 3: Add wp_flame_trace_stored action**

In `wp_flame_shutdown()`, AFTER `$storage->save_trace()`:

```php
		do_action( 'wp_flame_trace_stored', $trace, $score_result );
```

- [ ] **Step 4: Add wp_flame_insights filter in Admin**

In `src/Admin.php`, find where `Insights::analyze($trace)` is called in the flame graph view. After the call, add:

```php
$insights = apply_filters( 'wp_flame_insights', $insights, $trace );
```

Also find where `Insights::analyze_dashboard()` is called and add the same filter (with `null` for trace since it's dashboard-level):

```php
$dashboard_insights = apply_filters( 'wp_flame_insights', $dashboard_insights, null );
```

- [ ] **Step 5: Add do_action/apply_filters stubs to bootstrap if needed**

In `tests/bootstrap.php`, ensure both are stubbed:

```php
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$args ) {
        // No-op in unit tests.
    }
}
```

(`apply_filters` should already be stubbed from Task 9.)

- [ ] **Step 6: Run all tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 7: Commit**

```bash
git add wp-flame.php src/Admin.php tests/bootstrap.php
git commit -m "feat: add core extension hooks (trace_meta, should_store_trace, trace_stored, insights)"
```

---

## Task 14: Full Query Text Privacy Warning (Spec 3.14)

**Files:**
- Modify: `src/Settings.php:240-243`

- [ ] **Step 1: Update the setting description**

In `src/Settings.php`, find the `wp_flame_full_query_text` field description (around line 240-243). The current text is:

```
When enabled, the complete SQL query is stored with each trace. This may increase storage usage.
```

Replace with:

```
When enabled, the complete SQL query is stored with each trace. This may increase storage usage. <strong>Privacy notice:</strong> full query text may contain personal data (email addresses, usernames, etc.) embedded in query values. Enable only in development or with appropriate data handling policies.
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/phpunit --testsuite unit`
Expected: All pass.

- [ ] **Step 3: Commit**

```bash
git add src/Settings.php
git commit -m "docs: add privacy warning to full query text setting description"
```

---

## Verification

After all 14 tasks are complete:

- [ ] **Run full test suite**

```bash
./vendor/bin/phpunit --testsuite unit
```

- [ ] **Verify no leftover get_option calls for behavior-driving settings**

```bash
grep -rn "get_option.*wp_flame_" wp-flame.php src/DB.php src/GraphQL.php src/CLI.php | grep -v "schema_version" | grep -v "mu_plugin"
```

Expected: Only `src/Settings.php` (form rendering) and `mu-plugin/` (early bailout, pre-Config) should remain.

- [ ] **Check all new files have declare(strict_types=1)**

```bash
head -3 src/Config.php src/SourceResolver.php src/Privacy.php
```

- [ ] **Verify hook registration**

```bash
grep -rn "apply_filters\|do_action" wp-flame.php src/
```

Expected: 4 hooks in wp-flame.php, 2 in Admin.php, 1 in Score.php, 2 in Privacy.php.

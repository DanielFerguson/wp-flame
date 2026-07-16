<?php

namespace WPFlame\Tests\Integration;

use WP_UnitTestCase;
use WPFlame\Span;
use WPFlame\CaptureSession;
use WPFlame\EnvironmentSnapshot;
use WPFlame\Score;
use WPFlame\Storage;
use WPFlame\StorageResult;
use WPFlame\Trace;

class StorageTest extends WP_UnitTestCase
{
    private Storage $storage;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        delete_option('wp_flame_migration_lock');
        delete_option('wp_flame_rollup_lock');
        wp_clear_scheduled_hook('wp_flame_run_migration');
        wp_clear_scheduled_hook('wp_flame_rollup_backfill');
        $this->storage = new Storage($wpdb);
        $this->storage->create_table();
        update_option('wp_flame_schema_version', Storage::SCHEMA_VERSION);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_traces");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_sessions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_environments");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}flame_rollups");
        delete_option('wp_flame_storage_quota_rows');
        delete_option('wp_flame_storage_quota_mb');
        delete_option('wp_flame_storage_quota_state');
        delete_option('wp_flame_capture_paused_reason');
        delete_option('wp_flame_last_cleanup_result');
        delete_option('wp_flame_last_cleanup_at');
        delete_option('wp_flame_migration_lock');
        delete_option('wp_flame_migration_health');
        delete_option('wp_flame_schema_version');
        delete_option('wp_flame_rollup_last_result');
        delete_option('wp_flame_rollup_last_at');
        delete_option('wp_flame_rollup_last_failure');
        delete_option('wp_flame_rollup_lock');
        wp_clear_scheduled_hook('wp_flame_prune_traces_continue');
        wp_clear_scheduled_hook('wp_flame_run_migration');
        wp_clear_scheduled_hook('wp_flame_rollup_backfill');
        parent::tear_down();
        // Rollup transactions commit independently of the WordPress test
        // transaction, so clear their option state again after core rollback.
        delete_option('wp_flame_rollup_last_result');
        delete_option('wp_flame_rollup_last_at');
        delete_option('wp_flame_rollup_last_failure');
        delete_option('wp_flame_rollup_lock');
    }

    private function make_trace(string $id = 'trace-1', string $url = '/test', float $total_ms = 100.0): Trace
    {
        $spans = [
            new Span('s1', null, 'Bootstrap', Span::TYPE_CORE, 'wordpress', 0.0, 50.0),
            new Span('s2', 's1', 'SELECT', Span::TYPE_DB, 'test-plugin', 50.0, 10.0, ['query' => 'SELECT 1']),
        ];

        return new Trace($id, $url, 'GET', '2026-03-16T12:00:00+00:00', $total_ms, 16777216, '8.1.0', '6.4.2', $spans);
    }

    public function test_create_table_creates_flame_traces_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $this->assertContains('trace_id', $columns);
        $this->assertContains('rollup_version', $columns);
        $this->assertContains('trace_bytes', $columns);
        $rollup_columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}flame_rollups");
        $this->assertContains('dimension_hash', $rollup_columns);
        $this->assertContains('self_duration_ms', $rollup_columns);
        $this->assertContains('duration_b11', $rollup_columns);
    }

    public function test_schema_v3_upgrade_adds_indexed_capture_dimensions_and_preserves_legacy_trace(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trace_id char(36) NOT NULL,
            url varchar(2048) NOT NULL DEFAULT '',
            url_path varchar(2048) NOT NULL DEFAULT '',
            method varchar(10) NOT NULL DEFAULT '',
            total_ms float NOT NULL DEFAULT 0,
            query_count int unsigned NOT NULL DEFAULT 0,
            peak_memory bigint unsigned NOT NULL DEFAULT 0,
            db_time_ms float NOT NULL DEFAULT 0,
            http_time_ms float NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            user_id int NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL DEFAULT '',
            score tinyint unsigned DEFAULT NULL,
            trace_data longtext NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY trace_id (trace_id),
            KEY url_path (url_path(191)),
            KEY created_at (created_at)
        ) {$charset}");

        $legacy = $this->make_trace('legacy-trace')->toArray();
        $legacy['v'] = 1;
        foreach ([
            'capture_session_id', 'capture_phase', 'capture_origin', 'capture_policy',
            'request_type', 'route_key', 'http_status', 'instrumentation_mode',
            'sample_rate', 'effective_sample_probability', 'capture_start_stage',
            'observed_duration_ms', 'request_start_reference_ms', 'unobserved_prebootstrap_ms',
            'wp_flame_version', 'score_version', 'environment_snapshot_id', 'capabilities',
            'incomplete_reasons', 'dropped_span_count', 'auto_closed_span_count', 'trace_truncated',
        ] as $key) {
            unset($legacy[$key]);
        }

        $wpdb->insert($table, [
            'trace_id'   => 'legacy-trace',
            'url'        => '/test',
            'url_path'   => '/test',
            'method'     => 'GET',
            'total_ms'   => 100,
            'trace_data' => wp_json_encode($legacy),
        ]);
        update_option('wp_flame_schema_version', 3);

        $storage = new Storage($wpdb);
        $storage->maybe_upgrade();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        foreach ([
            'request_type', 'route_key', 'http_status', 'instrumentation_mode',
            'capture_origin', 'capture_session_id', 'environment_snapshot_id',
            'score_version', 'is_complete',
            'rollup_version',
            'trace_bytes',
        ] as $column) {
            $this->assertContains($column, $columns);
        }
        $this->assertSame(Storage::SCHEMA_VERSION, (int) get_option('wp_flame_schema_version'));

        $indexes = $wpdb->get_col("SHOW INDEX FROM {$table}", 2);
        foreach (['route_cohort', 'status_created', 'completeness_created', 'capture_session', 'environment_snapshot'] as $index) {
            $this->assertContains($index, $indexes);
        }

        $row = $wpdb->get_row("SELECT request_type, instrumentation_mode, capture_origin, score_version, is_complete FROM {$table} WHERE trace_id = 'legacy-trace'", ARRAY_A);
        $this->assertSame('unknown', $row['request_type']);
        $this->assertSame('unknown', $row['instrumentation_mode']);
        $this->assertSame('legacy', $row['capture_origin']);
        $this->assertSame('1', $row['score_version']);
        $this->assertSame('0', $row['is_complete']);

        $retrieved = $storage->get_trace('legacy-trace');
        $this->assertNotNull($retrieved);
        $this->assertSame('legacy', $retrieved->capture_report->capture_origin);
        $this->assertCount(2, $retrieved->spans);
    }

    public function test_rollup_backfill_is_bounded_resumable_and_aggregates_consistently(): void
    {
        global $wpdb;
        for ($index = 0; $index < Storage::ROLLUP_BACKFILL_BATCH_LIMIT + 5; $index++) {
            $result = $this->storage->save_trace($this->make_trace('rollup-' . $index), 80);
            $this->assertTrue($result->success);
        }

        $first = $this->storage->run_rollup_backfill(5000);
        $this->assertSame(Storage::ROLLUP_BACKFILL_BATCH_LIMIT, $first['processed']);
        $this->assertSame(0, $first['failed']);
        $this->assertTrue($first['backlog']);
        $this->assertSame(5, $this->storage->rollup_health()['pending']);

        $second = $this->storage->run_rollup_backfill(5000);
        $this->assertSame(5, $second['processed']);
        $this->assertFalse($second['backlog']);
        $this->assertSame(0, $this->storage->rollup_health()['pending']);

        $rollups = $wpdb->prefix . 'flame_rollups';
        $cohort = $wpdb->get_row(
            "SELECT sample_count, span_count, total_duration_ms, total_query_count, total_score, scored_count
             FROM {$rollups} WHERE source = '' AND span_type = '' AND callback_key = ''",
            ARRAY_A
        );
        $this->assertSame('30', $cohort['sample_count']);
        $this->assertSame('60', $cohort['span_count']);
        $this->assertEquals(3000.0, (float) $cohort['total_duration_ms']);
        $this->assertSame('30', $cohort['total_query_count']);
        $this->assertSame('2400', $cohort['total_score']);
        $this->assertSame('30', $cohort['scored_count']);
        $this->assertSame(
            30,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'flame_traces WHERE rollup_version = ' . \WPFlame\Rollup::VERSION)
        );

        $population = $this->storage->get_rollup_population(7);
        $this->assertSame(30, $population['sample_count']);
        $this->assertSame(1, $population['capability_cohorts']);
        $this->assertSame(1, $population['score_versions']);
        $this->assertSame(0, $population['pending']);

        $types = $this->storage->get_rollup_type_totals(7);
        $this->assertCount(2, $types);
        $this->assertSame('core', $types[0]['span_type']);
        $this->assertSame('1200', $types[0]['total_ms']);
        $this->assertSame('db', $types[1]['span_type']);
        $this->assertSame('300', $types[1]['total_ms']);
    }

    public function test_malformed_rollup_input_is_reported_without_blocking_later_traces(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        $wpdb->insert($table, [
            'trace_id'   => 'malformed-rollup',
            'created_at' => current_time('mysql', true),
            'trace_data' => '{invalid',
        ]);
        $this->storage->save_trace($this->make_trace('valid-rollup'));

        $result = $this->storage->run_rollup_backfill(5000);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse($result['backlog']);
        $failure = $this->storage->rollup_health()['last_failure'];
        $this->assertSame('Malformed trace JSON.', $failure['message']);
    }

    public function test_purge_all_removes_raw_traces_and_derived_rollups(): void
    {
        global $wpdb;
        $this->storage->save_trace($this->make_trace('purge-rollup'));
        $this->storage->run_rollup_backfill(5000);
        $this->assertGreaterThan(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}flame_rollups"));

        $this->assertSame(1, $this->storage->purge_all());

        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}flame_traces"));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}flame_rollups"));
    }

    public function test_rollup_worker_lock_prevents_double_processing_and_stale_lock_recovers(): void
    {
        $this->storage->save_trace($this->make_trace('locked-rollup'));
        add_option('wp_flame_rollup_lock', [
            'token'       => 'other-worker',
            'acquired_at' => time(),
        ], '', false);

        $locked = $this->storage->run_rollup_backfill(5000);
        $this->assertSame(0, $locked['processed']);
        $this->assertTrue($locked['backlog']);

        update_option('wp_flame_rollup_lock', [
            'token'       => 'stale-worker',
            'acquired_at' => time() - Storage::ROLLUP_LOCK_TTL_SECONDS - 1,
        ]);
        $recovered = $this->storage->run_rollup_backfill(5000);
        $this->assertSame(1, $recovered['processed']);
        $this->assertFalse($recovered['backlog']);
        $this->assertFalse(get_option('wp_flame_rollup_lock', false));
    }

    public function test_route_cohort_trends_keep_capabilities_separate_and_report_histogram_percentiles(): void
    {
        $durations = [10.0, 60.0, 150.0, 600.0, 2000.0];
        foreach ($durations as $index => $duration) {
            $trace = new Trace(
                'trend-' . $index, '/checkout', 'POST', '2026-07-16T00:00:00+00:00',
                $duration, 1024, '8.3', '6.8', [], [], [
                    'request_type'        => 'frontend',
                    'route_key'           => 'POST /checkout',
                    'instrumentation_mode' => 'standard',
                    'score_version'       => 2,
                    'capabilities'        => [
                        'database' => [ 'status' => 'captured', 'reason' => '' ],
                    ],
                ]
            );
            $this->storage->save_trace($trace, 70);
        }
        $limited = new Trace(
            'trend-limited', '/checkout', 'POST', '2026-07-16T00:00:00+00:00',
            300.0, 1024, '8.3', '6.8', [], [], [
                'request_type'        => 'frontend',
                'route_key'           => 'POST /checkout',
                'instrumentation_mode' => 'standard',
                'score_version'       => 2,
                'capabilities'        => [
                    'database' => [ 'status' => 'unavailable', 'reason' => 'custom_database' ],
                ],
                'incomplete_reasons'  => [ 'database_unavailable' ],
            ]
        );
        $this->storage->save_trace($limited, 60);
        $this->storage->run_rollup_backfill(5000);

        $trends = $this->storage->get_route_cohort_trends(7, 10);

        $this->assertCount(2, $trends);
        $this->assertSame(5, $trends[0]['sample_count']);
        $this->assertSame(200.0, $trends[0]['p50_ms']);
        $this->assertSame(3000.0, $trends[0]['p95_ms']);
        $this->assertSame(1, $trends[1]['sample_count']);
        $this->assertNotSame($trends[0]['capability_cohort'], $trends[1]['capability_cohort']);
    }

    public function test_legacy_route_backfill_is_bounded_and_resumes_after_partial_migration(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        for ($i = 0; $i < 501; $i++) {
            $wpdb->insert($table, [
                'trace_id'   => "legacy-batch-{$i}",
                'url'        => "/legacy/{$i}?token=private",
                'url_path'   => '',
                'method'     => 'GET',
                'trace_data' => '{}',
            ]);
        }
        update_option('wp_flame_schema_version', 1);

        $first = $this->storage->maybe_upgrade();
        $this->assertSame('pending', $first['status'], wp_json_encode(get_option('wp_flame_migration_lock', [])) ?: '');
        $this->assertSame(Storage::MIGRATION_BACKFILL_BATCH_LIMIT, $first['processed']);
        $this->assertSame(1, (int) get_option('wp_flame_schema_version'));
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE url_path = ''"));
        $this->assertFalse(get_option('wp_flame_migration_lock', false));

        $second = $this->storage->maybe_upgrade();
        $this->assertSame('pending', $second['status']);
        $this->assertSame(Storage::MIGRATION_BACKFILL_BATCH_LIMIT, $second['processed']);

        $third = $this->storage->maybe_upgrade();
        $this->assertSame('complete', $third['status']);
        $this->assertSame(Storage::SCHEMA_VERSION, (int) get_option('wp_flame_schema_version'));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE url_path = ''"));
        $this->assertSame('/legacy/500', $wpdb->get_var("SELECT url_path FROM {$table} WHERE trace_id = 'legacy-batch-500'"));
    }

    public function test_trace_size_backfill_is_bounded_and_replaces_longtext_quota_scan(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'flame_traces';
        for ($i = 0; $i < 501; $i++) {
            $wpdb->insert($table, [
                'trace_id'   => "size-batch-{$i}",
                'url'        => '/size-fixture',
                'url_path'   => '/size-fixture',
                'method'     => 'GET',
                'trace_bytes' => 0,
                'trace_data' => '{"fixture":"' . str_repeat('x', 32) . '"}',
            ]);
        }
        update_option('wp_flame_schema_version', 5);

        $first = $this->storage->maybe_upgrade();
        $this->assertSame('pending', $first['status']);
        $this->assertSame(Storage::MIGRATION_BACKFILL_BATCH_LIMIT, $first['processed']);
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE trace_bytes = 0"));
        $this->assertSame(5, (int) get_option('wp_flame_schema_version'));

        $second = $this->storage->maybe_upgrade();
        $this->assertSame('complete', $second['status']);
        $this->assertSame(Storage::SCHEMA_VERSION, (int) get_option('wp_flame_schema_version'));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE trace_bytes = 0"));
        $this->assertSame(
            (int) $wpdb->get_var("SELECT SUM(LENGTH(trace_data)) FROM {$table}"),
            $this->storage->get_stats()['bytes']
        );
    }

    public function test_active_migration_lock_defers_work_and_stale_lock_can_recover(): void
    {
        update_option('wp_flame_schema_version', 3);
        add_option('wp_flame_migration_lock', [
            'token'       => 'active-worker',
            'acquired_at' => time(),
            'target'      => Storage::SCHEMA_VERSION,
        ], '', false);

        $locked = $this->storage->maybe_upgrade();
        $this->assertSame('locked', $locked['status']);
        $this->assertSame(3, (int) get_option('wp_flame_schema_version'));

        update_option('wp_flame_migration_lock', [
            'token'       => 'stale-worker',
            'acquired_at' => time() - Storage::MIGRATION_LOCK_TTL_SECONDS - 1,
            'target'      => Storage::SCHEMA_VERSION,
        ]);
        $recovered = $this->storage->maybe_upgrade();
        $this->assertSame('complete', $recovered['status']);
        $this->assertSame(Storage::SCHEMA_VERSION, (int) get_option('wp_flame_schema_version'));
    }

    public function test_save_and_get_trace_round_trip(): void
    {
        $trace = $this->make_trace();
        $this->storage->save_trace($trace);

        $retrieved = $this->storage->get_trace('trace-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('trace-1', $retrieved->id);
        $this->assertSame('/test', $retrieved->url);
        $this->assertSame('GET', $retrieved->method);
        $this->assertSame(100.0, $retrieved->total_ms);
        $this->assertCount(2, $retrieved->spans);
        $this->assertSame('Bootstrap', $retrieved->spans[0]->name);
        $this->assertSame(1, $retrieved->query_count);
    }

    public function test_score_v2_factor_snapshot_round_trips_with_stored_overall_score(): void
    {
        $trace = new Trace(
            'score-v2', '/checkout', 'POST', '2026-07-16T00:00:00+00:00',
            250.0, 1024, '8.3', '6.8', [], [], [
                'request_type'        => 'frontend',
                'route_key'           => 'POST /checkout',
                'instrumentation_mode' => 'safe',
                'score_version'       => Score::VERSION,
                'capabilities'        => [
                    'early_lifecycle' => [ 'status' => 'captured', 'reason' => '' ],
                    'database'        => [ 'status' => 'not_requested', 'reason' => 'safe_mode' ],
                    'callbacks'       => [ 'status' => 'not_requested', 'reason' => 'safe_mode' ],
                    'http'            => [ 'status' => 'captured', 'reason' => '' ],
                ],
            ]
        );
        $snapshot = Score::calculate($trace);
        $trace->set_score_snapshot($snapshot);
        $stored = $this->storage->save_trace($trace, $snapshot['score']);
        $this->assertTrue($stored->success);

        $retrieved = $this->storage->get_trace('score-v2');

        $this->assertNotNull($retrieved);
        $this->assertSame($snapshot, $retrieved->score_snapshot);
        $this->assertSame($snapshot, Score::calculate($retrieved));
        $this->assertSame($snapshot['score'], $retrieved->meta['_row_score']);
    }

    public function test_capture_session_groups_traces_and_deduplicates_environment_snapshot(): void
    {
        global $wpdb;
        $snapshot = new EnvironmentSnapshot([
            'wp_version'  => '6.8',
            'php_version' => '8.3',
            'plugins'     => [ 'woocommerce' => '10.0' ],
        ]);
        $snapshotId = $this->storage->save_environment_snapshot($snapshot);
        $this->assertSame($snapshot->fingerprint, $snapshotId);
        $this->assertSame($snapshotId, $this->storage->save_environment_snapshot($snapshot));
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}flame_environments"));

        $session = new CaptureSession([
            'id'                   => '11111111-1111-4111-8111-111111111111',
            'route_key'            => 'POST /checkout',
            'request_type'         => 'frontend',
            'capture_policy'       => 'manual_verification',
            'instrumentation_mode' => 'standard',
            'requested_count'      => 2,
            'expires_at'           => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            'phase'                => 'baseline',
        ]);
        $this->assertTrue($this->storage->save_capture_session($session));

        $trace = new Trace(
            'session-trace', '/checkout', 'POST', '2026-07-16T00:00:00+00:00',
            100, 1024, '8.3', '6.8', [], [], [
                'capture_session_id'        => $session->id,
                'capture_phase'             => 'baseline',
                'capture_origin'            => 'manual',
                'request_type'              => 'frontend',
                'route_key'                 => 'POST /checkout',
                'instrumentation_mode'      => 'standard',
                'environment_snapshot_id'   => $snapshotId,
            ]
        );
        $this->storage->save_trace($trace);

        $storedSession = $this->storage->get_capture_session($session->id);
        $this->assertNotNull($storedSession);
        $this->assertSame(1, $storedSession->captured_count);
        $this->assertSame('active', $storedSession->status);
        $this->assertCount(1, $this->storage->list_traces(['capture_session_id' => $session->id]));

        $secondTrace = new Trace(
            'session-trace-2', '/checkout', 'POST', '2026-07-16T00:00:01+00:00',
            90, 1024, '8.3', '6.8', [], [], [
                'capture_session_id'      => $session->id,
                'capture_phase'           => 'baseline',
                'capture_origin'          => 'session',
                'request_type'            => 'frontend',
                'route_key'               => 'POST /checkout',
                'instrumentation_mode'    => 'standard',
                'environment_snapshot_id' => $snapshotId,
            ]
        );
        $this->assertTrue($this->storage->save_trace($secondTrace)->success);
        $completedSession = $this->storage->get_capture_session($session->id);
        $this->assertNotNull($completedSession);
        $this->assertSame(2, $completedSession->captured_count);
        $this->assertSame('complete', $completedSession->status);
        $this->assertCount(2, $this->storage->get_capture_session_traces($session->id));
        $this->assertNotEmpty($this->storage->list_capture_sessions());
        $this->assertTrue($this->storage->cancel_capture_session($session->id));
        $this->assertSame('complete', $this->storage->get_capture_session($session->id)->status);

        $storedSnapshot = $this->storage->get_environment_snapshot($snapshotId);
        $this->assertNotNull($storedSnapshot);
        $this->assertSame($snapshot->fingerprint, $storedSnapshot->fingerprint);
    }

    public function test_capture_session_atomically_pins_first_route_and_rejects_background_routes(): void
    {
        $session = new CaptureSession([
            'id'                   => '22222222-2222-4222-8222-222222222222',
            'instrumentation_mode' => 'standard',
            'requested_count'      => 10,
            'expires_at'           => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
            'phase'                => 'baseline',
        ]);
        $this->assertTrue($this->storage->save_capture_session($session));

        $claimed = $this->storage->claim_capture_session_route($session->id, 'GET /checkout', 'frontend');

        $this->assertNotNull($claimed);
        $this->assertSame('GET /checkout', $claimed->route_key);
        $this->assertSame('frontend', $claimed->request_type);
        $this->assertNull($this->storage->claim_capture_session_route($session->id, 'POST AJAX heartbeat', 'ajax'));
        $reloaded = $this->storage->get_capture_session($session->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('GET /checkout', $reloaded->route_key);
        $this->assertSame('frontend', $reloaded->request_type);
    }

    public function test_get_trace_returns_null_for_nonexistent(): void
    {
        $result = $this->storage->get_trace('does-not-exist');
        $this->assertNull($result);
    }

    public function test_delete_trace_removes_row(): void
    {
        $this->storage->save_trace($this->make_trace());
        $this->storage->delete_trace('trace-1');

        $this->assertNull($this->storage->get_trace('trace-1'));
    }

    public function test_list_traces_returns_lightweight_rows(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/page-1', 100.0));
        $this->storage->save_trace($this->make_trace('t2', '/page-2', 200.0));

        $rows = $this->storage->list_traces([]);

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('trace_id', $rows[0]);
        $this->assertArrayHasKey('url', $rows[0]);
        $this->assertArrayHasKey('total_ms', $rows[0]);
        $this->assertArrayNotHasKey('trace_data', $rows[0]);
    }

    public function test_list_traces_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save_trace($this->make_trace("t{$i}", "/page-{$i}"));
        }

        $page1 = $this->storage->list_traces(['per_page' => 2, 'page' => 1]);
        $page2 = $this->storage->list_traces(['per_page' => 2, 'page' => 2]);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
    }

    public function test_count_traces_returns_total(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->storage->save_trace($this->make_trace("t{$i}"));
        }

        $this->assertSame(3, $this->storage->count_traces([]));
    }

    public function test_list_traces_filter_by_min_duration(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/fast', 50.0));
        $this->storage->save_trace($this->make_trace('t2', '/slow', 500.0));

        $rows = $this->storage->list_traces(['min_duration' => 100.0]);

        $this->assertCount(1, $rows);
        $this->assertSame('/slow', $rows[0]['url']);
    }

    public function test_list_traces_filter_by_url(): void
    {
        $this->storage->save_trace($this->make_trace('t1', '/admin/settings'));
        $this->storage->save_trace($this->make_trace('t2', '/shop/product'));

        $rows = $this->storage->list_traces(['url' => 'admin']);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('admin', $rows[0]['url']);
    }

    public function test_prune_old_deletes_expired_traces(): void
    {
        global $wpdb;
        $this->storage->save_trace($this->make_trace());

        // Manually backdate the trace
        $wpdb->update(
            $wpdb->prefix . 'flame_traces',
            ['created_at' => '2020-01-01 00:00:00'],
            ['trace_id' => 'trace-1']
        );

        $this->storage->prune_old(7);

        $this->assertNull($this->storage->get_trace('trace-1'));
    }

    public function test_retention_cleanup_removes_expired_daily_rollups(): void
    {
        global $wpdb;
        $this->storage->save_trace($this->make_trace('expired-rollup'));
        $this->storage->run_rollup_backfill(5000);
        $wpdb->update($wpdb->prefix . 'flame_traces', ['created_at' => '2020-01-01 00:00:00'], ['trace_id' => 'expired-rollup']);
        $wpdb->query("UPDATE {$wpdb->prefix}flame_rollups SET bucket_start = '2020-01-01'");

        $result = $this->storage->run_retention_cleanup(7, 5000);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}flame_rollups"));
    }

    public function test_row_quota_rejects_unexpired_trace_and_recovers_after_space_is_created(): void
    {
        update_option('wp_flame_storage_quota_rows', 100);
        update_option('wp_flame_storage_quota_mb', 16);

        for ($i = 0; $i < 100; $i++) {
            $result = $this->storage->save_trace($this->make_trace("quota-{$i}"));
            $this->assertTrue($result->success);
        }

        $rejected = $this->storage->save_trace($this->make_trace('quota-rejected'));
        $this->assertFalse($rejected->success);
        $this->assertSame(StorageResult::QUOTA_REACHED, $rejected->status);
        $this->assertSame(100, $this->storage->count_traces([]));
        $this->assertSame('storage_quota', get_option('wp_flame_capture_paused_reason'));

        $this->storage->delete_trace('quota-0');
        $stored = $this->storage->save_trace($this->make_trace('quota-recovered'));
        $this->assertTrue($stored->success);
        $this->assertSame(100, $this->storage->count_traces([]));
        $this->assertFalse(get_option('wp_flame_capture_paused_reason', false));
    }

    public function test_quota_prunes_expired_trace_before_accepting_new_trace(): void
    {
        global $wpdb;
        update_option('wp_flame_storage_quota_rows', 100);
        update_option('wp_flame_storage_quota_mb', 16);
        update_option('wp_flame_retention_days', 7);

        for ($i = 0; $i < 100; $i++) {
            $result = $this->storage->save_trace($this->make_trace("retention-{$i}"));
            $this->assertTrue($result->success);
        }

        $wpdb->update(
            $wpdb->prefix . 'flame_traces',
            ['created_at' => '2020-01-01 00:00:00'],
            ['trace_id' => 'retention-0']
        );

        $stored = $this->storage->save_trace($this->make_trace('retention-new'));
        $this->assertTrue($stored->success);
        $this->assertNull($this->storage->get_trace('retention-0'));
        $this->assertNotNull($this->storage->get_trace('retention-new'));
        $this->assertSame(100, $this->storage->count_traces([]));
    }
}

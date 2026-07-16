# M6 deterministic accuracy evidence

Recorded: 2026-07-16

## Purpose

This register ties each public v1 measurement capability to a deterministic fixture, its expected observation, and its limitation. Passing a fixture means WP Flame reported the known workload within the stated capture boundary; it does not turn request-timeline instrumentation into a complete PHP profiler.

## Fixture register

| Workload | Expected observation | Authoritative evidence | Documented limitation |
| --- | --- | --- | --- |
| Core `wpdb` query | A DB span, normalized query shape, duration, and WordPress/plugin caller evidence | `tests/Integration/DBTest.php`; `tests/Unit/DBTest.php` | WP Flame wraps the compatible core `wpdb` instance. It does not replace an unsupported custom database object. |
| Custom `wpdb` subclass | Original object remains installed; database capability is unavailable/degraded rather than silently claimed | `tests/Integration/RequestLifecycleTest.php::test_custom_database_subclass_remains_untouched` | Query count/time is unknown when the custom layer cannot be safely wrapped. |
| Slow, repeated, and failed queries | Repeated fingerprints retain evidence count; slow and failed-query rules include bounded failure evidence | `tests/Unit/InsightContractTest.php`; `tests/Unit/DBTest.php::test_failed_query_records_only_a_bounded_failure_signal`; 100-query benchmark fixture | Query text is normalized by default, so equality is based on the fingerprint/shape rather than literals. |
| Slow and failed WordPress HTTP calls | HTTP span retains host, method, bounded status/failure signal, duration, and owner where supported | `tests/Unit/HttpTest.php`; `tests/Unit/InsightContractTest.php` | Requests that bypass the WordPress HTTP API are outside the v1 capture contract. Full URLs and error messages require the sensitive-data acknowledgement. |
| Nested and out-of-order callbacks | Parent IDs and self time remain correct; an out-of-order close cannot corrupt another span | `tests/Unit/CollectorTest.php`; `tests/Unit/CallbackInstrumentorTest.php`; `tests/Unit/CallbackWrapperTest.php` | Deep wraps supported WordPress callbacks only. By-reference signatures and malformed/non-callable hook entries are visibly excluded for compatibility. |
| Child theme, parent theme, plugin, mu-plugin, and drop-in source | Best-supported WordPress owner and bounded content-relative caller evidence | `tests/Unit/CollectorTest.php::test_get_source_distinguishes_mu_plugins_drop_ins_and_child_themes`; `tests/Unit/SourceResolverTest.php`; live child-theme fixture in `bin/compat-smoke.sh` | Attribution identifies the owner reached in the observed backtrace. It is not proof that all of that owner's internal execution was measured. |
| Degraded/no-mu-plugin mode | Capture starts at `plugins_loaded`, early lifecycle capability is unavailable, and later phase names remain honest | `tests/Unit/LifecycleTest.php`; `tests/Unit/PluginLifecycleTest.php`; `tests/Unit/MuPluginManagerTest.php` | Work before plugin bootstrap is unobserved and never represented as zero. |
| REST, AJAX, WooCommerce AJAX, cron, CLI, and GraphQL | Request type, normalized route, and dispatch phase match the deterministic input | `tests/Unit/RouteResolverTest.php`; `tests/Unit/LifecycleTest.php`; `tests/Integration/RequestLifecycleTest.php` | Route normalization deliberately removes identifiers and secrets. Cron is a background population and is never mixed into a frontend cohort. |
| Current WPGraphQL operation and root resolver | Operation/resolver spans, LIFO batched-operation handling, root source evidence, and DB nesting | `tests/Unit/GraphQLTest.php`; live operation and resolver assertions in `bin/compat-smoke.sh` | Non-root resolver internals and GraphQL implementations that do not expose the supported hooks are outside the advertised capability. |
| Persistence failure and full quota | Monitored response continues; storage result and health state expose the failure; manual capture receives a retry action | `tests/Unit/StorageTest.php`; `tests/Integration/StorageTest.php`; `tests/e2e/wp-flame.spec.js` | A trace that could not be persisted cannot be recovered from WP Flame after the request. |
| Dropped, auto-closed, and storage-trimmed spans | Counts/reasons propagate into the capture report, completeness, score, finding, and UI | `tests/Unit/CollectorTest.php`; `tests/Unit/TraceTest.php`; `tests/Unit/StorageBoundsTest.php`; maximum-trace benchmark fixture | The renderer supports 5,000 spans. The default collector limit is 2,000; extra spans are counted as dropped and conclusions are marked incomplete. |
| Response equivalence | Disabled and captured responses retain the same status and normalized anonymous body | `bin/compat-smoke.sh`; `bin/benchmark-overhead.mjs` | Authenticated admin HTML contains request-volatile nonces/live data, so those rows require status equivalence and separate browser assertions rather than an artificial byte-for-byte equality. |

## External reference comparison

The release comparison uses three deliberately different references:

1. Query Monitor is installed only in the disposable compatibility environment and reports the WordPress query population for the same deterministic 100-query route.
2. WordPress's independent `$wpdb->num_queries` counter supplies an exact request-local reference for that fixture.
3. The benchmark client's monotonic wall clock measures the complete loopback request, including WP Flame shutdown persistence. WP Flame's stored `observed_duration_ms` stops before persistence by contract, so it is expected to be lower.

The comparison is not required to produce identical totals. Query Monitor and `$wpdb->num_queries` can include setup or late queries that occur outside WP Flame's compatible wrapper boundary; WP Flame can include only the spans it actually captured. Any discrepancy must be explained as a boundary difference and must not be hidden by changing fixture values.

### Dated query reference result

`node bin/compare-query-reference.mjs` recorded the following on 2026-07-16 using Query Monitor 4.0.7 and the same logged-in 100-query route:

| Database layer | Response | WordPress counter at footer | Reference/captured count | WP Flame database capability |
| --- | ---: | ---: | ---: | --- |
| Query Monitor `db.php` active | 200 | 267 | Query Monitor: 259 | `unavailable` — `custom_database_layer_unsupported`; WP Flame captured 0 DB spans and did not present zero as healthy |
| Core `wpdb` restored | 200 | 265 | WP Flame: 257 | `captured` — `core_wpdb_wrapper_from_mu_plugin` |

Both references were eight queries below the footer counter. Query Monitor excludes/does not expose its own final query population, while WP Flame cannot observe the small database bootstrap that occurs before its mu-plugin can replace the already-created core `wpdb` instance. The 100 intentional fixture queries are present in the WP Flame count, the response remains unchanged, and the incompatible Query Monitor drop-in produces an explicit degraded capability instead of false equality. The script preserves and restores Query Monitor's disposable `db.php` and the original WP Flame settings.

The dated comparison result is recorded alongside the final M6 benchmark results. A native extension APM such as XHProf is not a release dependency and is not available in the standard wp-env image. The independent client wall clock is therefore the portable second timing reference for v1; this limitation is explicit rather than represented as an extension-backed validation.

## Reproduction

```bash
composer test:unit
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration
npm run compat:smoke
WP_FLAME_BENCHMARK_RUNS=100 WP_FLAME_BENCHMARK_WARMUP=10 node bin/benchmark-overhead.mjs
```

Run the multisite suite separately with `WP_MULTISITE=1`. The benchmark refuses fewer than 100 measured runs unless its explicit harness-development override is present.

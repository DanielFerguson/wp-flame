# WP Flame — Public v1 Release Roadmap

**Status:** Pre-release execution plan

**Last updated:** 2026-07-16

**Target:** Public version-one release candidate

**Commercial brief:** [COMMERCIAL-STRATEGY.md](COMMERCIAL-STRATEGY.md)

## Purpose

This is the handoff document for taking the current WP Flame codebase to a release-ready public v1. It replaces the previous feature wishlist with an ordered engineering plan, explicit dependencies, acceptance criteria, and release gates.

The roadmap is deliberately governed by one rule:

> WP Flame must become a trustworthy performance instrument before it becomes a larger feature set.

The current architecture is worth preserving. The work ahead is primarily measurement correctness, operational safety, user interpretation, production evidence, and release discipline.

## Product decision

WP Flame v1 is a self-hosted WordPress performance monitoring and diagnosis product for dynamic, uncached requests.

The intended v1 promise is:

> Capture a real WordPress request, show where the observed server-side time went, identify the largest supported contributors, recommend the next action, and verify whether a change improved it.

WP Flame v1 will:

- Capture manually requested or safely sampled WordPress request timelines.
- Show lifecycle, WordPress HTTP API, compatible database, and optional WordPress hook-callback timing.
- Attribute captured work to WordPress core, plugins, themes, mu-plugins, and supported drop-ins.
- Declare what each trace did and did not observe.
- Produce evidence-backed performance findings with safe next actions.
- Support a guided before/after verification workflow.
- Store traces locally using bounded retention and privacy-conscious defaults.
- Work without a required SaaS service or PHP profiling extension.
- Separate continuous low-risk monitoring from time-boxed Deep diagnostics.

WP Flame v1 will not:

- Claim to profile every PHP function or produce a complete PHP call stack.
- Observe requests served entirely by a page cache or CDN without running PHP.
- Measure browser page load, network latency, or Core Web Vitals.
- Automatically rewrite code, change plugin settings, disable plugins, cache pages, minify assets, or optimize images.
- Promise identical telemetry on every database layer or managed host.
- Act as a general infrastructure APM.
- Enter the security-monitoring product category.

## Primary v1 users

1. WordPress agencies and freelancers maintaining dynamic client sites.
2. WooCommerce and membership operators investigating checkout, cart, admin, AJAX, REST, cron, and logged-in performance.
3. Plugin and theme developers diagnosing performance-related support cases.
4. Teams that need evidence they can hand to a developer, host, or plugin vendor.

## Required v1 workflow

A release candidate must let an administrator:

1. Install WP Flame and see an immediate health and capability check.
2. Select a route or workflow and start a bounded capture session.
3. Capture a Standard trace manually.
4. Run an explicitly time-boxed Deep trace when callback detail is required.
5. Understand what was captured, what was unavailable, and whether the trace is incomplete.
6. See the largest measured contributors before opening the technical graph.
7. Inspect the supporting callback, query, HTTP, lifecycle, and source details.
8. Follow a prioritized recommendation or hand the evidence to a developer.
9. Repeat the workflow enough times to compare compatible baseline and after sample cohorts.
10. Keep runtime overhead, trace size, retention, and database growth bounded.

## Measurement contract

Every implementation and marketing decision must preserve these definitions:

- **Observed request duration** begins at the earliest WP Flame bootstrap point available. It is not the web server's absolute request start.
- **Persistence time** is excluded from the customer trace so WP Flame does not score its own storage work as application work.
- **Safe mode** observes supported lifecycle and WordPress HTTP API activity.
- **Standard mode** adds database capture only where a compatible strategy succeeds.
- **Deep mode** adds supported WordPress hook callbacks. It does not observe arbitrary internal PHP calls.
- **Source attribution** identifies the best supported WordPress owner; it is not proof that every internal operation was measured.
- **Unavailable telemetry** is unknown, never zero and never automatically excellent.
- **Incomplete traces** must say why they are incomplete.
- **Comparisons** must use compatible request routes, capture capabilities, and score versions.

## Release principles

- Accuracy before feature count.
- Missing telemetry is unknown, never excellent.
- Every trace declares its capture mode and capabilities.
- Deep mode is diagnostic and self-expiring, not an always-on production setting.
- WP Flame excludes its own admin, persistence, pruning, and maintenance work from customer analytics.
- Historical data remains readable after schema and score upgrades.
- Privacy-sensitive capture remains off by default and requires explicit acknowledgement.
- No instrumentor may silently fail without a visible capability warning.
- No quantified public claim ships without a dated, reproducible benchmark.
- Community and Pro must share the same measurement truth; paid packaging must not change the meaning of a trace.

## Current baseline

This snapshot records the starting point as of 2026-07-10:

- The core Collector, Span, Trace, instrumentors, storage, scoring, insight, admin, settings, privacy, CLI, and release-build patterns already exist.
- PHP lint passes across 69 files.
- The unit suite passes with 464 tests and 3,324 assertions.
- JavaScript tests pass.
- The full local PHPUnit command discovers 483 tests, but all 19 WordPress integration tests are skipped without the WordPress test framework.
- Composer validation and dependency audit pass.
- The npm audit currently fails on one moderate development dependency advisory.
- The compatibility smoke test could not be run locally because Docker was unavailable.
- The worktree contains 20 pre-existing modified tracked files. They must be reviewed and preserved rather than reset.
- Source metadata declares version 1.2.0, while the only repository tag is v0.1.0-alpha.
- The current package cannot be treated as an immutable public release.

## How to execute this roadmap

- Complete milestones in dependency order; do not bypass a milestone's validation gate.
- After M1, instrumentation, persistence, and parts of release tooling may proceed in parallel.
- Use the agent-sized backlog near the end of this document when delegating work.
- Keep each pull request focused on one behavioral contract.
- Add focused tests next to every changed behavior.
- Preserve trace backward compatibility through `Trace::fromArray()`.
- Update schema versioning, migrations, and uninstall behavior together.
- Treat hot-path changes as performance-sensitive and benchmark them proportionally.
- Update user-facing documentation only to claims supported by the current implementation.
- Check `git status --short` before every work package and never discard unrelated changes.

## Milestone sequence

| Milestone | Outcome | Depends on | May run alongside |
|---|---|---|---|
| M0 | Product and terminology contract | — | — |
| M1 | Honest trace/session schema, environment context, and capture report | M0 | — |
| M2 | Correct lifecycle, request identity, and instrumentation | M1 | M3 |
| M3 | Safe storage, retention, privacy, and lifecycle operations | M1 | M2 |
| M4 | Capability-aware score and performance insights | M2, M3 | — |
| M5 | Guided diagnosis and before/after user experience | M4 | Early M6 work |
| M6 | Accuracy, overhead, compatibility, and quality evidence | M2–M5 | Late M5 work |
| M7 | Commercial packaging and immutable release process | M5, M6 | — |
| M8 | Design-partner release candidate and public v1 gate | M7 | — |

---

## M0 — Product contract and documentation truth

### Objective

Make every internal and public description agree with the measurement contract above.

### Work

- Replace complete-profiler language with accurate request-timeline language.
- Define Safe, Standard, and Deep guarantees and limitations.
- Define manual diagnostics separately from continuous sampling.
- Remove unsupported statements such as:
  - Every plugin or theme function is timed.
  - Every query is captured on every host.
  - Active overhead is always below a fixed number.
  - Degraded mode changes only early plugin-load timing.
- Add a glossary for inclusive time, self time, source attribution, route, dropped spans, partial traces, capability, and confidence.
- Align `README.md`, `readme.txt`, `docs/FEATURE-GUIDE.md`, settings copy, onboarding copy, and the commercial brief.
- Decide deliberately whether v1 continues to support PHP 7.4. If it does, every release gate must continue covering it.
- Lock a provisional Community/Pro measurement boundary before feature implementation. Validate it through manually billed design-partner work before investing in full licensing infrastructure.
- Establish WordPress Coding Standards, a static-analysis baseline, and coverage reporting early so foundational changes do not create a large release-stage cleanup.

### Acceptance criteria

- [x] Safe and Standard are never described as function-level PHP profiling.
- [x] Deep is always described as time-boxed WordPress callback instrumentation.
- [x] No fixed overhead number appears without published benchmark evidence.
- [x] No page-load, Core Web Vitals, or automatic-optimization promise appears.
- [x] Product terminology is consistent across code, documentation, packaging, and website inputs.
- [x] Release-metadata tests detect prohibited legacy claims.
- [x] Community and Pro share the same trace accuracy, schema, scoring truth, and essential diagnostic findings.
- [x] Foundational coding-standard, static-analysis, and coverage jobs run in CI with an explicit baseline.

### Validation gate

- Documentation review is complete.
- Claim-regression tests pass.
- The public promise can be mapped directly to testable capabilities.

### Completion evidence — 2026-07-16

- V1-001: `README.md`, `readme.txt`, `docs/FEATURE-GUIDE.md`, settings, score, dashboard, graph, and finding copy now use the observed-request contract. The capability guide includes the required measurement glossary.
- V1-002: `ReleaseMetadataTest` scans public claim surfaces for prohibited legacy language and requires the supported terminology.
- V1-003: CI runs WPCS, PHPStan level 5 with WordPress stubs, and unit coverage against committed no-growth baselines. The repository keeps its established PSR-4 filenames and PSR-style formatting while enforcing the remaining WordPress rules. The current debt is explicit: 1,506 WPCS violations in 229 file/sniff groups, 51 PHPStan baseline errors, and a 67.14% statement-coverage floor across `src/`. M6 must reduce or deliberately supersede these baselines; it may not silently weaken them.
- V1-004: `docs/architecture/COMMUNITY-PRO-BOUNDARY.md` fixes one shared measurement core while reserving paid value for recurring monitoring and professional workflow.
- PHP 7.4 support remains a deliberate v1 decision and stays in the unit and WordPress integration matrices.
- The manually billed V1-005 willingness-to-pay test remains a commercial evidence activity. It is required before irreversible M7 licensing investment, but it does not block M1 measurement-integrity work.

---

## M1 — Trace integrity and capability model

### Objective

Make each trace self-describing, backward-compatible, and honest about missing or incomplete information.

### Work package M1.1 — Trace schema v2

Add and version:

- `capture_session_id`
- `capture_phase` such as `baseline`, `after`, or `observation`
- `capture_origin` such as manual, forced, sampled, or background
- `capture_policy`
- `request_type`
- `route_key`
- `http_status`
- `instrumentation_mode`
- `sample_rate`
- `effective_sample_probability`
- `capture_start_stage`
- `observed_duration_ms`
- `request_start_reference_ms` when a trustworthy reference exists
- `unobserved_prebootstrap_ms` when it can be estimated
- `wp_flame_version`
- `score_version`
- `environment_snapshot_id`
- `capabilities`
- `incomplete_reasons`
- `dropped_span_count`
- `auto_closed_span_count`
- `trace_truncated`

Suggested capability keys:

- `early_lifecycle`
- `database`
- `callbacks`
- `http`
- `graphql`
- `cache_counters`

Each capability must have a status, not only a boolean:

- `captured`
- `not_requested`
- `unavailable`
- `failed`

Intentional absence is not incompleteness. For example, Safe mode intentionally does not request DB or callback capture. A Standard trace that requested DB capture and could not register it is degraded and must explain why.

Suggested incomplete reasons:

- Early mu-plugin unavailable or stale.
- Unsupported database layer.
- Instrumentor registration failure.
- Dropped spans.
- Trace-size trimming.
- Auto-closed spans.
- Persistence metadata truncation.

### Work package M1.2 — Indexed storage dimensions

- Add indexed columns for request type, normalized route, status, mode, score version, and completeness where justified.
- Use forward-only additive migrations with downgrade-compatible reads where practical.
- Keep v1 traces readable.
- Keep uninstall cleanup synchronized.
- Avoid repeatedly decoding arbitrary trace JSON for fields used by lists and dashboards.

### Work package M1.3 — Span and persistence correctness

- Make `Collector::end_span($id)` ID-aware so an out-of-order close cannot pop another span.
- Use key-aware limits for SQL, HTTP, GraphQL, callback, and file metadata.
- Remove silent 500-byte persistence truncation for values documented with larger bounds, or change the documented bounds and record truncation.
- Make `Storage::save_trace()` return an explicit result.
- Show force-trace success and fire `wp_flame_trace_stored` only after a successful insert.
- Increment and persist dropped/truncated counts consistently.

### Work package M1.4 — Capture report

Add a visible trace-level report that explains:

- Mode requested.
- Instrumentors that succeeded.
- Instrumentors that were unavailable.
- When capture began.
- Whether sampling, limits, or errors made the trace incomplete.
- Which score factors and insights are therefore valid.

### Work package M1.5 — Capture sessions and environment snapshots

- Add a capture-session model that groups compatible observations.
- Store the intended route/workflow, policy, mode, requested count, expiry, phase, status, and trace membership.
- Use `baseline` and `after` phases for verification sessions.
- Store a deduplicated environment snapshot containing WordPress, PHP, active plugin, parent/child theme, and relevant configuration versions or hashes.
- Put the snapshot ID on each trace rather than duplicating the full inventory in every payload.
- Record environment changes between baseline and after phases and treat material changes as comparison context.
- Preserve manual single-trace observations without pretending that one request verifies an improvement.

### Acceptance criteria

- [x] Legacy trace data round-trips through `Trace::fromArray()`.
- [x] Every new trace has mode, capabilities, version, and completeness metadata.
- [x] Every trace records its origin, policy, and effective sampling probability.
- [x] Missing DB, callback, GraphQL, or cache data is distinguishable from zero activity.
- [x] Intentional mode exclusions are distinguishable from requested capture failures.
- [x] Observed duration is separated from any estimated pre-bootstrap gap.
- [x] Out-of-order closes cannot corrupt the span stack.
- [x] Full-payload opt-ins obey documented limits without silent truncation.
- [x] Failed inserts never produce success notices or stored-trace actions.
- [x] Dropped, trimmed, degraded, and auto-closed states are visible.
- [x] Capture sessions can group compatible baseline, after, sampled, and manual observations.
- [x] Environment snapshots are versioned, bounded, and referenced without bloating each trace.

### Validation gate

- Unit tests cover schema migration, malformed legacy input, out-of-order closes, bounds, and persistence failures.
- A WordPress integration test upgrades a database from the current schema.
- SQL, HTTP, and GraphQL metadata round-trip tests prove the documented limits.
- Session and environment-snapshot tests cover baseline/after membership, expiry, sampling origin, version changes, and legacy standalone traces.

### Completion evidence — 2026-07-16

- V1-010: trace schema v2 and `CaptureReport` make capture origin, policy, phase, sampling probability, start timing, capabilities, incompleteness, truncation, and version context explicit while preserving bounded legacy-v1 hydration. The trace screen renders this report before technical details.
- V1-011: storage schema v4 adds indexed request, route, status, mode, origin, session, environment, score-version, and completeness dimensions. A real WordPress/MySQL integration fixture upgraded schema v3 in place, retained its legacy trace, and verified the new columns and indexes.
- V1-012 and V1-013: collector span closing is ID-aware; dropped, auto-closed, trimmed, truncated, degraded, and close-mismatch states propagate into the trace report instead of silently corrupting the stack or payload.
- V1-014 and V1-015: persistence returns an explicit `StorageResult`; force-capture success and the stored-trace action occur only after insertion. SQL, HTTP, and GraphQL metadata use key-specific bounded limits through live capture and persistence.
- V1-016: bounded, expiring capture sessions group traces by phase and policy; deduplicated, versioned environment snapshots are referenced by fingerprint rather than copied into every trace.
- Verification: `composer test` passed 507 tests with 3,617 assertions (21 integration tests intentionally skipped in the framework-free run); the real WordPress integration suite passed 21 tests with 72 assertions against disposable MySQL; coverage passed at 68.53% (2,922/4,264 statements) against the 67.14% floor; PHP lint, PHPStan, WPCS no-growth baseline, JavaScript tests, strict Composer validation, dependency audit, and `git diff --check` all passed.
- The WordPress integration installer now selects only the first current release returned by the version API. Integration tests also establish an administrator before registering capability-gated menus, assert invalid nonce failure explicitly, and use the configured WordPress table prefix.

---

## M2 — Lifecycle, request identity, instrumentation, and attribution

### Objective

Ensure displayed phases and attributed spans correspond to real WordPress execution, with visible fallbacks where observation is impossible.

### Work package M2.1 — Lifecycle accuracy

- Rebuild lifecycle names around the actual WordPress request sequence.
- Represent the pre-instrumentation gap honestly.
- Keep observed lifecycle totals separate from an optional request-start reference and estimated unobserved pre-bootstrap time.
- Never distribute unobserved time across attributed phases.
- Correct the current post-`wp` “Main Query” boundary.
- Use appropriate phase maps for frontend, admin, AJAX, REST, cron, CLI, and GraphQL requests.
- Make degraded mode use the correct request-type phases.
- Document that persistence is intentionally outside the customer trace.

### Work package M2.2 — Request classifier and route keys

Capture:

- Frontend normalized path.
- Matched REST route, including `rest_route` requests.
- AJAX action.
- WooCommerce AJAX action.
- Cron request or event summary.
- CLI command name without arguments.
- GraphQL operation name.
- Final HTTP response status.

Route keys must remain bounded and must not retain unapproved identifiers or secrets.

### Work package M2.3 — Database capture strategy

Write an architecture decision record comparing:

- The current core-`wpdb` wrapper.
- `SAVEQUERIES` and supported query hooks.
- A capability adapter for custom database layers.
- An optional future profiler-agent path.
- An explicit unavailable state.

The selected v1 approach must:

- Never replace a custom `$wpdb` subclass.
- State its strategy and capture start point.
- Stop backtrace and normalization work when the Collector stops.
- Capture failed-query metadata safely where available.
- Avoid claiming early-query coverage when capture registered later.
- Leave missing DB score factors unknown.

### Work package M2.4 — Callback diagnostics

- Make Deep mode a per-request or expiring override.
- Preserve by-reference, return-by-reference, non-callable, and removal-safety exclusions.
- Resolve callback metadata lazily and cache it.
- Record hook, priority, source, caller, and wrap limitation where safe.
- Ensure Safe and Standard never mutate `WP_Hook` callbacks.
- Restore the prior mode automatically after a forced Deep request.

### Work package M2.5 — WPGraphQL

- Replace the current early request-constant decision.
- Register operation and resolver hooks lazily.
- Use a stack for multiple or batched operations.
- Attribute resolvers to their actual callback/source where possible.
- Keep resolver limits and thresholds explicit.
- Require actual operation and resolver spans in the live compatibility test.
- If those assertions cannot be made reliable, remove WPGraphQL-specific v1 claims and ship generic supported telemetry only.

### Work package M2.6 — Source and cache attribution

- Distinguish child theme, parent theme, plugin, mu-plugin, supported drop-in, core, and unknown sources.
- Store bounded caller function/file/line where available.
- Aggregate callbacks by source, callback, and hook rather than short name alone.
- Resolve plugin/theme display names and versions outside hot paths.
- Use `wp_using_ext_object_cache()` to report that an external cache is configured.
- Report backend health only when an adapter exposes a measurable health or counter signal; otherwise show health as unknown.
- Prevent false “install a persistent cache” advice.

### Work package M2.7 — Self-observation

- Exclude WP Flame Tools, Settings, persistence, pruning, migration, and maintenance work from normal analytics.
- Keep a separate diagnostic path for debugging WP Flame itself.
- Ensure viewing the dashboard cannot alter the figures it is displaying.

### Acceptance criteria

- [x] Lifecycle labels match WordPress core order.
- [x] The real main query is not hidden inside a generic Routing label.
- [x] Degraded traces use the correct request-type phase map.
- [x] REST, AJAX, WooCommerce AJAX, cron, CLI, and GraphQL operations have useful normalized identities.
- [x] Unsupported database layers remain untouched and display a capability warning.
- [x] Child-theme code is not attributed to WordPress core.
- [x] Standard mode never wraps callbacks.
- [x] Deep mode automatically expires.
- [x] Current WPGraphQL produces tested operation/resolver spans or is not advertised as doing so.
- [x] No avoidable instrumentor work continues after collection stops.
- [x] WP Flame does not pollute customer analytics.

### Validation gate

- WordPress integration fixtures cover every request type and phase order.
- Request and lifecycle fixtures are added with M2 rather than deferred to the final release-validation milestone.
- Compatibility tests cover stock `wpdb`, a custom subclass, and pre-defined `SAVEQUERIES` states.
- Child-theme, WooCommerce, Elementor, bbPress, object-cache, and WPGraphQL smoke fixtures pass for every claimed capability.
- Instrumentation-on and instrumentation-off responses have equivalent status and output.

### Completion evidence — 2026-07-16

- V1-031: `RequestClassifier`, `RouteResolver`, and request-specific `Lifecycle` maps cover frontend, admin, REST, AJAX, WooCommerce AJAX, cron, CLI, and GraphQL. Frontend phases put main-query preparation and the real main query before `wp`; early and degraded capture use explicitly different initial labels while sharing the request-type transition map.
- V1-032: database capture follows the accepted core-`wpdb` wrapper decision in `docs/architecture/DATABASE-CAPTURE-STRATEGY.md`. Custom subclasses remain untouched and report an unavailable capability. WPGraphQL uses WordPress query metadata only when `SAVEQUERIES` is active and otherwise falls back to the compatible core wrapper. DB, HTTP, and GraphQL hot-path work stops immediately when collection stops; failed queries retain only a bounded failure signal.
- V1-033 and V1-036: source attribution distinguishes plugin, mu-plugin, child theme, parent theme, supported drop-in, core, and unknown. Cache guidance uses `wp_using_ext_object_cache()` and treats non-public or absent counters as unknown. Environment version discovery and persistence execute after the customer trace is stopped.
- V1-034: live wp-env checks against WPGraphQL 2.17.0 require a named operation span and `RootQuery.generalSettings` resolver span in Safe, Standard, and bounded Deep modes. The implementation supports current and legacy operation hooks, stacked/batched operations, bounded resolver labels, and explicit missing-hook capability evidence.
- V1-035: callback metadata is lazy and cached; compatibility exclusions are counted; Safe and Standard do not register callback instrumentation. Deep is one-shot or expires within 15 minutes, and permanent saved Deep values are downgraded to Standard. Exact self-source matching prevents WP Flame callbacks from polluting traces without excluding unrelated slugs that merely contain `wp-flame`.
- V1-037: the live compatibility gate passed with WooCommerce 10.9.4, Elementor 4.1.5, bbPress 2.6.14, WPGraphQL 2.17.0, a generated child theme, core `wpdb`, and Safe/Standard/Deep mode assertions. It verifies identical normalized body/status output with instrumentation off and on, mode-specific DB/callback behavior, current GraphQL operation/resolver spans, runtime WooCommerce `wpdb` properties, and child-theme callback attribution. Unit/integration fixtures cover request routes and phase order, custom DB subclasses, active/disabled `SAVEQUERIES`, object-cache evidence, callback exclusions, bounded expiry, and self-observation.
- Verification: `composer test` passed 548 tests with 3,686 assertions (32 integration tests intentionally skipped in the framework-free run); the real WordPress/MySQL integration suite passed 32 tests with 104 assertions; statement coverage passed at 69.99% (3,179/4,542) against the 67.14% floor. PHP lint, PHPStan, the 1,468-violation/227-group WPCS no-growth baseline, JavaScript tests, strict Composer validation, dependency audit, shell syntax, live wp-env compatibility, and `git diff --check` all passed.

---

## M3 — Operational safety, retention, privacy, and lifecycle operations

### Objective

Make installation, upgrades, capture, storage, cleanup, and uninstall bounded and trustworthy on real sites.

### Work package M3.1 — Safe mu-plugin ownership

- Add a verifiable ownership marker or signature.
- Refuse to overwrite an unowned collision.
- Write to a temporary file and use an atomic rename.
- Verify the installed content/version.
- Remove the file only when its ownership signature still matches.
- Surface degraded mode and recovery steps when installation fails.

### Work package M3.2 — Storage quotas and resumable retention

- Add configurable row and byte ceilings.
- Scope quotas explicitly per site and define network-level reporting for multisite.
- Prune in bounded, time-budgeted batches.
- Schedule continuation when a batch fills until the retention backlog is cleared.
- Expose oldest expired row, estimated storage, quota state, and last cleanup result.
- Prune expired data before enforcing the hard quota.
- Pause automatic capture at the hard ceiling and resume automatically below it.
- Do not silently evict unexpired traces.
- Do not let manual capture bypass the hard ceiling; show a clear purge-or-increase action and allow the user to retry.
- Choose and document the default quota from measured trace-size and storage benchmarks.
- Align the configurable span limit with the actual Trace and renderer limit.

### Work package M3.3 — Migrations and multisite

- Add a migration lock and health state.
- Prevent large backfills from running unbounded on a frontend request.
- Batch large-network activation, upgrades, cleanup, and uninstall.
- Provide a CLI-safe maintenance path.
- Test partial migration recovery and retries.

### Work package M3.4 — Privacy contract

- Register suggested site privacy-policy text.
- Publish a complete data inventory and retention description.
- Strip SQL comments before storage.
- Separate stored display URLs from privacy-safe normalized route keys.
- Improve display-URL redaction for names, identifiers, and custom application routes without destroying stable route grouping.
- Make exporters include all personal data categories that can be retained.
- Explain the erasure limitation for anonymous data and provide bounded deletion controls.
- Require explicit acknowledgement for identity, raw SQL, full URL, user-agent, IP, and GraphQL query capture.
- Keep all external communication disabled unless a later feature receives explicit consent.

### Work package M3.5 — First-run defaults and health

- Do not begin broad production capture before onboarding.
- Default to manual-only capture. Admin sampling is a separate explicit opt-in policy.
- Add health states for:
  - Trace table missing or unwritable.
  - Mu-plugin missing or stale.
  - DB capture unavailable.
  - Pruning backlog.
  - Storage quota reached.
  - Repeated persistence failures.
- Make failures recoverable without taking the monitored site down.

### Work package M3.6 — Aggregate rollups

- Design versioned rollups for route/request-type cohorts, source/type time, callback evidence, score versions, capability coverage, and sample count.
- Populate rollups at ingest or through bounded resumable jobs.
- Keep rollups derivable and versioned so algorithm changes do not silently rewrite historical meaning.
- Move dashboard queries away from repeatedly decoding arbitrary trace JSON.
- Make M4 trends and budgets depend on these rollups.

### Acceptance criteria

- [x] Activation cannot overwrite an unowned mu-plugin file.
- [x] Deactivation/uninstall cannot remove a changed or unowned file.
- [x] More than 5,000 expired traces are eventually pruned.
- [x] Storage cannot grow indefinitely past its configured ceiling.
- [x] Settings cannot promise more retained spans than the trace can hold.
- [x] Large multisite operations are bounded and resumable.
- [x] Migrations do not run an unbounded table backfill on a normal frontend request.
- [x] Privacy-policy text and the data inventory match actual stored data.
- [x] Default capture does not surprise an operator or trace every visitor.
- [x] Storage and health failures are visible without affecting the application response.
- [x] Dashboard rollups disclose their source population, capability cohort, algorithm version, and sample count.

### Validation gate

- Collision, atomic-install, changed-file, deactivate, and uninstall tests cover the mu-plugin lifecycle.
- Retention backlog, quota, full-disk/insert-failure, and migration-recovery tests pass.
- Multisite activation, upgrade, prune, deactivate, and uninstall tests pass.
- Privacy export, erasure, redaction, and sensitive-opt-in integration tests pass.
- Rollup backfill, versioning, resumability, and population-consistency tests pass.

### Completion evidence — 2026-07-16

- V1-013: `MuPluginManager` uses an ownership marker, exact hash verification, same-directory temporary write, atomic rename, collision refusal, and exact-content removal. Activation, drift repair, deactivation, and uninstall share the same ownership contract and preserve degraded operation when the destination is unavailable.
- V1-014: Per-site row and encoded-trace byte quotas stop persistence at 10,000 rows or 512 MB by default, after bounded expired-data pruning. Automatic capture pauses at the hard ceiling, unexpired traces are not evicted, and manual capture receives the same refusal. The measured local corpus and sizing rationale are recorded in `docs/architecture/STORAGE-QUOTA.md`.
- V1-015: Retention deletes in 500-row batches, at most ten batches or 250 ms per run, and schedules continuation. Unit evidence clears a 5,001-row backlog. Daily rollup buckets expire at day granularity and purge-all removes both raw and derived data.
- V1-016: Schema v5 uses a 300-second migration lock, additive `dbDelta` changes, 500-row legacy backfills, visible health state, cron continuation, and explicit `wp flame migrate`. Network activation, migration, prune, deactivate, and uninstall use checkpointed ten-site batches with retry state.
- V1-017: `docs/PRIVACY.md` inventories actual storage and opt-ins. WordPress policy text, complete bounded trace export, 500-row erasure, anonymous-erasure limitation, SQL-comment stripping, route/display redaction, and a shared sensitive-data acknowledgement are enforced and covered by unit/integration tests.
- V1-018: New installs are manual-only. Automatic sampled capture is explicit, while a nonce-bound manual capture can operate when automatic capture is off. Missing/stale mu-plugin, trace table, migration, database layer, retention, quota, persistence, and rollup health states remain recoverable without changing the monitored response.
- V1-019: `Rollup::VERSION` projects daily route/request, mode, score, capability, source/type, and callback dimensions asynchronously. A 25-trace/250 ms worker, 60-second stale lock, per-trace transactions, corrupt-row isolation, bounded 100-dimension projection, CLI continuation, and current-version dashboard queries are documented in `docs/architecture/ROLLUPS.md`.
- Real WordPress/MySQL gates pass: single-site 45 tests/416 assertions with the intentional multisite-only skip; multisite 45 tests/437 assertions with no skips. Unit 534 tests/3,782 assertions, PHP lint, PHPStan, JavaScript tests, and the no-growth WPCS baseline also pass.

---

## M4 — Capability-aware score and performance insights v2

### Objective

Turn observed data into defensible conclusions without rewarding incomplete capture.

### Work package M4.1 — Score v2

- Version the algorithm.
- Treat unavailable factors as unknown.
- Renormalize only across observed, valid factors.
- Persist the overall score, factor results, version, and capability cohort.
- Use the persisted factor snapshot on historical views.
- Keep list and detail scores identical.
- Separate request-type cohorts where thresholds differ.
- Never compare trends across incompatible versions or capabilities.

### Work package M4.2 — Insight contract

Expand each finding with:

- Related span IDs.
- Owning source and version.
- Measured impact.
- Evidence count.
- Confidence.
- Required capability.
- Action type.
- Plain-language detail.
- Concrete remediation or handoff guidance.
- Verification instruction.

### Work package M4.3 — v1 performance rules

Prioritize:

- Slow unique database query.
- Repeated/fingerprinted query.
- Failed database query where observable.
- Slow or failed external HTTP call.
- Blocking HTTP during an early phase.
- Slow callback.
- Persistent-cache evidence and uncertainty.
- Incomplete capture warning.
- Route-specific budget violation.

Do not add unrelated product categories or automatic fixes.

### Work package M4.4 — Cohort-aware trends

- Group results by normalized route and request type.
- Compare only compatible capability and score cohorts.
- Use p50, p95, sample count, and time window rather than a single average.
- Stop deriving seven-day conclusions from a biased newest-small-trace subset.
- Add rollups or summaries that avoid repeated large JSON decoding.

### Acceptance criteria

- [x] Safe mode cannot receive a perfect DB result because DB capture is absent.
- [x] Custom-DB traces display DB as unavailable.
- [x] Stored, listed, and detailed score factors remain identical.
- [x] Every finding names its evidence and required capability.
- [x] Incomplete traces cannot produce high-confidence negative conclusions.
- [x] Cache guidance does not fire when persistent-cache evidence is unavailable.
- [x] A single materially slow query produces a useful finding.
- [x] Trend charts disclose sample count, period, route, mode, and cohort.

### Validation gate

- Golden score fixtures cover each request type and capability set.
- Score-v1 traces remain displayable without being mixed into Score-v2 trends.
- Every rule has positive, negative, unavailable, malformed, and truncated-data tests.
- Dashboard aggregates are tested against the same known trace population.

### Completion evidence — 2026-07-16

- V1-020: `Score::VERSION = 2` persists the exact overall/factor snapshot, configured and applied weights, observed/unknown status, required capabilities, observed weight, and capability-cohort hash. Unknown and unrequested factors receive zero applied weight and remaining observed factors renormalize. Legacy v1 traces preserve their stored overall score and are visibly labelled as lacking a historical factor snapshot.
- V1-021: The finding contract now carries span IDs, source/version where the environment snapshot can resolve it, measured impact, evidence count, confidence, required capability, action type, bounded next action, and verification instruction. Incomplete/truncated/dropped captures force low confidence; capability-gated rules do not run against unavailable telemetry.
- V1-022: The v1 rule set covers slow unique and repeated queries, observable failed queries, slow/failed/blocking HTTP, slow callbacks, persistent-cache evidence and uncertainty, incomplete capture, and route budgets. Focused fixtures cover positive, negative, unavailable, malformed, and incomplete/truncated behavior.
- V1-023: Daily rollups now include a committed duration histogram. `get_route_cohort_trends()` isolates normalized route, request type, mode, capability cohort, rollup version, and score version, and returns bounded p50/p95 estimates, sample/complete counts, window, query averages, and score averages without raw JSON decoding.
- The detail view uses the stored Score v2 snapshot, exposes unknown factors without an invented grade, and renders evidence/next-action/verification context above the technical graph. The dashboard discloses seven-day compatible route cohorts and labels percentile precision as bounded histogram estimates.
- Release gates pass: 544 unit tests/3,829 assertions; PHP lint across 98 files; PHPStan; JavaScript tests; WPCS no-growth baseline at 1,399 violations/220 groups; WordPress/MySQL single-site 47 tests/428 assertions with one intentional multisite-only skip; multisite 47 tests/449 assertions without skips.

---

## M5 — Explainable diagnosis and verification experience

### Objective

Make WP Flame useful to a capable site owner or agency without requiring them to interpret raw profiler output.

### Work package M5.1 — Guided capture

- First-run capability and health check.
- Trace the current frontend page through the admin bar, or arm a short-lived capture session and ask the user to navigate or reproduce the workflow.
- Do not add server-side authenticated URL replay or synthetic browsing to v1.
- Choose Standard or one-shot Deep capture.
- Set bounded request count or expiry.
- Explain likely overhead and unavailable capture before starting.
- Show capture progress, failure, and the exact stored trace.

### Work package M5.2 — Top opportunities

Place a plain-language summary above the graph:

- What is slow.
- Which component owns the measured work.
- How much time it contributes.
- How many observations support the conclusion.
- Confidence and capture completeness.
- The safest next action.
- Whether a developer is needed.
- How to verify the result.

### Work package M5.3 — Span detail panel

Preserve span metadata in the browser model and show:

- Inclusive and self time.
- Percentage of observed request.
- Source and source version.
- Observed callback.
- Hook and priority.
- Bounded caller file/line.
- Normalized SQL fingerprint/hash.
- HTTP host, method, status, and error.
- Template or operation metadata.
- Truncation and auto-close state.

Add source/type search, filtering, keyboard selection, accessible focus behavior, and leaf-span inspection.

### Work package M5.4 — Before/after cohort comparison

- Compare capture-session cohorts only when route, request type, mode, capability set, score version, and relevant environment context are compatible.
- Use 10 baseline and 10 after observations as the initial directional-session hypothesis. Require at least 20 compatible observations in each cohort before the product labels an improvement verified, and allow product validation to raise that threshold.
- Compare p50, distributions, DB, HTTP, query count, top sources, top callbacks, score factors, and capability differences. Show p95 only when the cohort meets its documented minimum and always disclose uncertainty.
- Show sample counts and confidence and explain when a conclusion is weak.
- Warn when plugin, theme, WordPress, PHP, or relevant configuration snapshots differ.
- Explain when a comparison is invalid.
- Generate a locally downloadable, redacted comparison report.
- Require explicit inclusion of sensitive fields.

### Work package M5.5 — Dashboard

- Separate frontend, admin, REST, AJAX, cron, CLI, and GraphQL populations.
- Use normalized route keys.
- Show p50, p95, distribution, sample count, and capability coverage.
- Ensure every chart and list uses a disclosed, consistent population.
- Keep technical detail available without making it the first screen.

### Acceptance criteria

- [x] A new user can capture and understand a trace without editing settings first.
- [x] Deep never remains enabled accidentally.
- [x] A user can explain a unique slow query or HTTP request without opening raw JSON.
- [x] Every incomplete trace says why it is incomplete.
- [x] The graph and detail controls are keyboard accessible.
- [x] A comparison rejects or clearly marks incompatible cohorts.
- [x] A single before and after request is never presented as a verified improvement.
- [x] Export is redacted by default.
- [x] The maximum supported trace remains navigable.

### Validation gate

- End-to-end browser tests cover onboarding, current-page capture, armed workflow capture, Standard capture, Deep capture, persistence failure, trace inspection, and cohort comparison.
- JavaScript tests cover missing, malformed, cyclic, oversized, and metadata-rich spans.
- Accessibility review covers semantics, focus, contrast, keyboard behavior, and reduced motion.
- A large-trace rendering benchmark is recorded.
- Usability sessions confirm non-technical participants can identify the intended top finding.

### Engineering completion evidence — 2026-07-16

- V1-024: Tools > WP Flame now provides health-aware, bounded guided capture. Standard sessions accept 1–20 requests for at most 15 minutes; Deep stores exactly one request and expires after five minutes. The selected frontend/admin/AJAX/REST/GraphQL population is fixed before arming, and an atomic first-match claim pins one normalized route so incidental browser traffic cannot contaminate the cohort.
- V1-025: Capture sessions use a nonce-bound, admin-only, HttpOnly/SameSite browser cookie plus server-side expiry, request count, phase, status, progress, exact latest-trace link, and cancellation. WP Flame does not replay an authenticated URL. Live WordPress validation caught and fixed both a background-REST cohort claim and a shared-`wpdb` result invalidation fatal.
- V1-026: The detail view ranks up to five evidence-backed opportunities above the technical timeline. Findings state ownership/version where known, measured contribution, evidence count, confidence/completeness, help level, safest next action, and a compatible verification instruction; an empty result explicitly says that no supported threshold fired rather than claiming health.
- V1-027: The browser model preserves bounded span metadata and exposes inclusive/self time, observed-request percentage, source/version, callback/hook/priority, content-relative caller, SQL fingerprint/shape, HTTP result, template/operation, and truncation/auto-close state. Search and source/type filters update a live count; hidden matches leave pointer and keyboard focus order; Enter/Space selection and reduced-motion/responsive styles are supported.
- V1-028: Baseline/after comparison is valid only for one compatible normalized route, request population, instrumentation mode, capability cohort, score version, and relevant environment. Ten observations per cohort are directional; 20 are required for verified and p95. The UI compares p50, five-number duration distribution, DB/HTTP/query/score/completeness metrics, capability states, top sources/callbacks, and score factors, with explicit invalidity, uncertainty, and controlled plugin/theme-change warnings.
- V1-029: Local comparison JSON excludes raw spans, SQL, full HTTP URLs, identities, and user agents. The normalized route is redacted by default and requires an explicit sensitive-field checkbox. The dashboard continues to disclose request populations, normalized routes, p50/p95 bounds, sample/completeness counts, versions, modes, and capability cohorts.
- V1-030: The 6,000-span deterministic fixture caps rendering at 5,000 supported spans and recorded a 22 ms median/30 ms maximum over five local runs; see `docs/benchmarks/M5-LARGE-TRACE.md`. Live browser validation covered guided Standard, one-shot Deep, persistence failure/recovery, exact trace inspection, keyboard leaf evidence, filtering, insufficient comparison, and export controls.
- Release gates pass: 553 unit tests/3,871 assertions; PHP lint across 102 files; PHPStan; JavaScript malformed, cyclic, depth, missing-element, metadata-rich, filter-accessibility, and oversized fixtures; WPCS no-growth baseline at 1,380 violations/213 groups; WordPress/MySQL single-site 48 tests/444 assertions with one intentional multisite-only skip; multisite 48 tests/465 assertions without skips.
- The scripted non-technical participant study is intentionally not claimed as complete. `docs/validation/M5-USABILITY-PROTOCOL.md` defines the five-participant gate, success criteria, and privacy-safe record. Its result remains an external M8 release-candidate condition rather than fabricated engineering evidence.

---

## M6 — Accuracy, overhead, compatibility, and quality evidence

### Objective

Replace safety assumptions and marketing estimates with reproducible evidence.

### Work package M6.1 — Accuracy fixtures

Consolidate deterministic fixtures that were added alongside M1–M5. M6 is the cross-system evidence pass, not the first time these tests are written.

The combined fixture set must include known delays and ownership for:

- Core-compatible and custom database layers.
- Slow and repeated queries.
- Slow and failed WordPress HTTP calls.
- Nested callbacks.
- Child and parent themes.
- Degraded mode.
- REST, AJAX, WooCommerce AJAX, cron, CLI, and GraphQL.
- Persistence failure.
- Dropped and truncated traces.

Compare results against Query Monitor and at least one independent PHP/APM reference where practical. Explain expected differences rather than forcing artificial equality.

### Work package M6.2 — Overhead benchmark

Measure plugin disabled, sampled-out, Safe, Standard, Deep, shutdown persistence, and dashboard rendering.

Use a documented warm-up and at least 100 measured runs per representative scenario unless the methodology explains why a larger sample is required.

Use at minimum:

- Vanilla WordPress.
- WooCommerce frontend, checkout, and admin.
- Elementor page.
- REST and AJAX.
- Cron or Action Scheduler.
- Current WPGraphQL if claimed.
- A 100-query request.
- A 2,000-span request.
- A maximum-supported trace.

Record:

- p50 and p95 wall-time delta.
- CPU where the environment supports it.
- Peak-memory delta.
- Trace bytes.
- Database growth.
- Dropped/truncated spans.
- Output and response-status equivalence.

Set budgets only after observing representative results. Publish methodology, environment, sample size, results, and limitations.

### Work package M6.3 — Compatibility matrix

Cover:

- WordPress 6.0 lower bound and the current release.
- PHP 7.4 if retained, plus 8.1, 8.3, 8.4, and 8.5.
- MySQL and MariaDB.
- No-mu-plugin managed-host mode.
- Persistent object-cache drop-ins.
- Custom database subclass.
- Multisite.
- WooCommerce.
- Elementor.
- bbPress.
- WPGraphQL if claimed.

Every matrix row must state which capture capabilities are expected, unavailable, or degraded.

### Work package M6.4 — Automated quality gates

Foundational WPCS, static-analysis, and coverage baselines begin in M0/M1. Browser and accessibility coverage begins with M5. M6 raises and enforces the final release thresholds.

Complete:

- WordPress integration tests that cannot silently skip in CI.
- WordPress Coding Standards/PHPCS enforcement.
- Plugin Check.
- PHP static analysis with no unexplained baseline growth.
- Coverage reporting and a non-regression policy.
- JavaScript/browser end-to-end tests.
- Accessibility checks.
- MariaDB and PHP 8.5 lanes.
- Multisite integration coverage.
- Package-content validation.
- Performance-regression checks for representative fixtures.
- Resolution of the current npm audit failure, or a dated, documented accepted-risk exception with a removal owner.
- A release-time application assurance review covering capabilities, nonces, stored personal data, SQL handling, DOM safety, and failure behavior. Authenticated update delivery receives a follow-up review after it is implemented in M7. This is release assurance, not a customer-facing monitoring feature.

### Acceptance criteria

- [x] Phase and attribution fixtures report the known owner within documented limitations.
- [x] Every supported/degraded behavior combination has current deterministic evidence; platform-version lanes remain an immutable RC-commit gate.
- [x] Mode-specific overhead results are published.
- [x] No “production-safe” claim appears without an explicit tested budget.
- [x] CI fails if integration tests are skipped.
- [x] Plugin Check, lint, static analysis, unit, integration, JS, browser, compatibility, audit, and package gates pass.
- [x] Composer and npm dependency audit gates pass or have an explicit time-bounded accepted-risk record.
- [x] The release assurance review has no unresolved release-blocking finding.
- [x] Known limitations are documented alongside the compatibility matrix.

### Engineering completion evidence — 2026-07-16

- V1-070: `docs/validation/M6-ACCURACY-FIXTURES.md` maps deterministic database, query, HTTP, callback, ownership, degraded, request-type, GraphQL, failure, truncation, and response-equivalence workloads to their expected evidence and limitations. Query Monitor 4.0.7 and WordPress's independent footer counter were compared against the same 100-query route; the documented eight-query boundary difference is expected, and an unsupported Query Monitor database drop-in produces an explicit unavailable capability rather than a false zero.
- V1-071: The reproducible 31-row overhead harness enforces warm-up, sample count, request success, status/body equivalence, trace size/growth, truncation, and mode/scenario budgets. The final 100-run publication is in `docs/benchmarks/M6-OVERHEAD-RESULTS.md`. It found and drove remediation of a payload-sized quota scan; schema v6 now stores exact `trace_bytes` and backfills legacy rows in bounded 500-row batches.
- V1-072: `docs/compatibility/MATRIX.md` states expected captured, not-requested, unavailable, and degraded capabilities for supported WordPress/PHP/database platforms, managed-host mode, object-cache/custom-database layers, multisite, request types, WooCommerce, Elementor, bbPress, and WPGraphQL. Live full-stack compatibility passed with response equivalence and mode-specific assertions.
- V1-073: CI enforces WPCS, PHPStan, a deliberate 63.41% unit statement-coverage floor, no-skip WordPress integration, JavaScript, Playwright/axe, compatibility, performance, Composer/npm audits, production-package validation, and official packaged Plugin Check. The assembled ZIP returned zero Plugin Check errors; its documented warnings do not conceal a failing check.
- V1-074: The matrix includes PHP 7.4, 8.1, 8.3, 8.4, and 8.5, WordPress 6.0/current, MySQL 8, MariaDB 11.4, and a dedicated multisite lane. Local release evidence passes 553 unit tests/3,876 assertions, 48 single-site integration tests/456 assertions, 49 multisite tests/477 assertions, five browser/axe workflows, the full compatibility stack, and the CI-sized 31-row performance regression check. Tagged CI remains the immutable platform-matrix proof required by M8.
- V1-075: `docs/validation/M6-RELEASE-ASSURANCE.md` reviews capabilities, authorization/nonces, stored personal data, SQL, trace-controlled DOM content, failure isolation, storage bounds, external communication, dependencies, and the required M7 updater follow-up. No release blocker remains in the Community measurement scope. Composer reports no advisories and npm reports zero known vulnerabilities.

---

## M7 — Commercial packaging and immutable release process

### Objective

Create a supportable paid product without compromising measurement truth or WordPress distribution rules.

### Work package M7.1 — Edition architecture

Confirm or revise the provisional boundary from M0 using paid, manually billed design-partner evidence before implementing the full edition architecture. The recommended starting architecture is:

- A genuinely useful Community edition.
- A separate off-directory Pro add-on.
- Shared, versioned trace and extension contracts.
- No Community feature that is merely present and locally locked behind payment.
- No difference in trace accuracy between editions.
- License expiry stops updates, support, and connected services, not already-installed local functionality.

**RC decision:** `1.3.0-rc.1` uses one directly distributed paid design-partner package with no Community/Pro code split. `docs/architecture/RC-DISTRIBUTION.md` records this reversible channel. A later public Community/Pro decision remains gated on paid evidence.

### Work package M7.2 — Licensing and updates

- Select checkout, tax, licensing, and secure-update infrastructure.
- Sign or otherwise authenticate update metadata.
- Handle activation limits, staging/local sites, expiration, renewal, rollback, and failed updates.
- Keep trace capture functional when the licensing service is unavailable.
- Require explicit consent before any license, analytics, or service request leaves the site.
- Review updater authenticity, rollback, entitlement failure, and external-request behavior before packaging.

The design-partner RC deliberately has no runtime licensing client or custom updater. Payment and delivery are handled outside the plugin, and candidate packages/checksums are supplied manually to the small cohort. This makes licensing outage non-applicable to local capture while preserving the full follow-up gate before any service or updater is added.

### Work package M7.3 — Release metadata

- Resolve the current dirty changes through reviewed commits.
- Choose a monotonic public version strategy.
- Synchronize plugin header, constant, mu-plugin version, readme stable tag, schema version, and changelog.
- Move all shipped changes out of Unreleased.
- Remove CI's release-safeguard bypass.
- Update Tested up to for the verified current WordPress version.
- Add the standalone GPL license file.

### Work package M7.4 — Reproducible package

- Build only from a clean tagged commit.
- Validate exact archive contents.
- Run the complete release gate on the tag.
- Publish an immutable archive and SHA-256 checksum.
- Prove clean installation, forward upgrade, application-package rollback using downgrade-compatible reads, deactivation, and uninstall.
- Do not implement destructive database down-migrations as part of package rollback.
- Retain build provenance and release notes.

### Work package M7.5 — Support and trust material

Prepare:

- User documentation and quickstart.
- Mode/overhead guide.
- Compatibility and known-limitations page.
- Privacy and data-handling documentation.
- Terms, refund policy, and support scope.
- Update and license-expiry behavior.
- Responsible disclosure process for the plugin itself.
- Diagnostic support bundle that defaults to redacted data.

### Acceptance criteria

- [x] The single-edition RC boundary satisfies its direct paid design-partner distribution channel.
- [x] Licensing failure cannot disable local monitoring: the RC has no licensing runtime dependency.
- [x] The RC originates no WP Flame service, license, update, analytics, or telemetry request.
- [x] Release metadata is synchronized.
- [ ] The artifact comes from a clean tag and has a published checksum.
- [x] Install, forward-upgrade, compatible package-rollback, deactivate, and uninstall tests pass.
- [ ] Documentation, support, refund, privacy, and update policies are published.

### Engineering progress evidence — 2026-07-16

- **V1-083:** `1.3.0-rc.1` is synchronized across the plugin header, runtime constant, mu-plugin, readme stable tag, and changelog. The changelog has an empty Unreleased section, CI no longer bypasses release safeguards, Tested up to reflects the verified WordPress version, and the distributable includes the standalone GPLv2 license.
- **V1-084 (local proof):** repeated builds from the reviewed committed branch are byte-identical, and `bin/release-package.sh` enforces a clean exact version tag before producing the immutable archive, SHA-256 checksum, and provenance record. Publication remains intentionally open until the review branch is published and merged, the exact tag is created, and the complete tag gate passes.
- An isolated temporary-repository simulation of the current candidate also passed the clean exact `v1.3.0-rc.1` tag path and produced a byte-identical repeated build, checksum file, and `wp-flame-release-provenance.v1` record. This proves the mechanism, not the official artifact; its synthetic commit and checksum must never be published as the release.
- Version-tag pushes now run the complete CI matrix. The tag-only release job depends on every quality, unit, integration, multisite, MariaDB, compatibility, E2E/performance, Plugin Check, and package job before it can upload the archive, checksum, and provenance bundle.
- **V1-084 (lifecycle):** `bin/package-lifecycle-smoke.sh` now uses an authentic package built from the pre-RC `1.2.0` commit rather than a version-relabelled current tree. It passes exact-hash adoption of the pre-ownership early loader, forward schema/application upgrade, data-retaining application rollback with the legacy Bootstrap fallback, return to the candidate, deactivation/reactivation, and uninstall cleanup. This corrected two false-positive gates that had hidden a degraded upgrade and rollback fatal.
- A redacted-by-default `wp flame support-bundle` command and its unit tests are present. Quickstart, overhead/mode, compatibility, privacy, support, update/license, responsible-disclosure, GPL-aware terms, and refund-draft material are prepared in the repository.
- The distributable now carries every user-facing document linked by its README, and a release-metadata test recursively rejects missing or unpackaged relative documentation links. Internal roadmap, validation, publication-input, refund-draft, and terms-draft files remain outside the ZIP.
- The self-contained `1.3.0-rc.1` package was rescanned with Plugin Check 2.0.0 using CI's non-PHPCS configuration and returned no errors. Its warnings remain the documented direct-database/instrumentation, generated-autoloader, external-hook, global, and WordPress.org name/slug constraints; they do not silently become approval for a WordPress.org submission.
- `docs/commercial/PAID-DESIGN-PARTNER-GATE.md` prevents irreversible Pro/licensing work before five real paid transactions and repeat-use evidence. `docs/commercial/LICENSING-VENDOR-EVALUATION.md` records the reversible first-checkout recommendation, official vendor evidence, and mandatory sandbox proof.
- `docs/architecture/RC-DISTRIBUTION.md` selects a single complete GPL package, direct paid delivery, manual checksum-verified candidate updates, and no runtime licensing or update dependency for the RC. A release-metadata test guards the absence of outbound WP Flame service initiators in packaged PHP.
- `docs/commercial/PUBLICATION-INPUTS.md` records the completed repository authorization and the remaining seller, checkout, URL, support-capacity, review-branch, tag, and publication inputs without treating placeholders as a pass.
- `docs/validation/M7-RC-ENGINEERING-HANDOFF.md` consolidates the current exact gate results, corrected authentic predecessor/rollback evidence, tag-release contract, documented package warnings, diagnostic local checksum, and owner-controlled gates.
- **Open M7 boundary:** V1-080 is complete for the single-edition direct RC. V1-081 and V1-082 are conditional public-sales work and must not begin unless paid evidence selects an architecture that needs licensing or a custom updater. The complete candidate has been reviewed into logical commits on `feat/wp-flame-v1-rc`; the remaining repository gate is publication/review/merge followed by the exact tag and its immutable CI workflow. Owner/legal/support publication details remain external inputs, and no vendor selection is required for the direct RC.

---

## M8 — Design-partner RC and public v1 gate

### Objective

Validate the release candidate on real sites before broad paid sales.

### Programme

- Recruit 10–20 paid design partners, primarily agencies and WooCommerce operators.
- Require each partner to bring at least one real slow dynamic workflow.
- Obtain separate written authorization for every production site and separate consent for trace review, research notes, testimonials, and case studies.
- Use manual onboarding and record every capability gap or misleading conclusion.
- Review trace accuracy, operator understanding, overhead, storage, retention, support burden, and fix verification weekly.
- Produce at least three permissioned before/after case studies.
- Run the RC for at least two weeks without WP Flame-caused fatal errors, data loss, unbounded storage, or unexplained WP Flame-caused performance regressions.

Operate the programme through:

- `docs/validation/M8-RC-PROGRAM.md` for entry, onboarding, evidence, weekly review, and exit rules.
- `docs/validation/M8-BLOCKER-REGISTER.md` for severity-controlled defect and release-gate state.
- `docs/validation/M8-CASE-STUDY-TEMPLATE.md` for separately permissioned, cohort-compatible before/after evidence.
- `docs/validation/M8-GO-NO-GO.md` for the candidate-specific public-release decision ledger.

The operating pack is prepared, but M8 remains **NO-GO** until the external paid cohort, field-safety window, case studies, public policy URLs, and official tagged artifact supply their required evidence.

### Release-blocker severity

Maintain a linked v1 blocker register using these definitions:

- **P0:** A release blocker involving site failure, data loss, incorrect high-confidence conclusions, privacy exposure, update authenticity, unbounded overhead/storage, or inability to install, upgrade, or remove safely.
- **P1:** A significant correctness, compatibility, accessibility, or workflow defect with a documented safe workaround or visible degradation path.
- **P2:** A non-blocking improvement that does not invalidate measurement, safety, or the core diagnosis workflow.

P0 issues cannot be waived. A P1 may be deferred only when its limitation is visible in-product, documented publicly, and approved in the release-blocker register.

### Public v1 go/no-go checklist

#### Measurement truth

- [ ] Every trace shows mode, capabilities, start stage, score version, and completeness.
- [ ] Missing capture cannot improve a score.
- [ ] Lifecycle phases are correct.
- [ ] Request populations are segmented.
- [ ] Self-observation is excluded.
- [ ] Advertised integrations have real end-to-end evidence.

#### Production safety

- [ ] Mode-specific overhead is within published budgets.
- [ ] Deep is one-shot or time-boxed.
- [ ] Storage quota and resumable retention work.
- [ ] Mu-plugin installation and removal prove ownership.
- [ ] Failed persistence and migrations degrade safely.
- [ ] Default capture is conservative.

#### Product usefulness

- [ ] A user can capture a trace without editing settings.
- [ ] Top findings expose impact, evidence, confidence, and action.
- [ ] Span details expose the supporting data safely.
- [ ] Before/after comparison works for compatible sample cohorts.
- [ ] Reports are redacted by default.
- [ ] Critical workflows pass browser and accessibility checks.

#### Release evidence

- [ ] Complete CI and compatibility matrices are green.
- [ ] No P0 issue remains open.
- [ ] Every deferred P1 issue has a documented safe degradation.
- [ ] Benchmark, compatibility, privacy, limitations, support, and refund pages are public.
- [ ] The release archive is built from a clean tag and checksummed.
- [ ] Three real cases demonstrate a measured, verified improvement.

---

## Agent-sized implementation backlog

The IDs below are intended for delegation. A task should not be considered complete merely because its code exists; its milestone acceptance criteria and validation gate still apply.

| ID | Task | Primary areas | Depends on | Size |
|---|---|---|---|---|
| V1-001 | Align product contract and terminology | README, readme, Feature Guide, settings copy | — | S |
| V1-002 | Add documentation claim-regression tests | release metadata tests | V1-001 | S |
| V1-003 | Establish WPCS, static-analysis, and coverage baselines | CI and quality configuration | V1-001 | M |
| V1-004 | Define provisional Community/Pro measurement boundary | product and architecture documentation | V1-001 | S |
| V1-005 | Run manually billed packaging and willingness-to-pay test | commercial brief and research evidence | V1-004 | M |
| V1-010 | Design trace schema v2 and capture report | Trace, new value objects | V1-001 | M |
| V1-011 | Add indexed storage migration and legacy hydration | Storage, uninstall, integration tests | V1-010 | L |
| V1-012 | Make span closing ID-aware | Collector and tests | V1-010 | M |
| V1-013 | Propagate dropped, trimmed, and auto-closed state | Collector, Trace, Storage | V1-010 | M |
| V1-014 | Return explicit persistence results | Storage, bootstrap, force-trace flow | V1-010 | M |
| V1-015 | Align key-aware metadata bounds | Span, Storage, tests | V1-010 | S |
| V1-016 | Add capture sessions and environment snapshots | new session/snapshot services, Trace, Storage | V1-010, V1-011 | L |
| V1-020 | Extract request classifier and route resolver | new source classes, bootstrap | V1-010 | L |
| V1-021 | Correct frontend lifecycle boundaries | bootstrap and mu-plugin | V1-020 | L |
| V1-022 | Correct degraded and request-type phase maps | bootstrap and mu-plugin | V1-020 | M |
| V1-023 | Add bounded capture policies and background sampling | Instrumentation, Settings, mu-plugin | V1-016, V1-020 | L |
| V1-024 | Add request-type and lifecycle integration fixtures | integration tests | V1-021, V1-022, V1-023 | L |
| V1-030 | Write database capture strategy ADR | architecture documentation | V1-010 | M |
| V1-031 | Implement selected DB strategy and capability reporting | DB instrumentors | V1-030 | L |
| V1-032 | Add stopped-collector guards and DB error metadata | DB, HTTP, GraphQL | V1-031 | M |
| V1-033 | Fix child-theme, drop-in, callback, and cache attribution | resolvers and instrumentation | V1-010 | M |
| V1-034 | Repair or de-scope WPGraphQL-specific instrumentation | GraphQL and smoke behavior | V1-010 | L |
| V1-035 | Add one-shot Deep override and lazy callback metadata | callback classes and admin flow | V1-023 | L |
| V1-036 | Exclude WP Flame self-observation | eligibility and bootstrap | V1-020 | M |
| V1-037 | Add DB, GraphQL, attribution, Deep, and degraded fixtures | integration and compatibility tests | V1-031, V1-032, V1-033, V1-034, V1-035, V1-036 | L |
| V1-040 | Make mu-plugin ownership atomic and verifiable | activation, deactivation, uninstall | V1-001 | L |
| V1-041 | Add storage health, quota, and resumable pruning | Storage, Settings, admin health | V1-011 | L |
| V1-042 | Add migration locking and multisite batching | Storage, bootstrap, uninstall, CLI | V1-011 | L |
| V1-043 | Implement privacy-safe URL, route, SQL, and metadata storage | Privacy, Redactor, Trace, Storage | V1-011 | M |
| V1-044 | Design and ingest versioned aggregate rollups | Storage and new rollup service | V1-011, V1-016 | L |
| V1-045 | Complete policy text, export, erasure, and consent UX | Privacy, Settings, docs | V1-043 | M |
| V1-046 | Move dashboard aggregation from trace JSON to rollups | Storage and admin dashboard | V1-044 | L |
| V1-047 | Add quota, pruning, migration, multisite, and rollup fixtures | integration and soak tests | V1-040, V1-041, V1-042, V1-043, V1-044, V1-045, V1-046 | L |
| V1-050 | Implement and persist Score v2 | Score, Trace, Storage | V1-016, V1-020, V1-031, V1-044 | L |
| V1-051 | Expand Insight with evidence, impact, and confidence | insight model and rules | V1-010 | M |
| V1-052 | Add v1 query, HTTP, cache, and incomplete rules | Rules | V1-031, V1-051 | M |
| V1-053 | Make budgets and trends cohort-aware | Storage, bootstrap, admin | V1-044, V1-046, V1-050 | M |
| V1-060 | Add onboarding, capability, and health workflow | admin, settings, new services | V1-016, V1-041, V1-045 | L |
| V1-061 | Add Standard and one-shot Deep capture choices | admin bar and instrumentation | V1-014, V1-035 | M |
| V1-062 | Add trace completeness UI and detail panel | admin PHP, JS, CSS | V1-013, V1-051 | L |
| V1-063 | Add Top Opportunities summary | admin and insights | V1-052, V1-062 | M |
| V1-064 | Add filters, keyboard support, and accessibility | flame graph JS/CSS/tests | V1-062 | M |
| V1-065 | Add compatible cohort comparison and redacted export | sessions, storage, admin, comparison service | V1-016, V1-050, V1-062 | L |
| V1-066 | Add browser workflow and accessibility gates | browser tests and CI | V1-060, V1-061, V1-062, V1-063, V1-064, V1-065 | L |
| V1-070 | Run cross-system deterministic accuracy evidence pass | integration tests and fixtures | V1-024, V1-037, V1-047, V1-066 | M |
| V1-071 | Build reproducible overhead harness | bin, fixtures, documentation | V1-070 | L |
| V1-072 | Complete host/plugin/degraded compatibility matrix | CI, smoke tests, docs | V1-071 | L |
| V1-073 | Enforce final Plugin Check, quality, coverage, E2E, a11y, audit, and package gates | CI and configuration | V1-003, V1-066, V1-070 | M |
| V1-074 | Add MariaDB, PHP 8.5, multisite, and package lanes | CI and integration tests | V1-073 | M |
| V1-075 | Complete core release-time application assurance review | capabilities, privacy, SQL, DOM, failure paths | V1-072, V1-073, V1-074 | M |
| V1-080 | Confirm direct single-edition RC architecture and provisional public edition contracts | packaging and architecture docs | V1-075 | M |
| V1-081 | Conditional after paid evidence: implement licensing, entitlement, and checkout integration | Pro/add-on infrastructure | V1-005, V1-080 | M |
| V1-082 | Conditional after paid evidence: implement authenticated updates and rollback handling | updater and release infrastructure | V1-005, V1-080, V1-081 | M |
| V1-083 | Repair versioning, changelog, dependency audit, license, and release metadata | release files and CI | V1-075 | M |
| V1-084 | Prove clean, immutable package and lifecycle, including updater recovery only when V1-082 applies | build scripts and release tests | V1-080, V1-083 | M |
| V1-085 | Conduct paid design-partner RC and close blockers | blocker register and release docs | V1-084 | L |

## Definition of done for every backlog item

- The behavioral contract is documented.
- Focused unit or integration tests are added.
- Existing trace data remains compatible or has a tested migration.
- Filter-provided and malformed values remain bounded.
- Hot-path changes receive proportional performance validation.
- Output is escaped and trace-controlled DOM content remains safe.
- Privacy-sensitive data remains opt-in.
- Changelog and relevant user documentation are updated.
- Relevant PHP, WordPress integration, JavaScript, browser, and build checks pass.
- No unrelated dirty file is modified, staged, or reverted.

## Recommended pull-request sequence

1. Product contract, provisional edition boundary, claim tests, and foundational quality baselines.
2. Trace schema v2, capability statuses, capture sessions, and environment snapshots.
3. Forward storage migration, metadata bounds, and persistence result.
4. Request classifier, lifecycle correction, capture policies, and their integration fixtures.
5. Database strategy, source/cache attribution, and stopped-collector guards.
6. Deep one-shot behavior, WPGraphQL decision, self-exclusion, and instrumentation fixtures.
7. Atomic mu-plugin ownership.
8. Storage quotas and resumable retention.
9. Migration locking and multisite batching.
10. Privacy storage, policy, export, erasure, and consent.
11. Aggregate rollup ingestion and dashboard migration.
12. Score v2 after lifecycle, capability, DB, session, and rollup contracts are stable.
13. Insight contract, v1 rules, and cohort-aware budgets/trends.
14. Guided current-page/workflow capture and capability UI.
15. Span detail, accessibility, and Top Opportunities.
16. Cohort before/after comparison and redacted export.
17. Cross-system accuracy, overhead, compatibility, and final quality gates.
18. Direct single-edition paid RC architecture; only after paid evidence, any selected Community/Pro, licensing, or authenticated-update architecture.
19. Release metadata, immutable packaging, support, and trust material.
20. Design-partner RC and final blocker closure.

## Deferred until after public v1

These ideas remain valuable but must not delay the release candidate:

- Site profiles and profile-specific scoring.
- WooCommerce-specific transaction packs.
- Plugin-specific expert rule packs.
- Automatic plugin, theme, and core change correlation.
- Scheduled baselines and advanced regression alerts.
- Multi-site and fleet dashboards.
- Optional cloud alerts and hosted share links.
- Real User Monitoring and Core Web Vitals.
- Synthetic monitoring.
- OpenTelemetry export.
- Optional true PHP profiler-agent integration.
- LLM-assisted trace explanation.
- PDF and white-label reports beyond the minimum v1 export.
- Anonymous ecosystem performance benchmarks.
- Automatic remediation or settings changes.
- Asset, autoloaded-option, opcode-cache, block-render, and transient audits.

## Status maintenance

When work starts:

1. Mark the relevant backlog item in the issue tracker.
2. Link the branch or pull request.
3. Record decisions that change this plan in an ADR or the roadmap.
4. Update acceptance checkboxes only when evidence exists.
5. Do not mark a milestone complete until its validation gate passes.
6. Keep the commercial brief synchronized when a promised v1 capability changes.

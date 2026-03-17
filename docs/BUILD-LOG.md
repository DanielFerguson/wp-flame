# WP Flame — Build Log

The story of building WP Flame from zero to a production-ready WordPress APM plugin in a single session.

---

## The Starting Point

A README.md with a solution design document outlining three phases: Simple, Loveable, Complete. The vision: a zero-dependency, self-hosted WordPress plugin that instruments the full request lifecycle and renders it as an interactive flame graph.

No code. No git repo. Just an idea.

---

## Phase 1: Simple — Core Instrumentation + Flame Graph

**Goal:** A working plugin that captures request timing data and displays it as an interactive flame graph.

### Architecture Decisions
- **Composer with PSR-4 autoloading** over WordPress-traditional `require_once` chains — modern PHP, better for TDD
- **SVG over Canvas** for the flame graph — simpler interactivity (native DOM events), sufficient for the span counts we'd see in Phase 1
- **mu-plugin pattern** (same as Query Monitor) for early loading — ensures we can time plugin initialization
- **Graceful degraded mode** — works without the mu-plugin, just captures fewer phases
- **Check at shutdown, not at start** — always collect in-memory, only decide whether to persist at the end

### What Was Built
- `Span` and `Trace` value objects with serialization
- `Collector` singleton with span stack, UUID generation, source attribution
- `Storage` with custom database table, CRUD, filtering, pagination, pruning
- `DB` class extending wpdb for query instrumentation (using `ReflectionClass::newInstanceWithoutConstructor`)
- mu-plugin for lifecycle phase capture
- Plugin bootstrap with activation/deactivation/uninstall hooks
- Server-rendered admin UI with trace list and flame graph view
- Interactive SVG flame graph with zoom, tooltips, and breadcrumbs
- 30 unit tests

### Key Discovery
- `wp_localize_script` converts all values to strings — breaks `.toFixed()` in JS. Fixed by switching to `wp_add_inline_script` with `wp_json_encode`.

---

## Phase 2: Loveable — Per-Callback Timing + Polish

**Goal:** Make it genuinely enjoyable to use with deeper data and better UI.

### P2.1: Per-Callback Instrumentation

The most important Phase 2 feature. Transforms the flame graph from "lifecycle phases + DB queries" to "individual plugin/theme callback timing."

**Key Discovery:** `WP_Hook` is `final` in WordPress 6.7+. The original spec (extend `WP_Hook` and override `apply_filters()`) was impossible. Redesigned to wrap callbacks in-place — replace each callback's `function` entry in the `callbacks` array with a `CallbackWrapper` invocable object. Simpler, more robust, zero WordPress internal code copied.

**Design decisions:**
- Minimum duration threshold (0.5ms default) to filter noise
- `end_span_filtered()` discards sub-threshold spans but preserves the tree (keeps parents that have retained children)
- Two wrapping passes at `plugins_loaded` and `init` (priority 1) to catch callbacks registered by themes
- Self-instrumentation prevention via source file detection
- `try/finally` in CallbackWrapper for exception safety

### P2.2: HTTP Instrumentation
Hooks `pre_http_request`/`http_response`. Added `add_span_meta()` to Collector for post-creation metadata (response status code).

### P2.4: Insights Panel
5 rule-based insights analyzing trace data. Later expanded to 7 rules with cache insights.

### P2.5: UI Polish
Time axis, color legend, "Full request" bar, improved stat cards, marketing-matched colors. Multiple iterations on tooltip positioning (viewport overflow, `position: fixed` with `clientX/clientY` to handle WordPress admin positioned ancestors).

### P2.6: Settings Page
Full WordPress Settings API integration. Admin bar "Trace This Page" button with CSP-safe JS. Sampling rate, audience control, data retention.

---

## Phase 3: Complete — Production-Grade

**Goal:** Ready for WordPress.org submission with aggregate analytics and operational features.

### P3.8: WordPress.org Readiness (done first)
Comprehensive security audit found 13 issues: unauthenticated force-trace via cookie, missing ABSPATH guards on all files, raw SQL patterns, missing `wp_unslash()`, silent nonce failures. All fixed. i18n applied to 62+ strings. readme.txt written.

### P3.1: Aggregate Dashboard
Stat cards with trend arrows, slowest pages/callbacks rankings, time breakdown bar, response time distribution histogram. The histogram bars are clickable — filter the trace table by duration range. Added sortable columns and method filter dropdown.

### P3.2: Object Cache Stats
Added `meta` array to Trace for extensible request-level metadata. Reads `$wp_object_cache->cache_hits/misses` at shutdown. Cache stat card on flame graph. Two new insight rules.

### P3.3: Cron Instrumentation
Two lines in the shutdown handler: detect `DOING_CRON`, bypass audience/sampling. Existing instrumentation already captures cron callbacks.

### P3.4 & P3.6: REST Filtering + WP-CLI
REST API type filter (one dropdown option). WP-CLI commands for `list`, `show`, `prune` (one new class).

### P3.5: Performance Budget Alerts
Threshold settings, transient-based violation counter, batched admin notice.

### Performance Score
5-factor weighted algorithm (Response Time 35%, External HTTP 20%, DB Query Count 15%, DB Time Ratio 15%, Slow Callbacks 15%). Stored in DB column. Displayed on flame graph (with factor breakdown), trace list (colored badges), and dashboard (average). 25 additional tests.

---

## By The Numbers

| Metric | Count |
|--------|-------|
| Commits | 82 |
| PHP source files | 13 |
| Test files | 12 |
| Unit tests | 115 |
| Assertions | 294 |
| Lines of PHP (src/) | 2,931 |
| Lines of tests | 2,086 |
| Lines added total | 10,834 |
| Asset files (JS/CSS) | 4 |
| Security issues found & fixed | 13 |
| i18n strings | 62+ |
| Insight rules | 7 |
| Score factors | 5 |
| Code reviews | 4 full-codebase reviews |
| Spec documents | 7 |

---

## Architecture

```
wp-flame/
├── wp-flame.php              # Bootstrap, activation, shutdown, hooks
├── uninstall.php             # Clean removal
├── src/
│   ├── Collector.php         # Singleton span stack engine
│   ├── Span.php              # Immutable span value object
│   ├── Trace.php             # Trace value object with meta
│   ├── Storage.php           # Database persistence layer
│   ├── DB.php                # wpdb extension for query timing
│   ├── Http.php              # HTTP request instrumentation
│   ├── CallbackWrapper.php   # Invocable callback timing wrapper
│   ├── CallbackResolver.php  # Callback name/source resolution
│   ├── Admin.php             # Admin UI (list, flame graph, dashboard)
│   ├── Settings.php          # Settings page
│   ├── Insights.php          # Performance analysis rules
│   ├── Score.php             # 5-factor scoring algorithm
│   └── CLI.php               # WP-CLI commands
├── mu-plugin/
│   └── wp-flame-early-hooks.php
├── assets/
│   ├── js/flame-graph.js     # SVG renderer
│   ├── js/admin.js           # List page JS
│   ├── js/admin-bar.js       # Trace button JS
│   └── css/admin.css         # All admin styles
└── tests/
    ├── bootstrap.php
    ├── Unit/ (12 test files)
    └── Integration/ (3 test files)
```

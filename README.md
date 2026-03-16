# WP Flame: WordPress APM Plugin — Solution Design & SLC Outline

## 1. Problem statement

WordPress site owners, developers, and agencies have no lightweight, self-hosted way to understand *where time is spent* during a page request. Query Monitor shows tabular data. New Relic/Datadog require SaaS subscriptions and PHP extensions. Neither gives you an interactive flame graph that shows: this plugin took 95ms during `plugins_loaded`, this theme template triggered 12 DB queries totalling 38ms, this external HTTP call blocked for 450ms.

The gap: a zero-dependency, self-contained WordPress plugin that instruments the full request lifecycle and renders it as an interactive flame graph in the admin panel.

---

## 2. Architecture overview

### 2.1 Core concept: span-based tracing

Every measurable unit of work is a **span** — a named block with a start time, duration, parent span, and metadata (source plugin/theme, type, detail). Spans nest to form a tree. The flame graph is a visual rendering of that tree.

```
Span {
  id: string
  parent_id: string|null
  name: string              // "WooCommerce init"
  type: enum                // core|plugin|theme|db|http|php
  source: string            // "woocommerce/woocommerce.php"
  start_ms: float           // relative to request start
  duration_ms: float
  meta: object              // flexible — query text, HTTP URL, memory delta, etc.
}
```

### 2.2 System layers

```
┌─────────────────────────────────────────────────────┐
│  Layer 1: Instrumentation (PHP)                     │
│  Hooks into WP lifecycle, captures spans            │
│  Must-use plugin (mu-plugin) for earliest loading   │
├─────────────────────────────────────────────────────┤
│  Layer 2: Collection & storage (PHP)                │
│  Aggregates span tree, stores as JSON               │
│  Transient-based with configurable retention        │
├─────────────────────────────────────────────────────┤
│  Layer 3: Admin UI (React/JS)                       │
│  Flame graph renderer, request browser, settings    │
│  Admin page under Tools menu                        │
└─────────────────────────────────────────────────────┘
```

### 2.3 Data flow

1. Request arrives → mu-plugin `wp-flame-collector.php` loads first (before all other plugins)
2. Collector registers instrumentation hooks across the WP lifecycle
3. Each hook callback wraps the original with timing — pushes spans onto an in-memory stack
4. At `shutdown` action (priority 9999), the complete span tree is serialised to JSON
5. Stored as a WordPress transient (or custom table for persistence)
6. Admin UI fetches stored traces via REST API endpoint and renders the flame graph

---

## 3. Instrumentation strategy

### 3.1 Lifecycle phase spans (automatic, always-on)

These are captured by hooking into WordPress's named lifecycle actions. Each becomes a top-level span:

| Phase | Hook(s) | What it captures |
|-------|---------|-----------------|
| Bootstrap | Request start → `muplugins_loaded` | wp-config, wp-settings, mu-plugins |
| Plugin load | `muplugins_loaded` → `plugins_loaded` | Each plugin's require_once + init |
| Theme setup | `plugins_loaded` → `after_setup_theme` | Theme functions.php, supports |
| Init | `after_setup_theme` → `wp` | CPT registration, rewrites, routing |
| Query | `wp` → `template_redirect` | Main WP_Query, redirects |
| Render | `template_redirect` → `shutdown` | Template, wp_head, wp_footer |

### 3.2 Plugin/theme callback instrumentation (the core trick)

**Approach: wrap `WP_Hook::apply_filters()` via a drop-in replacement.**

WordPress 4.7+ uses `WP_Hook` objects stored in the global `$wp_filter` array. Each `WP_Hook` has an `apply_filters()` method that iterates callbacks. We can:

1. Create a `WP_Flame_Hook` class extending `WP_Hook`
2. Override `apply_filters()` to wrap each callback with timing
3. After WordPress loads `WP_Hook`, replace the class instances in `$wp_filter`

```php
class WP_Flame_Hook extends WP_Hook {
    public function apply_filters( $value, $args ) {
        foreach ( $this->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $id => $the_ ) {
                $span = WP_Flame_Collector::start_span(
                    $this->get_callback_name( $the_['function'] ),
                    $this->get_callback_source( $the_['function'] )
                );
                // Call original callback
                $value = call_user_func_array( $the_['function'], $args_to_pass );
                WP_Flame_Collector::end_span( $span );
            }
        }
        return $value;
    }
}
```

**Source attribution** uses reflection on the callback:
- Closure → `ReflectionFunction::getFileName()`
- Method → `ReflectionMethod::getFileName()`
- Function → `ReflectionFunction::getFileName()`
- File path matched against `WP_PLUGIN_DIR`, `get_template_directory()`, `WPMU_PLUGIN_DIR`, `ABSPATH` to determine if it's plugin/theme/core/unknown

### 3.3 Database query instrumentation

WordPress's `$wpdb` already supports query logging via `SAVEQUERIES`, but it's expensive. Instead:

**Option A (lightweight):** Hook `query` filter (fires before each query) and use `$wpdb->timer_start` / `$wpdb->timer_stop` pattern. Attribution via `wp_debug_backtrace_summary()`.

**Option B (precise):** Create a `WP_Flame_DB` wrapper that proxies `$wpdb->query()`, adding a span for each query with the SQL text, execution time, rows affected, and caller.

Recommended: **Option A** for v1, with a setting to enable full query capture (Option B) for debugging sessions.

### 3.4 External HTTP instrumentation

Hook `pre_http_request` (start span) and `http_response` (end span). Capture:
- URL (domain only by default, full URL opt-in for privacy)
- HTTP method
- Response code
- Duration
- Calling plugin (from backtrace)

### 3.5 Template instrumentation

Hook `template_include` to capture which template file is loaded. For `get_template_part()`, hook `get_template_part_{$slug}` (available since WP 5.2). For `get_header()`, `get_footer()`, `get_sidebar()` — hook the corresponding actions.

### 3.6 Object cache instrumentation

If a persistent object cache is active (Redis, Memcached), wrap the `wp_cache_get` / `wp_cache_set` calls via a drop-in decorator. Track hit/miss ratio and time spent in cache operations. This is a v2 feature — skip for initial SLC.

---

## 4. Storage design

### 4.1 Trace storage

Each page request generates a **trace** — the complete span tree plus request metadata.

```php
$trace = [
    'id'         => wp_generate_uuid4(),
    'url'        => $_SERVER['REQUEST_URI'],
    'method'     => $_SERVER['REQUEST_METHOD'],
    'timestamp'  => gmdate('c'),
    'total_ms'   => $total_duration,
    'peak_memory'=> memory_get_peak_usage(true),
    'php_version'=> PHP_VERSION,
    'wp_version' => $wp_version,
    'query_count'=> $query_count,
    'spans'      => $spans,  // flat array, parent_id creates the tree
];
```

### 4.2 Storage backend options

| Backend | Pros | Cons | When to use |
|---------|------|------|-------------|
| Transients (default) | Zero config, works everywhere | Lost on cache flush, limited query ability | v1 default |
| Custom table `wp_flame_traces` | Queryable, persistent, prunable | Requires DB migration | v1 opt-in |
| File-based (JSON in uploads) | No DB overhead | File I/O, permissions | Edge case |

**v1 approach:** Custom table with auto-creation on activation. Store traces as JSON blob with indexed columns for `url`, `timestamp`, `total_ms` for filtering/sorting in the admin UI. Auto-prune traces older than N days (configurable, default 7).

### 4.3 Overhead budget

Target: <5ms overhead per request with instrumentation active. This means:
- No file I/O during the request (write to in-memory array, flush at shutdown)
- No external calls (no SaaS reporting)
- Minimal reflection (cache callback-to-source mappings in a static array)
- Sampling mode: only trace 1-in-N requests (configurable)
- Admin-only mode: only trace when a logged-in admin is browsing (default)

---

## 5. Admin UI design

### 5.1 Pages

**Trace browser** (`/wp-admin/tools.php?page=wp-flame`)
- List of recent traces: URL, method, total time, query count, timestamp
- Filterable by URL pattern, time range, min duration
- Click to open flame graph view

**Flame graph view** (`/wp-admin/tools.php?page=wp-flame&trace=<id>`)
- Interactive flame graph (as prototyped above)
- Summary stats bar: total time, DB time, HTTP time, plugin time, peak memory
- Span detail panel on click/hover
- Ability to compare two traces side-by-side (v2)

**Settings** (`/wp-admin/options-general.php?page=wp-flame-settings`)
- Enable/disable instrumentation
- Sampling rate (1 = every request, 10 = 1 in 10, etc.)
- Who can trigger traces: admins only, logged-in users, everyone
- Data retention (days)
- Enable full query text capture (off by default for privacy)
- Enable full HTTP URL capture (off by default)

### 5.2 REST API endpoints

```
GET  /wp-json/wp-flame/v1/traces          — list traces (paginated, filterable)
GET  /wp-json/wp-flame/v1/traces/<id>     — single trace with all spans
DELETE /wp-json/wp-flame/v1/traces/<id>   — delete a trace
GET  /wp-json/wp-flame/v1/summary         — aggregate stats (avg response time, slowest pages, etc.)
POST /wp-json/wp-flame/v1/settings        — update settings
```

All endpoints require `manage_options` capability.

### 5.3 Flame graph renderer

Client-side JavaScript (vanilla JS, no framework dependency for v1). The flame graph is an HTML canvas or SVG element where:
- X-axis = time (0 to total request duration)
- Y-axis = depth (nesting level of spans)
- Each span is a coloured rectangle, width proportional to duration
- Colour encodes type (core, plugin, theme, DB, HTTP, PHP)
- Hover shows tooltip with span details
- Click zooms into that span's subtree
- Breadcrumb bar for navigation back up

---

## 6. File structure

```
wp-flame/
├── wp-flame.php                    # Main plugin file (activation, admin menu, REST routes)
├── readme.txt                      # WordPress.org readme
├── includes/
│   ├── class-wp-flame-collector.php    # Span collection engine (singleton)
│   ├── class-wp-flame-hook.php         # WP_Hook wrapper for callback timing
│   ├── class-wp-flame-db.php           # Database query instrumentation
│   ├── class-wp-flame-http.php         # HTTP request instrumentation
│   ├── class-wp-flame-storage.php      # Trace persistence (custom table)
│   ├── class-wp-flame-rest.php         # REST API controller
│   └── class-wp-flame-admin.php        # Admin pages, settings, enqueue
├── mu-plugin/
│   └── wp-flame-early-hooks.php    # Copied to mu-plugins/ on activation
│                                   # Ensures instrumentation loads before all plugins
├── assets/
│   ├── js/
│   │   ├── flame-graph.js          # Flame graph renderer
│   │   └── admin.js                # Trace browser, settings UI
│   └── css/
│       └── admin.css               # Admin styles
└── uninstall.php                   # Clean removal (drop table, delete options, remove mu-plugin)
```

---

## 7. Key technical decisions

### 7.1 mu-plugin for early loading

The main plugin can't instrument plugin loading if it loads *after* other plugins. Solution: on activation, copy a small bootstrap file to `wp-content/mu-plugins/wp-flame-early-hooks.php`. This file:
- Starts the request timer (`microtime(true)`)
- Loads the collector class
- Registers the `WP_Hook` wrapper
- Gets removed on plugin deactivation

This is the same pattern Query Monitor uses. It's well-established and users understand the mu-plugin dependency.

### 7.2 WP_Hook replacement vs. monkey-patching

We **extend** `WP_Hook`, not monkey-patch it. After WordPress creates the original `WP_Hook` instances in `$wp_filter`, we iterate and replace them with `WP_Flame_Hook` instances that copy over the existing callbacks. This preserves all registered hooks while adding timing.

**Risk:** Other plugins that also extend `WP_Hook` (rare, but possible). Mitigation: check if the object is already a custom subclass, and if so, use a decorator pattern instead.

### 7.3 Callback source caching

`ReflectionFunction::getFileName()` is not free. The collector maintains a static `$source_cache` mapping callback ID → source file. Since callbacks are registered once and called many times, this amortises the reflection cost to near-zero.

### 7.4 Why not XHProf/Xdebug/Tideways?

These require PHP extensions that most shared hosting doesn't have. The plugin must work on any WordPress install with zero server-level changes. The trade-off is lower granularity — we instrument at the WordPress hook level, not individual PHP function calls. For 95% of performance debugging, this is actually *better* because it maps directly to the concepts site owners care about: which plugin, which hook, which query.

---

## 8. SLC (Simple, Loveable, Complete) specification

This section is designed to be handed directly to Claude Code as a development brief.

---

### PHASE 1: SIMPLE — Core instrumentation + basic flame graph

**Goal:** A working plugin that captures request timing data and displays it as an interactive flame graph in wp-admin. The absolute minimum that provides genuine value.

**Deliverables:**

#### P1.1 — Collector engine (`includes/class-wp-flame-collector.php`)
- Singleton pattern with `::instance()` accessor
- `start_span(name, type, source, meta)` → returns span ID
- `end_span(span_id)` → records duration
- `get_trace()` → returns complete trace array
- Span types: `core`, `plugin`, `theme`, `db`, `http`, `php`
- In-memory array storage during request (no I/O until shutdown)
- Automatic parent/child relationship via an internal span stack
- Request metadata: URL, method, timestamp, PHP version, WP version

#### P1.2 — Lifecycle phase instrumentation (`mu-plugin/wp-flame-early-hooks.php`)
- Request start timer at mu-plugin load time
- Phase spans for: bootstrap → `muplugins_loaded` → `plugins_loaded` → `after_setup_theme` → `init` → `wp` → `template_redirect` → `shutdown`
- Each phase is a top-level span

#### P1.3 — Plugin load timing
- Hook `option_active_plugins` to get the plugin list
- Wrap each plugin's `require_once` with timing spans
- Source attribution: extract plugin slug from file path

#### P1.4 — Database query instrumentation (`includes/class-wp-flame-db.php`)
- Hook the `query` filter on `$wpdb`
- Record: query duration, caller (via backtrace), source plugin/theme
- Store simplified query text (first 200 chars, no sensitive data by default)
- Aggregate: total query count, total query time

#### P1.5 — Storage (`includes/class-wp-flame-storage.php`)
- Custom table `{prefix}flame_traces` created on activation
- Columns: `id` (BIGINT AUTO_INCREMENT), `trace_id` (VARCHAR UUID), `url` (VARCHAR), `method` (VARCHAR), `total_ms` (FLOAT), `query_count` (INT), `peak_memory` (BIGINT), `created_at` (DATETIME), `trace_data` (LONGTEXT JSON)
- `save_trace($trace)` — insert
- `get_trace($trace_id)` — fetch single
- `list_traces($args)` — paginated list with filters
- `prune_old($days)` — delete traces older than N days
- Auto-prune via `wp_scheduled_event` daily

#### P1.6 — REST API (`includes/class-wp-flame-rest.php`)
- `GET /wp-json/wp-flame/v1/traces` — list (paginated)
- `GET /wp-json/wp-flame/v1/traces/{id}` — single trace with spans
- `DELETE /wp-json/wp-flame/v1/traces/{id}` — delete
- All endpoints require `manage_options` permission

#### P1.7 — Admin page with flame graph (`includes/class-wp-flame-admin.php` + `assets/`)
- Admin menu item under Tools: "WP Flame"
- Trace list view: table with URL, method, total time, query count, date
- Click trace → flame graph view
- Flame graph rendered in vanilla JS (canvas-based for performance)
  - X-axis: time, Y-axis: span depth
  - Colour coding by span type
  - Hover tooltip: span name, duration, source, percentage of total
  - Click to zoom into subtree, breadcrumbs to zoom out
- Summary stats bar above the flame graph

#### P1.8 — Plugin bootstrap (`wp-flame.php`)
- Standard plugin header
- Activation hook: create DB table, copy mu-plugin
- Deactivation hook: remove mu-plugin (keep data)
- Uninstall: drop table, delete options, remove mu-plugin
- Admin-only tracing by default (only traces requests from logged-in admins)
- Settings stored in `wp_options` with `wp_flame_` prefix

**Definition of done for Phase 1:**
- Install the plugin on a fresh WordPress site with WooCommerce and a couple of other plugins active
- Navigate to any frontend page as admin
- Open Tools → WP Flame, see the request listed
- Click it, see a flame graph showing: WP bootstrap, each plugin's init time, DB queries, template rendering
- Hover any span to see its detail, click to zoom

---

### PHASE 2: LOVEABLE — Polish, HTTP tracking, better attribution

**Goal:** Make it genuinely enjoyable to use. Better data, better UI, things that make people tweet about it.

#### P2.1 — WP_Hook wrapper (`includes/class-wp-flame-hook.php`)
- Extend `WP_Hook` to wrap individual callbacks with timing
- On `plugins_loaded`, iterate `$wp_filter` and replace `WP_Hook` instances with `WP_Flame_Hook`
- Per-callback spans with source attribution
- Static source cache to amortise reflection cost
- This gives per-callback granularity within each hook (e.g., "Yoast's `wpseo_head` callback took 22ms within the `wp_head` action")

#### P2.2 — External HTTP instrumentation (`includes/class-wp-flame-http.php`)
- Hook `pre_http_request` and `http_response`
- Capture: URL domain (full URL opt-in), method, response code, duration
- Source attribution via backtrace
- Flag slow/blocking external calls prominently in the flame graph (e.g., license checks, API calls)

#### P2.3 — Template part instrumentation
- Hook `get_template_part_{$slug}` (WP 5.2+)
- Hook `get_header`, `get_footer`, `get_sidebar` actions
- Span for each template part load with file path

#### P2.4 — Insights panel
- Below the flame graph, auto-generate actionable insights:
  - "WooCommerce's license check added 27ms of blocking HTTP time during init"
  - "Elementor loads 847KB of JSON from postmeta on every page load"
  - "18 stylesheets are enqueued — consider combining"
  - "47 DB queries detected — 12 are duplicates"
- Rule-based: define insight rules as simple callables that inspect the trace

#### P2.5 — UI improvements
- Keyboard navigation (arrow keys to move between spans, Enter to zoom)
- Search/filter within a trace (find all spans from a specific plugin)
- Comparison mode: select two traces, show side-by-side flame graphs with delta highlighting
- Export trace as JSON (for sharing with developers or support)
- Dark mode support in admin

#### P2.6 — Settings page
- Full settings UI (not just wp_options)
- Sampling rate slider
- Trace audience selector: admins only / logged-in / everyone
- Data retention control
- Per-feature toggles: DB queries, HTTP calls, full query text, full URLs
- "Trace this page" admin bar button for on-demand single-page tracing

---

### PHASE 3: COMPLETE — Production-grade, ecosystem-aware

**Goal:** Ready for the WordPress.org plugin directory. Handles edge cases, scales to high-traffic sites, provides aggregate insights over time.

#### P3.1 — Aggregate analytics dashboard
- Average page load time over time (chart)
- Slowest pages ranking
- Slowest plugins ranking (by total time across all traces)
- DB query trends
- Memory usage trends
- Compare this week vs last week

#### P3.2 — Object cache instrumentation
- Detect and instrument Redis/Memcached object cache drop-ins
- Track cache hit/miss ratio per request
- Measure time spent in cache operations

#### P3.3 — Cron job instrumentation
- Hook `wp_cron` and `action_scheduler` to trace background jobs
- Same flame graph treatment for cron as for HTTP requests

#### P3.4 — REST API & AJAX instrumentation
- Trace `admin-ajax.php` requests and REST API calls
- Attribute to originating plugin

#### P3.5 — Performance budget alerts
- Define thresholds: "alert if any page takes >500ms" or "alert if any plugin takes >100ms during init"
- Admin notification when thresholds are exceeded
- Email digest option (weekly)

#### P3.6 — Export & integration
- WP-CLI commands: `wp flame list`, `wp flame show <id>`, `wp flame prune`
- OpenTelemetry-compatible trace export (OTLP JSON) for users who want to feed data into Grafana/Jaeger
- Webhooks for threshold alerts

#### P3.7 — Multisite support
- Per-site tracing with network-level aggregate view
- Network admin dashboard

#### P3.8 — WordPress.org readiness
- Full readme.txt with screenshots, FAQ, changelog
- Internationalisation (i18n) for all admin strings
- Accessibility audit of admin UI
- Security review: capability checks, nonce verification, SQL preparation, output escaping
- Performance audit: verify <5ms overhead target under load
- PHPUnit test suite for collector, storage, and REST endpoints
- E2E tests with WordPress test framework

---

## 9. Risks and mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Overhead too high on busy sites | Site slowdown, user complaints | Sampling mode default, admin-only default, benchmark on every commit |
| WP_Hook replacement breaks other plugins | Fatal errors | Check for existing subclasses, fall back to decorator pattern, provide "safe mode" that skips WP_Hook replacement |
| mu-plugin confuses users | Support burden | Clear admin notice explaining why the mu-plugin exists, auto-removal on deactivation |
| Large trace data fills DB | Disk usage | Auto-prune, configurable retention, max traces cap |
| Query text captures sensitive data | Privacy/security | Default to first 200 chars, parameterised query detection, opt-in full capture |
| Plugin conflicts with Query Monitor | User confusion | Detect QM, document co-existence, don't fight over the same hooks |

---

## 10. Naming and positioning

**Plugin name:** WP Flame

**Tagline:** "See exactly where your WordPress request spends its time."

**Positioning:** The self-hosted, zero-dependency APM for WordPress. No SaaS subscription, no PHP extensions, no server config. Install → activate → see your flame graph.

**Key differentiators vs Query Monitor:** Visual flame graph (not tables), time-series data (not single-request), per-callback granularity, actionable insights.

**Key differentiators vs New Relic/Datadog:** Free, self-hosted, no PHP extension required, WordPress-native concepts (plugins, themes, hooks) not generic PHP functions.

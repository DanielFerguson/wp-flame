=== WP Flame ===
Contributors: chepstowe
Tags: performance, profiling, flame graph, APM, debugging
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See exactly where your WordPress request spends its time. Interactive flame graph APM.

== Description ==

WP Flame is a self-hosted, zero-dependency APM (Application Performance Monitoring) plugin for WordPress. It instruments the full request lifecycle and renders an interactive flame graph in your admin panel.

**What it shows you:**

* **Lifecycle phases** - Bootstrap, Plugin Load, Theme Setup, Init, Routing, Main Query, Render
* **Per-callback timing** - Which specific plugin/theme callbacks are slow within each hook
* **Database queries** - Every SQL query with duration, caller, and source attribution
* **External HTTP calls** - API calls, license checks, webhook sends with URL, status, and duration
* **Actionable insights** - Auto-generated recommendations like "WooCommerce license check is blocking page load"

**Key features:**

* Interactive SVG flame graph with click-to-zoom and hover tooltips
* Per-callback instrumentation showing individual plugin/theme function timing
* Color-coded spans: Core (grey), Plugins (purple), Theme (green), Database (red), HTTP (amber)
* Time axis with ms labels and "Full request" overview bar
* Insights panel with 5 automatic performance analysis rules
* Settings page with sampling rate, audience control, and data retention
* "Trace This Page" admin bar button for on-demand single-page tracing
* Trace list with URL filtering, duration filtering, and pagination
* Dashboard with aggregate stats, slowest pages, slowest callbacks, and response time histogram
* Performance budgets — admin notice when requests exceed configured ms/query thresholds
* WP-CLI commands: `wp flame list`, `wp flame stats`, `wp flame purge`
* Object cache hit/miss stats per trace (compatible with Redis, Memcached, and default WP cache)

**How it works:**

WP Flame uses a must-use plugin (mu-plugin) to start timing before any other plugin loads. It wraps WordPress hook callbacks with timing wrappers, intercepts database queries via a wpdb extension, and hooks into the HTTP API. At shutdown, the trace is saved to a custom database table. The admin UI renders the data as an interactive flame graph.

**Zero dependencies. No SaaS. No PHP extensions. Just install and activate.**

== Installation ==

1. Upload the `wp-flame` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. The plugin automatically installs a mu-plugin for early loading
4. Browse your site as an admin
5. Go to Tools > WP Flame to see your flame graphs

**Note:** The mu-plugin (`wp-flame-early-hooks.php`) is automatically copied to `wp-content/mu-plugins/` on activation. This enables the plugin to capture timing data before other plugins load. It is automatically removed on deactivation.

If the mu-plugin cannot be installed (e.g., on managed hosting with restricted mu-plugins), WP Flame runs in limited mode — plugin load timing is unavailable but all other features work normally.

== Frequently Asked Questions ==

= Does this slow down my site? =

WP Flame adds less than 1ms of overhead per request. Callbacks below a configurable threshold (default 0.5ms) are automatically discarded. You can also control sampling rate and which users trigger tracing.

= What is the mu-plugin and why is it needed? =

The mu-plugin (`wp-flame-early-hooks.php`) loads before all other plugins, allowing WP Flame to measure plugin initialization time. Without it, WP Flame still works but cannot capture Bootstrap and Plugin Load phase timing. The mu-plugin is automatically installed on activation and removed on deactivation.

= Can I use this on a production site? =

Yes, with the appropriate settings. Set the sampling rate to trace 1 in every 10 (or 100) requests, and limit tracing to admin users only. Use the data retention setting to automatically clean up old traces.

= Does it work with page caching? =

Cached pages that are served without executing PHP will not generate traces. This is expected — cached pages don't need profiling. WP Flame only traces requests that actually run through WordPress.

= How is this different from Query Monitor? =

Query Monitor shows tabular data for a single request. WP Flame shows an interactive flame graph with time-based visualization, per-callback granularity, and historical trace storage. They complement each other well.

= How is this different from New Relic or Datadog? =

Those require SaaS subscriptions and PHP extensions. WP Flame is free, self-hosted, requires no server configuration, and uses WordPress-native concepts (plugins, themes, hooks) instead of generic PHP function profiling.

== Screenshots ==

1. Flame graph view showing request lifecycle phases, per-callback timing, and database queries
2. Trace list view with URL filtering and duration highlighting
3. Insights panel with actionable performance recommendations
4. Settings page with sampling rate, audience control, and data retention

== Changelog ==

= 1.0.0 =
* Initial release (Phase 1)
* Lifecycle phase instrumentation (Bootstrap through Render)
* Per-callback timing via hook callback wrapping
* Database query instrumentation with source attribution
* External HTTP request instrumentation
* Interactive SVG flame graph with zoom and tooltips
* Time axis, color legend, and summary stats
* Insights panel with 5 automatic analysis rules
* Settings page with sampling, audience, and retention controls
* "Trace This Page" admin bar button
* mu-plugin for early loading with graceful degraded mode

= Phase 2 =
* Dashboard with aggregate stats cards (avg load time, trace count, slowest page, avg queries)
* Time Breakdown Bar showing Core / Plugin / Theme / DB / HTTP split across recent traces
* Slowest Pages and Slowest Callbacks ranking tables
* Response Time Distribution histogram with click-to-filter integration
* Trend indicator comparing current period vs previous period avg load time
* Object cache hit/miss/ratio stats per trace displayed on the flame graph view
* Performance budget thresholds (max ms, max queries) with admin-notice violations counter

= Phase 3 =
* WP-CLI commands: `wp flame list`, `wp flame stats`, `wp flame purge`
* Sortable trace list columns (Duration, Queries, Memory, Date)
* Request type filter tabs (All, Cron, AJAX, REST API)
* HTTP method filter dropdown
* Pagination for trace list
* Guard against division-by-zero in trend calculation when prev_avg_ms is zero
* Replace NOW() with UTC_TIMESTAMP() in SQL queries to match UTC storage
* Use wp_add_inline_script instead of wp_localize_script to preserve numeric types

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Flame.

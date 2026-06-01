=== WP Flame ===
Contributors: chepstowe
Tags: performance, profiling, flame graph, APM, debugging
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See exactly where your WordPress request spends its time. Interactive flame graph APM.

== Description ==

WP Flame is a self-hosted, zero-dependency APM (Application Performance Monitoring) plugin for WordPress. It instruments the full request lifecycle and renders an interactive flame graph in your admin panel.

**What it shows you:**

* **Lifecycle phases** - Bootstrap, Plugin Load, Theme Setup, Init, Routing, Main Query, Render
* **Per-callback timing** - Optional Deep mode shows which plugin/theme callbacks are slow within each hook
* **Database queries** - SQL fingerprints with duration, caller, and source attribution; full query text is opt-in
* **External HTTP calls** - API calls, license checks, webhook sends with host, method, status, and duration; full URLs are opt-in
* **Actionable insights** - Auto-generated recommendations like "WooCommerce license check is blocking page load"

**Key features:**

* Interactive SVG flame graph with click-to-zoom and hover tooltips
* Compatibility-focused instrumentation modes: Safe, Standard, and Deep
* Optional per-callback instrumentation showing individual plugin/theme function timing
* Color-coded spans: Core (grey), Plugins (purple), Theme (green), Database (red), HTTP (amber)
* Time axis with ms labels and "Full request" overview bar
* Insights panel with automatic performance analysis rules
* Settings page with sampling rate, audience control, and data retention
* "Trace This Page" admin bar button for on-demand single-page tracing
* Trace list with URL filtering, duration filtering, and pagination
* Dashboard with aggregate stats, slowest pages, slowest callbacks, and response time histogram
* Performance budgets — admin notice when requests exceed configured ms/query thresholds
* WP-CLI commands: `wp flame list`, `wp flame show`, `wp flame prune`
* Privacy-safe defaults: redacted request URLs, normalized SQL fingerprints, host-only HTTP metadata, and identity fields (user IDs, IPs, user agents) disabled by default
* Object cache hit/miss stats per trace (compatible with Redis, Memcached, and default WP cache)

**How it works:**

WP Flame uses a must-use plugin (mu-plugin) to start timing before other plugins load. Depending on the selected compatibility mode, it records lifecycle spans, hooks into the HTTP API, captures safe database spans, and can optionally wrap WordPress hook callbacks during focused debugging. At shutdown, the trace is saved to a custom database table. The admin UI renders the data as an interactive flame graph.

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

WP Flame is designed to keep overhead bounded, but the exact cost depends on instrumentation mode, plugin stack, and request complexity. Use Safe or Standard mode with sampling for production monitoring, and reserve Deep mode for focused debugging. Callbacks below a configurable threshold (default 0.5ms) are automatically discarded.

= What is the mu-plugin and why is it needed? =

The mu-plugin (`wp-flame-early-hooks.php`) loads before all other plugins, allowing WP Flame to measure plugin initialization time. Without it, WP Flame still works but cannot capture Bootstrap and Plugin Load phase timing. The mu-plugin is automatically installed on activation and removed on deactivation.

= Can I use this on a production site? =

Yes, with the appropriate settings. Set the sampling rate to trace 1 in every 10 (or 100) requests, and limit tracing to admin users only. Use the data retention setting to automatically clean up old traces. User IDs, IP addresses, user-agent strings, full SQL text, full HTTP URLs, and full GraphQL query text are disabled by default and can be enabled only when your site policy allows it. When IP tracking is enabled, WP Flame uses `REMOTE_ADDR` by default; trusted proxy headers can be enabled with the `wp_flame_client_ip_headers` filter.

= Does it work with page caching? =

Cached pages that are served without executing PHP will not generate traces. This is expected — cached pages don't need profiling. WP Flame only traces requests that actually run through WordPress.

= How is this different from Query Monitor? =

Query Monitor shows tabular data for a single request. WP Flame shows an interactive flame graph with time-based visualization, per-callback granularity, and historical trace storage. They complement each other well.

= How is this different from New Relic or Datadog? =

Those require SaaS subscriptions and PHP extensions. WP Flame is free, self-hosted, requires no server configuration, and uses WordPress-native concepts (plugins, themes, hooks) instead of generic PHP function profiling.

== Changelog ==

= 1.2.0 =
* Compatibility-focused Safe, Standard, and Deep instrumentation modes
* Privacy-safe defaults for request URLs, SQL fingerprints, HTTP metadata, GraphQL query text, user IDs, IP addresses, and user-agent strings
* Bounded span count and trace JSON size settings to protect large production requests
* WPGraphQL-aware instrumentation with DB/GraphQL mutual exclusion for safer plugin compatibility
* WP-CLI commands: `wp flame list`, `wp flame show`, `wp flame prune`
* Sortable, paginated trace list with request type and HTTP method filters
* Multisite activation, uninstall, cron cleanup, and new-site provisioning improvements
* Release packaging, CI, WordPress integration, and wp-env compatibility smoke-test hardening
* Admin flame graph, score, insight, dashboard, and stored-trace parsing hardening for malformed trace data
* UTC storage/query consistency and safer inline-script data handling

= 1.1.1 =
* Clear button next to Filter on the trace list
* Route comparison spacing improvements in the flame graph view

= 1.1.0 =
* Dashboard with aggregate stats cards (avg load time, trace count, slowest page, avg queries)
* Time Breakdown Bar showing Core / Plugin / Theme / DB / HTTP split across recent traces
* Slowest Pages and Slowest Callbacks ranking tables
* Response Time Distribution histogram with click-to-filter integration
* Trend indicator comparing current period vs previous period avg load time
* Object cache hit/miss/ratio stats per trace displayed on the flame graph view
* Performance budget thresholds (max ms, max queries) with admin-notice violations counter
* Request identity filters and dashboard rankings for users and IPs when privacy settings allow capture
* WPGraphQL instrumentation and GraphQL trace filtering

= 1.0.0 =
* Initial release
* Lifecycle phase instrumentation (Bootstrap through Render)
* Per-callback timing via hook callback wrapping
* Database query instrumentation with source attribution
* External HTTP request instrumentation
* Interactive SVG flame graph with zoom and tooltips
* Time axis, color legend, and summary stats
* Insights panel with automatic analysis rules
* Settings page with sampling, audience, and retention controls
* "Trace This Page" admin bar button
* mu-plugin for early loading with graceful degraded mode

== Upgrade Notice ==

= 1.2.0 =
Privacy defaults are stricter and instrumentation modes are compatibility-focused. Review settings after upgrading if you previously relied on full identity, SQL, HTTP URL, or GraphQL query capture.

= 1.0.0 =
Initial release of WP Flame.

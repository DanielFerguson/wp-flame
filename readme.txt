=== WP Flame ===
Contributors: chepstowe
Tags: performance, profiling, flame graph, APM, debugging
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.0-rc.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect observed WordPress request time with local, bounded flame-style traces.

== Description ==

WP Flame is a self-hosted performance monitoring plugin for WordPress. It records supported server-side spans from the earliest WP Flame capture point available and renders them as an interactive flame-style timeline in wp-admin.

**What it shows you:**

* **Observed lifecycle spans** - Intervals captured from the earliest WP Flame bootstrap point available
* **Supported callback timing** - Optional Deep mode shows observed WordPress hook callbacks that can be wrapped safely
* **Compatible database queries** - SQL fingerprints and durations when the active database layer can be instrumented
* **WordPress HTTP API calls** - Host, method, status, and duration for supported requests; full URLs are opt-in
* **Performance findings** - Rule-based guidance derived from the spans and capabilities that were actually observed

**Key features:**

* Interactive SVG flame graph with click-to-zoom and hover tooltips
* Compatibility-focused instrumentation modes: Safe, Standard, and Deep
* Optional Deep instrumentation for supported WordPress hook callbacks
* Color-coded spans: Core (grey), Plugins (purple), Theme (green), Database (red), HTTP (amber)
* Time axis with ms labels and an observed-request overview bar
* Insights panel with automatic performance analysis rules
* Settings page with sampling rate, audience control, and data retention
* "Trace This Page" admin bar button for on-demand single-page tracing
* Trace list with URL filtering, duration filtering, and pagination
* Dashboard with aggregate stats, slowest request paths, slowest callbacks, and observed-duration histogram
* Performance budgets — admin notice when requests exceed configured ms/query thresholds
* WP-CLI commands: `wp flame list`, `wp flame show`, `wp flame prune`
* Privacy-safe defaults: redacted request URLs, normalized SQL fingerprints, host-only HTTP metadata, and identity fields (user IDs, IPs, user agents) disabled by default
* Object-cache hit/miss stats when the active cache implementation exposes compatible public counters

**How it works:**

WP Flame uses a must-use plugin (mu-plugin) to start timing before other plugins load. Depending on the selected compatibility mode, it records lifecycle spans, hooks into the HTTP API, captures safe database spans, and can optionally wrap WordPress hook callbacks during focused debugging. At shutdown, the trace is saved to a custom database table. The admin UI renders the data as an interactive flame graph.

**No runtime Composer packages, required SaaS service, or PHP profiling extension.**

== Installation ==

1. Upload the `wp-flame` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. The plugin attempts to install a mu-plugin for earlier capture
4. Browse your site as an admin
5. Go to Tools > WP Flame to see your flame graphs

**Note:** WP Flame attempts to copy `wp-flame-early-hooks.php` to `wp-content/mu-plugins/` on activation so capture can begin before normal plugins load. It attempts to remove that managed copy on deactivation.

If the mu-plugin cannot be installed, WP Flame runs in degraded mode. Early intervals are unavailable, request-specific phase detail may be reduced, and the resulting trace must be treated as partial.

== Frequently Asked Questions ==

= Does this slow down my site? =

WP Flame uses sampling, span limits, and trace-size limits to bound work, but its exact cost depends on mode, plugin stack, and request complexity. Start with Safe mode and admin-only sampling, measure the effect in your environment, and reserve Deep mode for focused debugging. Discarding short callback spans reduces stored data but does not remove callback-wrapper invocation cost. Public v1 overhead budgets remain gated on published benchmarks.

= What is the mu-plugin and why is it needed? =

The mu-plugin (`wp-flame-early-hooks.php`) loads before normal plugins, allowing WP Flame to observe more of plugin initialization. Without it, later supported instrumentation can still run, but the trace begins later and is partial.

= Can I use this on a production site? =

WP Flame is designed for bounded capture, but production use must be validated on the target stack. Start with Safe mode, admin-only eligibility, and a conservative sample rate. Monitor storage and reserve Deep mode for short diagnostic sessions. User IDs, IP addresses, user-agent strings, full SQL text, full HTTP URLs, and full GraphQL query text are disabled by default and should be enabled only when the site's purpose, notice, retention, export, and erasure policies support them.

= Does it work with page caching? =

Cached responses served without executing PHP do not generate traces. WP Flame observes only requests that actually run through WordPress.

= How is this different from Query Monitor? =

Query Monitor is an excellent current-request developer debugger. WP Flame focuses on bounded local history, a flame-style request timeline, supported callback detail, and a guided diagnosis/verification direction. They can complement each other.

= How is this different from New Relic or Datadog? =

Server APM products can provide deeper PHP and infrastructure visibility and may require a hosting integration or PHP extension. WP Flame is self-hosted by default, requires no profiling extension, and presents supported data using WordPress-native concepts. It is not a replacement for complete server observability.

== Changelog ==

= 1.3.0-rc.1 =
* Adds versioned capture completeness, capability, request-population, environment, and scoring evidence.
* Adds guided Standard and one-shot Deep capture, evidence-rich findings, safe span inspection, and compatible before/after comparison.
* Adds bounded storage quotas, resumable retention/migrations, aggregate rollups, and safer multisite maintenance.
* Strengthens attribution, lifecycle handling, privacy redaction, mu-plugin ownership, failure isolation, release checks, and compatibility coverage.
* Adds a redacted-by-default WP-CLI support bundle and release-candidate operating documentation.

= 1.2.0 =
* Compatibility-focused Safe, Standard, and Deep instrumentation modes
* Privacy-safe defaults for request URLs, SQL fingerprints, HTTP metadata, GraphQL query text, user IDs, IP addresses, and user-agent strings
* Bounded span count and trace JSON size settings to protect large production requests
* WPGraphQL-aware instrumentation with DB/GraphQL mutual exclusion for safer plugin compatibility
* WP-CLI commands: `wp flame list`, `wp flame show`, `wp flame prune`
* Sortable, paginated trace list with request type and HTTP method filters
* Multisite activation, uninstall, cron cleanup, and new-site provisioning improvements
* Release packaging, CI, WordPress integration, and wp-env compatibility smoke-test hardening
* Admin flame graph, score, insight, dashboard, source attribution, and stored-trace parsing hardening for malformed trace data
* Live trace/span construction, force-trace cookies, and flame graph rendering hardened against pathological input sizes
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

= 1.3.0-rc.1 =
Release candidate for paid design-partner validation. Verify backups and compatibility on the target stack, begin in Safe mode, and review the new capture-health, privacy, quota, and retention controls before production use.

= 1.2.0 =
Privacy defaults are stricter and instrumentation modes are compatibility-focused. Review settings after upgrading if you previously relied on full identity, SQL, HTTP URL, or GraphQL query capture.

= 1.0.0 =
Initial release of WP Flame.

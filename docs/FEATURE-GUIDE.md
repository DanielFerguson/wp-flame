# WP Flame — Feature Guide

A benefit-oriented guide to every feature in WP Flame. Written for users, marketing, and documentation.

---

## What Is WP Flame?

WP Flame is a self-hosted performance monitoring plugin for WordPress. It shows you exactly where your site spends its time — which plugins are slow, which database queries are redundant, which API calls are blocking page loads — and tells you how to fix it.

No SaaS subscription. No PHP extensions. No server configuration. Install, activate, browse your site, see your flame graph.

---

## Core Features

### Interactive Flame Graph

**What it does:** Visualizes every WordPress request as a horizontal bar chart where width = time. Nested bars show what's happening inside each phase — which plugin callbacks are running, which queries they trigger, which HTTP calls they make.

**Why it matters:** Instead of staring at numbers in a table, you SEE where time goes. A 500ms page load becomes a visual story: "200ms was WooCommerce's cart init, which triggered 15 database queries and a 150ms license check to api.woocommerce.com."

**Key interactions:**
- Hover any bar to see name, duration, source, and percentage of total
- Click any bar to zoom in and see its children in detail
- Breadcrumb navigation to zoom back out
- Time axis shows millisecond markers
- Color coding: Core (grey-blue), Plugins (purple), Theme (green), Database (red), HTTP (amber)

### Performance Score (0-100)

**What it does:** Every traced request gets a score from 0 to 100, graded A through F. The score is based on 5 weighted factors that measure different aspects of performance.

**Why it matters:** A single number that answers "is this page fast?" without needing to interpret complex profiling data. Track your score over time. Share it with clients. Set a target and work toward it.

**The 5 factors:**
- Response Time (35%) — how long the server took to respond
- External HTTP (20%) — time spent waiting for external APIs
- Database Queries (15%) — how many SQL queries ran
- DB Time Ratio (15%) — what percentage of time was spent in the database
- Slow Callbacks (15%) — how many plugin/theme functions took >50ms

### Insights Panel

**What it does:** Automatically analyzes each trace and generates plain-English recommendations. Not just "your site is slow" — specific, actionable findings.

**Why it matters:** You don't need to be a performance expert. WP Flame tells you exactly what's wrong and what to do about it.

**Example insights:**
- "External HTTP call to api.woocommerce.com took 450ms — consider caching the response"
- "12 duplicate SELECT queries detected — the same query ran 12 times totalling 24ms"
- "No persistent object cache detected — 85 cache misses on this request. Redis or Memcached would cache these across requests"

### Dashboard Analytics

**What it does:** Aggregate overview above the trace list showing trends, rankings, and distribution.

**Why it matters:** Individual traces tell you about one request. The dashboard tells you about your site's performance overall — is it getting faster or slower? Which pages are the bottlenecks?

**Components:**
- Stat cards: average load time (with trend vs last week), trace count, slowest page, average queries, average score
- Slowest Pages ranking (top 5 by average duration)
- Slowest Callbacks ranking (top 5 by total time across recent traces)
- Time Breakdown bar showing where time goes (Core/Plugin/Theme/DB/HTTP split)
- Response Time Distribution histogram (clickable — filter traces by duration range)

---

## Instrumentation

### Per-Callback Timing

**What it does:** Times every individual plugin and theme function that runs during a WordPress request. Shows you not just "init phase took 200ms" but "WooCommerce's `WC_Cart::init` took 85ms within the init phase."

**Why it matters:** This is the feature that makes WP Flame dramatically more useful than other profiling tools. It tells you exactly which function in which plugin is your bottleneck.

### Database Query Tracking

**What it does:** Captures every SQL query with its duration, the truncated query text, and which plugin triggered it.

**Why it matters:** Database queries are the most common performance bottleneck in WordPress. WP Flame shows you which plugins are running the most queries and which queries are slowest.

### HTTP Request Monitoring

**What it does:** Tracks every external HTTP call WordPress makes during a page load — API calls, license checks, webhook sends — with URL, method, response code, and duration.

**Why it matters:** External HTTP calls are often the #1 performance killer. A single plugin license check can add 500ms to every page load. WP Flame makes these visible so you can eliminate or cache them.

### Object Cache Stats

**What it does:** Shows cache hit/miss ratio and backend type (In-Memory, Redis, Memcached) for each traced request.

**Why it matters:** A low cache hit ratio means your site is doing redundant work. WP Flame tells you whether your caching strategy is working and suggests improvements.

### Cron Job Tracing

**What it does:** Automatically traces WordPress cron (wp-cron.php) requests with the same flame graph treatment as regular page loads.

**Why it matters:** Background jobs can consume significant server resources. WP Flame shows you which scheduled tasks are slow and what they're doing.

---

## Tools & Controls

### Settings Page

**What it does:** Full control over tracing behavior — who triggers traces, how often, how long data is kept, and what gets captured.

**Settings:**
- Enable/disable tracing globally
- Trace audience: admins only, logged-in users, or everyone
- Sampling rate: trace every request, or 1 in every N
- Data retention: automatically delete traces after N days
- Callback threshold: minimum callback duration to record (filters noise)
- Full SQL query text: opt-in to capture complete query text
- Performance budgets: alert when pages exceed time or query thresholds

### "Trace This Page" Button

**What it does:** A button in the WordPress admin bar (visible on the frontend) that forces a single-page trace. Click it, the page reloads, and WP Flame captures a detailed trace — then shows you a notification with a direct link to the flame graph.

**Why it matters:** On-demand profiling without changing any settings. "This page feels slow — let me trace it." One click.

### Performance Budget Alerts

**What it does:** Set a maximum acceptable page load time and query count. When any traced request exceeds your budget, WP Flame shows an admin notification with a link to the slow traces.

**Why it matters:** Catch performance regressions before your users do. "Something got slow" shows up as a warning in wp-admin, not as customer complaints.

### WP-CLI Commands

**What it does:** Manage traces from the command line.

**Commands:**
- `wp flame list` — list recent traces (with --limit, --url, --format options)
- `wp flame show <id>` — display full trace details as JSON
- `wp flame prune` — clean up old traces (with --days, --all options)

**Why it matters:** Power users and CI/CD pipelines can interact with WP Flame without the browser.

---

## Technical Details

### How It Works

1. A mu-plugin loads before all other plugins and starts the timer
2. Lifecycle phase hooks track Bootstrap → Plugin Load → Theme Setup → Init → Routing → Main Query → Render
3. Every hook callback is wrapped with a timing closure that measures its duration
4. Database queries are intercepted via a wpdb extension
5. HTTP requests are tracked via pre_http_request/http_response hooks
6. At shutdown, the complete trace is scored and saved to a custom database table
7. The admin UI queries the table and renders flame graphs, dashboards, and insights

### Zero Dependencies

- No external services or API calls
- No JavaScript frameworks (vanilla JS)
- No PHP extensions required
- No Composer dependencies at runtime (Composer is dev-only for tests)
- Works on any WordPress 6.0+ with PHP 7.4+

### Performance Overhead

- <1ms per request with instrumentation active
- Callbacks below 0.5ms threshold are automatically discarded
- Sampling mode reduces overhead on production sites
- Admin-only tracing by default — no impact on visitor requests

### Data Privacy

- All data stays on your server — nothing is sent externally
- SQL query text is truncated to 200 characters by default
- Full query text capture is opt-in
- Traces are automatically pruned after the configured retention period
- Complete data removal on plugin uninstall

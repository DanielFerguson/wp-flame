# WP Flame — Future Roadmap

This document captures the vision for WP Flame beyond the current release. Features are ordered by priority — build them in this sequence.

---

## COMPLETED

### Performance Score ✅
0-100 score per trace with 5-factor weighted algorithm (Response Time 35%, External HTTP 20%, DB Query Count 15%, DB Time Ratio 15%, Slow Callbacks 15%). Stored in DB, displayed on flame graph (with factor breakdown), trace list (colored badges), and dashboard (average). Grade mapping: A/B/C/D/F with color coding. 115 tests.

---

## Priority 1: Site Profiles + Onboarding

WP Flame should recognize what kind of site it's running on and customize the entire experience accordingly.

### Auto-Detection
- `class_exists('WooCommerce')` → Store profile
- High ratio of `/wp-json/` traces → Headless API profile
- Elementor/Divi/Beaver Builder active → Page Builder profile
- BuddyPress/LearnDash/MemberPress → Membership profile
- None of the above → Blog/Content profile

### Onboarding Wizard
First visit to WP Flame shows a one-step setup:
- "What kind of site is this?" with auto-detected recommendation
- 5 options: Blog, Store, Headless API, Page Builder, Membership
- Takes 5 seconds, stored in `wp_options`, changeable in Settings
- Skippable for power users

### Per-Profile Customizations

**WooCommerce Store:**
- Dashboard highlights cart/checkout response time and payment gateway latency
- Score weights adjusted: HTTP factor increased (payment gateways), query count decreased (WooCommerce is inherently query-heavy)
- WooCommerce-specific insights: cart fragments, HPOS migration, transient checks
- New metric: cart fragment AJAX response time

**Headless API:**
- "Slowest Pages" becomes "Slowest Endpoints" grouped by REST route
- Response payload size tracking
- Cache header analysis for API responses
- Score weights: response time 50%, callbacks dropped
- Insights: use `_fields` parameter, pagination, conditional requests

**Page Builder:**
- CSS generation time surfaced in dashboard
- Builder-specific insights: external CSS mode, lazy loading, dynamic CSS caching
- Score weighted heavier on response time

**Blog/Content:**
- Simplest dashboard — response time, queries, cache
- Page caching detection emphasized
- Insight: "No page cache detected — this is your #1 opportunity"

**Membership/Community:**
- Focus on logged-in user performance (cache-unfriendly by nature)
- Session/cookie overhead tracking
- Insights around object caching for personalized content

---

## Priority 2: Plugin-Specific Knowledge Base

Not just "WooCommerce is slow" but "Go to WooCommerce > Settings > Advanced > disable License Check transient. This will save 60ms per page load."

- Curated knowledge base of known performance fixes for the top 50 WordPress plugins
- Pattern matching: detect specific plugin + specific slow callback = specific fix instruction
- Step-by-step fix instructions with settings paths, code snippets, and plugin links
- Community-contributed fixes (GitHub-hosted knowledge base that anyone can PR)
- The Insights panel becomes genuinely actionable — users can fix problems without hiring a developer

### "Fix This" Buttons
Next to each insight, a direct action:
- **Settings-based fixes:** link opens the exact plugin settings page at the right section
- **Code-based fixes:** button adds a `wp_dequeue_script` or filter via a mu-plugin snippet
- **Plugin recommendations:** links to install pages for caching plugins, optimization plugins

---

## Priority 3: N+1 Query Detection

Group identical DB query patterns (normalize parameters) and flag repetition.

- "SELECT * FROM wp_postmeta WHERE post_id = %d ran 47 times"
- Identify the calling code via backtrace source attribution
- Recommend batched alternatives: "Use a single WHERE post_id IN(...) query"
- Detect common WordPress N+1 patterns: `get_post_meta()` in loops, `WP_Query` without `update_post_meta_cache`
- Link to the specific span in the flame graph for each occurrence
- This saves developers HOURS of debugging

---

## Priority 4: Performance Coaching

Beyond profiling — WP Flame becomes a coach that guides users through progressive improvement.

### Contextual Guidance
- **After install:** "We'll start collecting data as you browse. Come back in a few minutes."
- **After 10 traces:** "Here's your baseline: avg 340ms, 45 queries, score 72. Your biggest opportunity is [specific thing]."
- **After a plugin update:** "Elementor updated from 3.19 to 3.20. We're monitoring for performance changes."
- **Weekly summary:** Dashboard card: "Score 78 (+3), 0 budget violations, fastest improvement: /cart/ (1200ms → 400ms)"

### Performance Changelog
Automatic timeline of performance-relevant events:
- "Mar 17 — Plugin updated: Elementor 3.19 → 3.20"
- "Mar 17 — Avg response time increased 40% ← correlated"
- "Mar 18 — WP Rocket activated"
- "Mar 18 — Avg response time decreased 60% ← correlated"

Detect plugin activations/deactivations/updates from trace data and correlate with performance shifts automatically.

---

## Priority 5: Performance Testing Mode

Structured before/after workflow for proving optimization impact.

1. Click **"Start Test"** → WP Flame records a baseline (next 10 requests averaged)
2. Make your change (update plugin, change setting, deploy code)
3. Click **"End Test"** → records 10 more requests
4. Automatic comparison: **"After your change: -120ms response time, -8 queries, score 72 → 77"**

- Generates a shareable before/after report
- Agencies use this to prove ROI to clients
- Stored as a "test" entity linking two sets of traces

---

## Priority 6: LLM Trace Analysis ("Explain This Trace")

One-click AI-powered natural language analysis of any trace.

- "Explain This Trace" button on the flame graph view
- Sends a structured trace summary (span names, durations, types — NOT raw SQL or user data) to an LLM API
- Returns natural-language analysis with specific, actionable recommendations
- Bring-your-own-API-key model: users add their OpenAI/Anthropic key in WP Flame settings
- Trace data stays on their server — only a structured summary goes to the API
- Prompt engineering is our value-add — we craft the context so the AI gives WordPress-specific advice
- Example output:
  > "This WooCommerce product page is slow for three reasons:
  > 1. The Stripe gateway is making a synchronous API call during page render. Fix: disable 'Preload payment methods' in WooCommerce > Payments > Stripe.
  > 2. Related products query is scanning the full catalog. Fix: set related products manually or use a caching plugin.
  > 3. Elementor is regenerating CSS on every load. Fix: Go to Elementor > Tools > Regenerate CSS & Data."

---

## Priority 7: Anonymous Plugin Performance Database

Opt-in telemetry that aggregates anonymous performance data across sites to create the first public WordPress plugin performance benchmark.

- Opt-in only — never collect data without explicit user consent
- Anonymous: no URLs, no query text, no user data. Just: plugin slug + average ms contribution + active install context
- Dashboard shows: "WooCommerce adds an average of 85ms across 12,000 sites. Yoast: 23ms. Elementor: 134ms."
- Users can check before installing a plugin: "This plugin adds ~200ms on average"
- Plugin authors get a performance dashboard showing their plugin's impact across the ecosystem
- Requires a lightweight SaaS backend to collect and serve aggregate data
- Competitor comparison: "Your WooCommerce checkout: 800ms. Average: 1200ms. You're faster than 73% of WooCommerce sites."

---

## Future Ideas (Unordered)

### Headless WordPress Features
- **REST API Endpoint Dashboard** — Group traces by REST route, rank by avg response time, show request counts per endpoint
- **Response Payload Size Tracking** — Measure JSON response body size, flag oversized payloads, recommend `_fields` parameter
- **Cache Header Analysis** — Check for Cache-Control, ETag, Last-Modified on API responses. Flag missing headers.
- **WPGraphQL Instrumentation** — Instrument GraphQL resolvers, show slow fields, resolver-level query attribution
- **API Consumer Tracking** — Track request volume by user agent, API key, or origin header
- **Multi-Request Correlation** — Group related API calls by correlation ID header into a "page render" view

### Agency Features
- **Client Performance Reports (PDF)** — One-click branded PDF audit: score, top issues, recommendations, before/after. Agencies charge $500-2000 for these; we make it a button.
- **Before/After Proof** — Visual comparison report after optimization work. Shareable link for proving ROI to clients.
- **Multi-site performance comparison** — Network-level dashboard comparing performance across client sites.

### Advanced Monitoring
- **Frontend Core Web Vitals** — Opt-in JS snippet reporting LCP, FID, CLS back to WP Flame. Correlate server-side traces with real-world user experience metrics that Google uses for rankings.
- **Synthetic Monitoring** — Automated page loads every 5 minutes via wp-cron. Detect regressions within minutes, not days.
- **"What Changed?" Regression Pinpointing** — When a trace is unusually slow, auto-diff against baseline. Detect plugin version changes from file paths and correlate with performance shifts.
- **Error Correlation** — Track PHP errors/warnings alongside performance data. "This page threw 3 notices and took 2x longer than usual."

### Data Points to Track
- **Autoloaded options audit** — WordPress loads ALL autoloaded options on every request. Identify plugins storing megabytes as autoloaded.
- **Asset audit** — Count enqueued CSS/JS, identify render-blocking scripts, detect plugins loading assets on pages where they're unused.
- **Memory deltas** — Memory usage change per lifecycle phase, not just peak.
- **Block editor profiling** — Profile Gutenberg block rendering. Which blocks are slow? How many queries per block instance?
- **Transient usage** — Which transients are being set/read, are they effective?
- **PHP opcode cache status** — Is opcache enabled? What's the hit rate?

### Engagement
- **Performance achievements** — Badges for optimization milestones. "You got your homepage under 200ms!"
- **Weekly email digest** — "Score 78 (+3), 0 budget violations, fastest: /about (45ms), slowest: /cart (890ms)"
- **Slack/Discord/email alerts** — "Your checkout just took 3.2s. [View trace]"

### Developer Tools
- **Flame graph annotations** — Click any span and add a note. "This is expected — payment gateway check."
- **Trace comparison mode** — Side-by-side diff of two traces, aligned by hook name.
- **Share traces** — Generate a shareable link. Send to a developer or plugin author as proof.
- **Export to OpenTelemetry** — OTLP JSON export for users who want to pipe data into Grafana/Jaeger.

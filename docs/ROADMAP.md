# WP Flame — Future Roadmap

This document captures the vision for WP Flame beyond the current release. Features are ordered by priority — build them in this sequence.

---

## Priority 1: Performance Score

A simple 0-100 number like Lighthouse. "Your site scores 72. Here are the 3 things holding you back."

- Score algorithm weighting: page load time, query count, cache hit ratio, HTTP call count, memory usage
- Score displayed on the dashboard, per-trace, and in the admin bar
- Breakdown showing which factors are dragging the score down
- Historical score tracking (score over time chart)
- Users obsess over scores — this is the single most viral, most shareable metric we can build

---

## Priority 2: Plugin-Specific Knowledge Base

Not just "WooCommerce is slow" but "Go to WooCommerce > Settings > Advanced > disable License Check transient. This will save 60ms per page load."

- Curated knowledge base of known performance fixes for the top 50 WordPress plugins
- Pattern matching: detect specific plugin + specific slow callback = specific fix instruction
- Step-by-step fix instructions with settings paths, code snippets, and plugin links
- Community-contributed fixes (GitHub-hosted knowledge base that anyone can PR)
- The Insights panel becomes genuinely actionable — users can fix problems without hiring a developer

---

## Priority 3: N+1 Query Detection

Group identical DB query patterns (normalize parameters) and flag repetition.

- "SELECT * FROM wp_postmeta WHERE post_id = %d ran 47 times"
- Identify the calling code via backtrace source attribution
- Recommend batched alternatives: "Use a single WHERE post_id IN(...) query"
- Detect common WordPress N+1 patterns: `get_post_meta()` in loops, `WP_Query` without `update_post_meta_cache`
- Link to the specific span in the flame graph for each occurrence
- This saves developers HOURS of debugging — it's the kind of insight that makes the plugin indispensable

---

## Priority 4: LLM Trace Analysis ("Explain This Trace")

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

## Priority 5: Anonymous Plugin Performance Database

Opt-in telemetry that aggregates anonymous performance data across sites to create the first public WordPress plugin performance benchmark.

- Opt-in only — never collect data without explicit user consent
- Anonymous: no URLs, no query text, no user data. Just: plugin slug + average ms contribution + active install context
- Dashboard shows: "WooCommerce adds an average of 85ms across 12,000 sites. Yoast: 23ms. Elementor: 134ms."
- Users can check before installing a plugin: "This plugin adds ~200ms on average"
- Plugin authors get a performance dashboard showing their plugin's impact across the ecosystem
- Requires a lightweight SaaS backend to collect and serve aggregate data
- This changes the WordPress ecosystem — plugin authors would care about performance because the data is public

---

## Future Ideas (Unordered)

### Agency Features
- **Client Performance Reports (PDF)** — One-click branded PDF audit: score, top issues, recommendations, before/after. Agencies charge $500-2000 for these; we make it a button.
- **Before/After Proof** — Visual comparison after optimization work. Shareable link for proving ROI to clients.
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
- **REST API endpoint profiling** — For headless WordPress, profile individual REST endpoints.
- **Export to OpenTelemetry** — OTLP JSON export for users who want to pipe data into Grafana/Jaeger.

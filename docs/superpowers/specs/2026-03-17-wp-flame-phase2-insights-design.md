# WP Flame Phase 2.4 Design Spec: Insights Panel

## Overview

Auto-generate actionable performance insights from trace data and display them below the flame graph. Rules inspect the Trace's spans and produce human-readable recommendations.

## Approach

Server-side PHP analysis. A static `Insights` class runs rule methods against a Trace object and returns an array of insight items. The Admin class renders them as styled HTML below the flame graph.

## Component Designs

### Insights Class (`src/Insights.php`)

**Class:** `WPFlame\Insights` — static utility class.

**`static analyze(Trace $trace): array`**

Runs all rule methods and returns a flat array of insights. Each insight:

```php
[
    'severity' => 'warning' | 'info',
    'title'    => string,
    'detail'   => string,
]
```

**Rules (each is a private static method returning an array of insights):**

1. **`slow_http_requests(Trace)`** — any TYPE_HTTP span > 100ms. Severity: warning. Title: "External HTTP call to {host} took {duration}ms". Detail: "URL: {url}, Method: {method}, Status: {status}. Consider caching the response or deferring to a background task."

2. **`duplicate_db_queries(Trace)`** — group TYPE_DB spans by query text (meta['query']), flag groups with count > 1. Severity: info if 2-3 duplicates, warning if 4+. Title: "{count} duplicate {query_type} queries detected". Detail: "The query '{truncated_query}' ran {count} times totalling {total_ms}ms. Consider caching with wp_cache or a transient."

3. **`high_query_count(Trace)`** — if total TYPE_DB spans > 50. Severity: warning if > 100, info if > 50. Title: "{count} database queries on this page". Detail: "Consider enabling object caching or reducing queries."

4. **`slow_callbacks(Trace)`** — any callback span (has 'hook' in meta) > 50ms. Severity: warning. Title: "{callback_name} took {duration}ms on the '{hook}' hook". Detail: "Source: {source}. This callback is a performance bottleneck."

5. **`http_during_early_phases(Trace)`** — any TYPE_HTTP span whose parent chain includes a lifecycle phase span named "Init", "Plugin Load", or "Theme Setup". Severity: warning. Title: "HTTP request during {phase} blocks page load". Detail: "{host} called during {phase} — consider deferring to a later hook or using a transient."

### Admin Changes (`src/Admin.php`)

In `render_flame_graph_view()`, after the flame graph container, add:

```php
$insights = Insights::analyze($trace);
if (!empty($insights)) {
    echo '<div class="wp-flame-insights">';
    echo '<h3>Insights</h3>';
    foreach ($insights as $insight) {
        $class = $insight['severity'] === 'warning' ? 'wp-flame-insight-warning' : 'wp-flame-insight-info';
        echo '<div class="wp-flame-insight ' . $class . '">';
        echo '<strong>' . esc_html($insight['title']) . '</strong>';
        echo '<p>' . esc_html($insight['detail']) . '</p>';
        echo '</div>';
    }
    echo '</div>';
}
```

### CSS Changes (`assets/css/admin.css`)

```css
.wp-flame-insights { margin: 16px 0; }
.wp-flame-insight { padding: 12px 16px; margin: 8px 0; border-radius: 4px; border-left: 4px solid; }
.wp-flame-insight-warning { background: #fff8e1; border-color: #f4a742; }
.wp-flame-insight-info { background: #e8f4fd; border-color: #4285f4; }
.wp-flame-insight strong { display: block; margin-bottom: 4px; }
.wp-flame-insight p { margin: 0; color: #646970; }
```

## File Changes

- Create: `src/Insights.php`
- Create: `tests/Unit/InsightsTest.php`
- Modify: `src/Admin.php` — render insights below flame graph
- Modify: `assets/css/admin.css` — insight styles

## Testing

Unit tests for each rule using constructed Trace/Span objects:
- Trace with slow HTTP span → produces warning
- Trace with duplicate queries → produces info/warning
- Trace with >50 queries → produces info
- Trace with slow callback → produces warning
- Trace with HTTP during Init phase → produces warning
- Trace with no issues → produces empty array

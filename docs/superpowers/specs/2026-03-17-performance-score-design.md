# WP Flame: Performance Score Design Spec

## Overview

Add a 0-100 performance score to every trace, computed from 5 weighted factors. Displayed on the flame graph view (with factor breakdown), trace list (as a column), and dashboard (as an average). Scores are computed at save time and stored in the database for efficient querying.

## Scoring Algorithm

### Factors

| Factor | Weight | 100 (Excellent) | 0 (Poor) | Data Source |
|--------|--------|-----------------|----------|-------------|
| Response Time | 35% | ≤100ms | ≥3000ms | `$trace->total_ms` |
| External HTTP | 20% | 0ms total | ≥2000ms total | Sum of `duration_ms` for spans with `type === 'http'` |
| DB Query Count | 15% | ≤15 | ≥200 | `$trace->query_count` |
| DB Time Ratio | 15% | ≤10% of total | ≥60% of total | `$trace->total_query_ms / $trace->total_ms`. Auto 100 if `total_ms < 100` |
| Slow Callbacks | 15% | 0 above 50ms | ≥10 above 50ms | Count of spans with `meta['hook']` AND `duration_ms > 50` |

### Interpolation

Linear interpolation between thresholds, clamped to 0-100:

```php
function interpolate(float $value, float $best, float $worst): int
{
    if ($value <= $best) return 100;
    if ($value >= $worst) return 0;
    return (int) round(100 * (1 - ($value - $best) / ($worst - $best)));
}
```

### Overall Score

Weighted average of the 5 sub-scores, rounded to nearest integer:

```php
$score = (int) round(
    $factors['response_time']['score'] * 0.35 +
    $factors['http_time']['score'] * 0.20 +
    $factors['query_count']['score'] * 0.15 +
    $factors['db_ratio']['score'] * 0.15 +
    $factors['slow_callbacks']['score'] * 0.15
);
```

### Grade Mapping

- 90-100: A (green `#22c55e`)
- 80-89: B (light green `#84cc16`)
- 70-79: C (yellow `#eab308`)
- 60-69: D (orange `#f97316`)
- 0-59: F (red `#ef4444`)

## Component Designs

### Score Class (`src/Score.php`)

**Class:** `WPFlame\Score` — static utility class.

**`static calculate(Trace $trace): array`**

Returns:
```php
[
    'score'   => 82,
    'grade'   => 'B',
    'color'   => '#84cc16',
    'factors' => [
        [
            'key'    => 'response_time',
            'label'  => 'Response Time',
            'score'  => 75,
            'weight' => 35,
            'value'  => '234ms',
        ],
        // ... 4 more factors
    ],
]
```

Factor extraction from Trace:
- **Response time:** `$trace->total_ms`
- **HTTP time:** iterate `$trace->spans`, sum `duration_ms` where `$span->type === Span::TYPE_HTTP`
- **Query count:** `$trace->query_count`
- **DB time ratio:** `$trace->total_query_ms / $trace->total_ms` (if `total_ms >= 100`, else auto 100)
- **Slow callbacks:** iterate `$trace->spans`, count where `isset($span->meta['hook']) && $span->duration_ms > 50`

**`static grade(int $score): array`** — returns `['grade' => 'B', 'color' => '#84cc16']`

**`static calculate_from_basic(float $total_ms, int $query_count): int`** — simplified score from just response time (35%) and query count (15%), with other factors scored at 100. Used as a fallback for traces without span data. NOT used in normal flow — only for edge cases.

### Storage Changes (`src/Storage.php`)

**Schema:** Add `score` column to `create_table()`:

```sql
score tinyint unsigned DEFAULT NULL,
```

`dbDelta()` handles adding the column to existing tables automatically.

**`save_trace(Trace $trace, int $score = null): void`** — updated to accept and store score. The score column is included in the insert.

**`get_avg_score(int $days = 7): ?float`** — new method:

```php
public function get_avg_score(int $days = 7): ?float
{
    $result = $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT AVG(score) FROM `{$this->table}` WHERE score IS NOT NULL AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
        $days
    ));
    return $result !== null ? round((float) $result, 1) : null;
}
```

**`list_traces()`** — updated SELECT to include `score` column.

### Shutdown Changes (`wp-flame.php`)

After building the Trace (step 4), compute the score:

```php
$trace = $collector->get_trace($request_meta);
$score_result = \WPFlame\Score::calculate($trace);
```

Pass score to `save_trace()`:

```php
$storage->save_trace($trace, $score_result['score']);
```

### Admin UI Changes (`src/Admin.php`)

**Flame graph view** — Score stat card:

A stat card (same style as Total Time, DB Queries, etc.) with colored left border matching the grade:

```php
echo '<div class="wp-flame-stat wp-flame-score-card" style="border-left:4px solid ' . esc_attr($score['color']) . '">';
echo '<span class="wp-flame-stat-label">SCORE</span>';
echo '<span class="wp-flame-stat-value">' . esc_html($score['score']) . '<small>' . esc_html($score['grade']) . '</small></span>';
echo '</div>';
```

Below the stats bar, a "Score Breakdown" section:

```
Score Breakdown
Response Time     234ms    ████████░░  75/100  (35%)
External HTTP     0ms      ██████████  100/100 (20%)
DB Queries        31       ████████░░  87/100  (15%)
DB Time Ratio     12%      █████████░  96/100  (15%)
Slow Callbacks    2        ████████░░  80/100  (15%)
```

Each factor is a row with: label, value, progress bar, sub-score, weight.

**Trace list** — Score column:

Add "Score" as the first column after URL. Display the numeric score with a colored background badge:

```php
$grade = \WPFlame\Score::grade((int) $row['score']);
echo '<td><span class="wp-flame-score-badge" style="background:' . esc_attr($grade['color']) . '">' . esc_html($row['score']) . '</span></td>';
```

For NULL scores (old traces): show "—".

**Dashboard** — Average score stat card:

Add an "AVG SCORE" stat card using `$this->storage->get_avg_score(7)`. Same styling as other dashboard stat cards. Shows the number with grade color.

### CSS Additions (`assets/css/admin.css`)

```css
/* Score badge in trace list */
.wp-flame-score-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    color: #fff;
    font-weight: 600;
    font-size: 12px;
}

/* Score breakdown */
.wp-flame-score-breakdown {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    margin: 12px 0;
}

.wp-flame-score-breakdown h3 {
    margin: 0 0 12px;
    font-size: 13px;
    text-transform: uppercase;
    color: #646970;
    letter-spacing: 0.5px;
}

.wp-flame-score-factor {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid #f0f0f0;
}

.wp-flame-score-factor:last-child {
    border-bottom: none;
}

.wp-flame-score-factor-label {
    width: 120px;
    font-size: 13px;
    flex-shrink: 0;
}

.wp-flame-score-factor-value {
    width: 60px;
    font-size: 12px;
    color: #646970;
    text-align: right;
    flex-shrink: 0;
}

.wp-flame-score-factor-bar {
    flex: 1;
    height: 8px;
    background: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
}

.wp-flame-score-factor-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s;
}

.wp-flame-score-factor-score {
    width: 50px;
    font-size: 12px;
    color: #646970;
    flex-shrink: 0;
}
```

## Testing

**Unit tests (`tests/Unit/ScoreTest.php`):**
- Fast trace (50ms, 10 queries, no HTTP, no slow callbacks) → score ~95, grade A
- Slow trace (2000ms, 150 queries, 500ms HTTP, 5 slow callbacks) → score ~25, grade F
- Medium trace (300ms, 40 queries, 0 HTTP, 1 slow callback) → score ~75, grade C
- Edge: total_ms < 100ms → DB ratio factor auto 100
- Edge: no HTTP spans → HTTP factor 100
- Edge: no callback spans → slow callbacks factor 100
- Interpolation boundary: exactly at threshold → correct score
- Grade mapping: 90 → A, 89 → B, 70 → C, 60 → D, 59 → F

## File Changes

- Create: `src/Score.php`
- Create: `tests/Unit/ScoreTest.php`
- Modify: `src/Storage.php` — score column, save_trace signature, get_avg_score(), list_traces includes score
- Modify: `src/Admin.php` — score card on flame graph, breakdown section, score column in list, avg score on dashboard
- Modify: `wp-flame.php` — compute score at shutdown, pass to save_trace
- Modify: `assets/css/admin.css` — score badge, breakdown styles

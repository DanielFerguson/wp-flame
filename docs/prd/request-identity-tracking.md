# PRD: Request Identity & Abuse Detection

## Problem

WP Flame shows WHAT is happening (which endpoints are slow, how many queries) but not WHO is causing it. When someone is scraping your WooCommerce customer API with 50 sequential paginated requests, you can see the requests but not the attacker.

Site owners need to answer:
- **Who** is making this request? (authenticated user, API key, anonymous)
- **Where** are they? (IP address, potentially geolocation)
- **What** are they using? (user agent, API client)
- **How much** are they consuming? (request count, total server time per user/IP)
- **Is this abuse?** (sequential pagination, high request rate, unusual patterns)

## User Stories

1. **As a site owner**, I want to see which WordPress user or API consumer made each traced request, so I can identify who is causing heavy load.

2. **As a site owner**, I want to see the IP address of each request, so I can block abusive IPs at the firewall level.

3. **As a site owner**, I want to see which users and IPs are consuming the most server resources, so I can take action on the worst offenders.

4. **As a site owner**, I want to be alerted when a single IP or user is making an unusually high number of requests, so I can detect scraping or abuse early.

5. **As a site owner**, I want to filter my trace list by user or IP, so I can investigate a specific actor's behavior.

## Solution

### Data Collection

Capture three new data points at shutdown for every traced request:

| Field | Source | Storage |
|-------|--------|---------|
| User ID | `get_current_user_id()` | DB column (INT) + Trace meta |
| IP Address | `$_SERVER` headers with proxy detection | DB column (VARCHAR 45) + Trace meta |
| User Agent | `$_SERVER['HTTP_USER_AGENT']` | Trace meta only (too long for a column) |

**IP Detection Priority** (handles proxies, CDNs, load balancers):
1. `HTTP_CF_CONNECTING_IP` (Cloudflare)
2. `HTTP_X_FORWARDED_FOR` (standard proxy, take first IP)
3. `HTTP_X_REAL_IP` (nginx proxy)
4. `REMOTE_ADDR` (direct connection)

Validate with `filter_var($ip, FILTER_VALIDATE_IP)`. Return empty string if none valid.

**User identity enrichment** (for display, not storage):
- User ID 0 → "Anonymous"
- User ID > 0 → look up username, display name, and role via `get_userdata()`
- For WooCommerce REST API: the authenticated user's name shows which API key/user is making calls

### Database Changes

Add 2 indexed columns to `flame_traces`:

```sql
user_id int NOT NULL DEFAULT 0,
ip_address varchar(45) NOT NULL DEFAULT '',
```

Added via `dbDelta()` (handles existing tables). Indexed for filtering and grouping:

```sql
KEY user_id (user_id),
KEY ip_address (ip_address)
```

User agent stored in Trace meta only (not a column — too long, rarely filtered by).

### UI: Trace List

Add two new columns to the trace table:

| URL | User | IP | Score | Method | Duration | ... |
|-----|------|-----|-------|--------|----------|-----|
| /wp-json/wc/v3/customers?... | api_user (shop_manager) | 203.0.113.45 | 57 | GET | 1907ms | ... |
| /wp-admin/ | Daniel (admin) | 192.168.1.10 | 82 | GET | 340ms | ... |

- User column shows: username (role) or "Anonymous"
- IP column shows the IP address
- Both are clickable → filter the table by that user/IP

### UI: Flame Graph View

Add a "Request Context" section below the stat cards (before the color legend):

```
REQUEST CONTEXT
User: api_user (shop_manager)    IP: 203.0.113.45    User Agent: WooCommerce API Manager/2.1
```

Simple horizontal bar, same styling as the color legend.

### UI: Dashboard

Add two new ranking panels to the dashboard (alongside Slowest Pages/Callbacks/Histogram):

**Top Users by Load:**
| User | Requests | Avg Duration | Total Time |
|------|----------|-------------|------------|
| api_user (shop_manager) | 347 | 1,850ms | 641s |
| Daniel (admin) | 89 | 280ms | 25s |
| Anonymous | 45 | 120ms | 5s |

**Top IPs by Request Count:**
| IP Address | Requests | Avg Duration | Total Time |
|------------|----------|-------------|------------|
| 203.0.113.45 | 412 | 1,720ms | 709s |
| 192.168.1.10 | 89 | 280ms | 25s |

Clicking a row filters the trace table.

### UI: Filters

Add to the trace list filter bar:
- User dropdown: list of users who have traces, plus "Anonymous"
- IP search: text input for IP address

### Insight Rules (Abuse Detection)

**High request rate:**
- Trigger: single IP made >100 requests in the traced period
- Severity: warning
- Message: "IP 203.0.113.45 made 412 requests (avg 1,720ms). This may indicate scraping or abuse."

**Sequential API pagination:**
- Trigger: detect sequential `page=N` parameters in REST API URLs from the same IP, with N incrementing
- Severity: warning
- Message: "IP 203.0.113.45 is paginating through /wp-json/wc/v3/customers (pages 1-51). This appears to be systematic data extraction."

**High resource consumer:**
- Trigger: single user/IP consumed >60 seconds of total server time in the traced period
- Severity: warning
- Message: "User 'api_user' consumed 641 seconds of server time across 347 requests."

### Privacy & GDPR

IP addresses are personally identifiable information under GDPR.

**Mitigations:**
- IP tracking respects the data retention setting (pruned with traces)
- New setting: `wp_flame_track_ips` (bool, default true) — can be disabled for GDPR compliance
- When disabled, IP column stores empty string
- User IDs are already accessible to admins via WordPress user management — no additional privacy exposure
- User agents are anonymized to first 500 characters

**Privacy documentation:**
- Settings page includes a note: "IP addresses are stored with traces and deleted according to your retention policy."
- readme.txt FAQ entry about GDPR compliance

### Settings

One new setting:
- `wp_flame_track_ips` — bool, default true. Label: "Track IP addresses". Description: "Record the IP address of each traced request. Disable for GDPR compliance. IPs are deleted with traces according to your retention policy."

## Technical Implementation

### Files Changed

**Modified:**
- `src/Storage.php` — add columns to schema, update save_trace/list_traces, add get_top_users/get_top_ips methods
- `src/Admin.php` — user/IP columns in trace list, request context in flame graph, user/IP rankings in dashboard, user/IP filters
- `src/Insights.php` — 3 new abuse detection rules
- `wp-flame.php` — collect user_id/ip/user_agent at shutdown, new setting default
- `src/Settings.php` — IP tracking toggle
- `assets/css/admin.css` — request context styling

**No new files.** This builds entirely on existing patterns.

### Storage Queries

**get_top_users(int $limit, int $days):**
```sql
SELECT user_id, COUNT(*) as request_count, AVG(total_ms) as avg_ms, SUM(total_ms) as total_ms
FROM flame_traces
WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
GROUP BY user_id
ORDER BY total_ms DESC
LIMIT %d
```

**get_top_ips(int $limit, int $days):**
```sql
SELECT ip_address, COUNT(*) as request_count, AVG(total_ms) as avg_ms, SUM(total_ms) as total_ms
FROM flame_traces
WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND ip_address != ''
GROUP BY ip_address
ORDER BY request_count DESC
LIMIT %d
```

### Performance

- Two new indexed columns: negligible storage overhead (~50 bytes per trace)
- Dashboard queries use indexed GROUP BY — fast even with thousands of traces
- IP detection at shutdown: 4 header checks + 1 validation = microseconds
- User ID lookup for display: `get_userdata()` is cached by WordPress

## Success Criteria

1. Looking at the trace list, the admin can immediately see which user and IP made each request
2. The dashboard shows which users/IPs are consuming the most resources
3. Abuse patterns (pagination scraping, high request rate) trigger automatic insights
4. The admin can filter traces by user or IP with one click
5. IP tracking can be disabled for GDPR compliance

## Out of Scope (Future)

- IP geolocation (would require MaxMind database or external API)
- Automatic IP blocking (should be done at firewall level, not in PHP)
- Rate limiting (not a profiling tool's job — recommend a WAF)
- API key attribution (WooCommerce REST API uses user-based auth, so user_id covers this)
- Real-time alerting (Slack/email when abuse is detected)

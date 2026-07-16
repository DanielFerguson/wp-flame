# Privacy and data inventory

Status: public v1 contract
Last verified: 2026-07-16

WP Flame is a local WordPress performance monitor. It has no SaaS requirement and sends no trace, identity, license, analytics, or telemetry data to WP Flame or another external service. WordPress and installed plugins can still make their own external requests; WP Flame observes supported requests but does not add a reporting destination.

## Stored by default

Each retained trace can contain:

- A generated trace ID, UTC capture time, request method, HTTP status, request type, capture policy, and instrumentation mode.
- A display URL whose query values and unsafe query keys are redacted. Email addresses, numeric IDs, UUIDs, long mixed identifiers, and values following common identity-route segments are redacted from its path.
- A separate normalized route key used for stable grouping. Dynamic IDs and UUIDs become placeholders; query values are not part of the grouping key.
- Observed duration, memory, query counts, score/version fields, completeness reasons, sampling probability, and capture-capability evidence.
- Bounded lifecycle, callback, database, HTTP, and GraphQL spans when their instrumentors are available.
- Database statement type, normalized query fingerprint, and a label with literal values and SQL comments removed. Full SQL is off by default.
- External HTTP host names and bounded timing metadata. Full external URLs are off by default.
- GraphQL operation/resolver labels and query length where supported. Full GraphQL text is off by default.
- WordPress, PHP, WP Flame, active plugin, theme, and relevant environment versions in a deduplicated environment snapshot.
- Locally generated insights and the evidence used to support them.

Custom code can add bounded trace metadata through the documented `wp_flame_trace_meta` filter. A site using that filter is responsible for documenting and handling any additional category it stores.

## Sensitive opt-in categories

All sensitive categories default off. Enabling any of them requires the shared sensitive-data acknowledgement as well as its individual setting. Runtime checks enforce both conditions, so changing an individual option directly does not bypass acknowledgement.

| Category | Option | Location | Risk |
|---|---|---|---|
| Logged-in WordPress user ID | `wp_flame_track_users` | Indexed trace column and trace export context | Direct account association |
| Client IP address | `wp_flame_track_ips` | Indexed trace column | Personal data and approximate location/network identity |
| Browser user-agent | `wp_flame_track_user_agent` | Trace metadata | May contribute to browser/device fingerprinting |
| Full SQL query text | `wp_flame_full_query_text` | Database span metadata | May contain form values, emails, tokens, or content; comments are still removed |
| Full external HTTP URL | `wp_flame_full_http_url` | HTTP span metadata | May contain identifiers, tokens, paths, or query values |
| Full GraphQL query text | `wp_flame_full_graphql_query` | GraphQL span metadata | May contain identifiers, arguments, or user-provided values |

Sensitive settings should be used only with an appropriate purpose, notice, access policy, retention period, and response process. Deep mode does not itself enable these categories.

## Storage, retention, and access

Trace, capture-session, environment-snapshot, option, and aggregate data are stored in the site's WordPress database. Trace access is restricted to administrators with `manage_options`. Output is escaped and trace-controlled graph labels are rendered through DOM-safe paths.

Traces become eligible for deletion after the configured retention period. Cleanup uses bounded batches and resumable continuations. Per-site row and encoded-JSON byte ceilings stop new persistence before storage can grow indefinitely; expired traces are pruned first, but unexpired traces are never silently evicted. Administrators can delete individual traces or purge all trace data. See `docs/architecture/STORAGE-QUOTA.md` for the exact quota contract.

Environment snapshots and aggregate rollups are local diagnostic records rather than visitor identity records. Uninstall removes all plugin trace, session, environment, rollup, option, transient, cron, and owned mu-plugin data for each site, using checkpointed multisite batches.

Aggregate rollups contain normalized route, source/callback labels, daily performance measures, capability evidence, and sample counts. They do not contain user IDs, IP addresses, user agents, raw SQL, full external URLs, or full GraphQL documents. Purge-all removes raw traces and derived rollups. Individual trace deletion cannot reliably subtract one contribution from an already aggregated daily row, so rollup population means successfully summarized captures rather than currently retained raw-trace count. See `docs/architecture/ROLLUPS.md` for the complete contract.

## WordPress privacy tools

When user-ID capture is enabled, the WordPress personal-data exporter selects traces linked to the requested WordPress account in pages of 10. Each item includes the indexed identity fields and the complete bounded trace JSON so personal data in opted-in span metadata or extension metadata is not omitted.

The eraser deletes matching user-linked traces in batches of 500 and asks WordPress to continue until the final partial batch. This avoids an unbounded deletion request.

Anonymous traces have `user_id = 0`. WP Flame cannot reliably determine which anonymous trace belongs to an email address, even if the trace contains an IP address or other indirect identifier. The eraser therefore explains this limitation instead of claiming those rows were removed. Use the configured retention window, individual administrative deletion, or purge-all control for anonymous trace deletion.

## Suggested policy text

WP Flame registers suggested text with WordPress's Privacy Policy Guide. The registered text describes local performance capture, default redaction, sensitive opt-ins, retention and quotas, export/erasure behavior, and the anonymous-erasure limitation. Site operators must adapt it to their actual capture policy, lawful basis, audience, installed extensions, and jurisdiction.

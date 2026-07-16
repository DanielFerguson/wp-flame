# WP Flame public v1 compatibility matrix

Recorded: 2026-07-16

## Capability legend

- **Captured:** the advertised capability has deterministic automated evidence.
- **Not requested:** the selected mode intentionally excludes the capability.
- **Degraded:** WP Flame continues operating and explains the missing boundary.
- **Configured gate:** CI is configured to run the row, but a tagged release must retain the resulting immutable CI evidence before publication.

## Platform matrix

| Platform | Release expectation | Evidence/gate | Capability notes |
| --- | --- | --- | --- |
| WordPress 6.0 / PHP 7.4 / MySQL 8 | Supported lower bound | Integration CI matrix, unit PHP matrix, syntax gate | Safe/Standard supported; Deep uses only PHP 7.4-compatible syntax and supported WordPress hooks. Current tagged CI evidence is required. |
| WordPress 6.7 / PHP 8.1 / MySQL 8 | Supported | Integration CI matrix | Same capture contract. |
| Current WordPress / PHP 8.3 / MySQL 8 | Supported | Integration CI matrix; local single-site suite | Same capture contract. |
| Current WordPress / PHP 8.4 / MySQL 8 | Supported | Integration CI matrix | Same capture contract. |
| Current WordPress / PHP 8.5 / MySQL 8 | Supported | Unit and integration CI matrix | Same capture contract; current tagged CI evidence is required. |
| Current WordPress / PHP 8.3 / MariaDB 11.4 | Supported | Dedicated integration CI lane | Same core-`wpdb` capture contract; current tagged CI evidence is required. |
| Multisite / current WordPress / PHP 8.5 | Supported | Dedicated no-skip multisite integration lane | Network activation, migration, pruning, deactivation, and uninstall are checkpointed; capture/storage remain per-site. |

The local M6 benchmark environment was WordPress 7.0.1, PHP 8.3.32, and MariaDB 12.3.2 in Docker Desktop on Apple Silicon. That environment is benchmark evidence, not a replacement for the lower-bound and database CI lanes.

## Host and database behavior

| Combination | Safe | Standard | Deep | Evidence and limitation |
| --- | --- | --- | --- | --- |
| Owned, current mu-plugin installed | Captured from the early bootstrap boundary | Adds compatible DB spans | Adds supported callback spans | Default full-capability path; ownership/hash lifecycle tests and live smoke. |
| Managed host blocks mu-plugin copy | Captured from `plugins_loaded` | DB captured when compatible | Supported callbacks captured after degraded bootstrap | Early lifecycle is **degraded** and visibly unavailable. Plugin continues without a fatal installation requirement. |
| Core `wpdb` | DB not requested | DB captured | DB captured | Integration query fixture proves delegation and spans. |
| Custom `wpdb` subclass/drop-in that cannot be safely replaced | DB not requested | DB **degraded/unavailable** | DB **degraded/unavailable**; callbacks may still be captured | Original DB object is preserved. Query conclusions are unknown rather than scored as healthy. |
| Persistent object-cache drop-in | Cache counters captured only when exposed by the runtime object | Same | Same | Drop-in ownership is attributable; backend health remains unknown where the drop-in exposes no trustworthy counters. |
| No persistent object cache | Cache class/counters observed where available | Same | Same | `NoPersistentCache` finding reports configuration evidence; it does not claim a cache-service outage. |

## Plugin and request matrix

| Integration/workflow | Expected evidence | Automated evidence | Known limitation |
| --- | --- | --- | --- |
| WooCommerce product and checkout | Frontend route cohort, lifecycle, DB/HTTP spans by mode | Live compatibility smoke and 100-run overhead fixtures | Requests served entirely by a page cache/CDN do not run WP Flame. Browser/network performance is not measured. |
| WooCommerce admin | Authenticated admin request timing and status equivalence | Logged-in benchmark request context; browser workflow suite | Dynamic admin documents are not byte-compared because nonces/live data change per request. |
| WooCommerce AJAX | `wc-ajax` action route and AJAX dispatch phase | Route/lifecycle fixtures | Identifiers are normalized; unrelated AJAX actions remain separate cohorts. |
| Elementor page | Frontend route plus plugin attribution and mode capabilities | Live compatibility smoke and overhead fixture | WP Flame measures server-side generation, not Elementor editor/browser rendering. |
| bbPress | Forum frontend request and plugin attribution | Live compatibility smoke | Same dynamic-request boundary as other frontend routes. |
| WPGraphQL | Operation, supported root resolver, nested DB evidence, GraphQL route | Unit fixtures and live compatibility assertion | Only the current advertised hooks and root-resolver boundary are claimed. Unsupported hook versions degrade visibly. |
| REST API | REST namespace/route cohort and REST dispatch phase | Unit/integration fixtures and overhead fixture | Normalized paths omit identifiers/secrets. |
| WordPress AJAX | Action cohort and AJAX dispatch phase | Unit/integration fixtures and overhead fixture | Unknown actions can legitimately return a non-200 application result; capture must preserve it. |
| Cron/Action Scheduler | Separate background/cron cohort and dispatch phase | Unit/integration fixtures and cron overhead fixture | Cron is deliberately not mixed with interactive user cohorts. |
| WP-CLI | Command route and command-execution phase | Unit/integration route fixtures | CLI wall time can include command bootstrap outside the earliest available plugin boundary. |

## Mode matrix

| Capability | Safe | Standard | Deep |
| --- | --- | --- | --- |
| Supported lifecycle phases | Captured | Captured | Captured |
| WordPress HTTP API spans | Captured | Captured | Captured |
| Compatible database spans | Not requested | Captured | Captured |
| Supported WordPress callbacks | Not requested | Not requested | Captured |
| Arbitrary internal PHP functions | Unavailable/not claimed | Unavailable/not claimed | Unavailable/not claimed |
| Operation policy | Manual or explicitly sampled | Manual or explicitly sampled | One request/five minutes; automatically expires |

## Public known limitations

- Pre-ownership-marker WP Flame early loaders are upgraded only when their complete SHA-256 matches a known WP Flame 1.x build. An unknown or locally modified collision at `wp-content/mu-plugins/wp-flame-early-hooks.php` is deliberately not overwritten; WP Flame runs degraded and directs the operator to review the file.
- During a compatible application-package rollback, a newer loader can coexist with the older package for one request. It falls back to the legacy Bootstrap boundary so the older main plugin can restore its own loader without a fatal; rollback does not perform a destructive database down-migration.
- WP Flame is a server-side WordPress request timeline, not a browser RUM tool, Core Web Vitals service, full infrastructure APM, or arbitrary PHP function profiler.
- Page-cache/CDN hits that do not execute PHP are invisible.
- Persistence occurs after the customer trace stops but still consumes real request shutdown time; the external benchmark includes that cost.
- Database and callback capture depend on compatible WordPress extension points. Missing capability is shown as unavailable and lowers confidence.
- Source attribution is best-supported ownership evidence, not proof that every internal call from that source was timed.
- The default is manual-only. Automatic sampling is an explicit opt-in and should use a conservative rate validated on the target site.
- The 5,000-span renderer and configured collector/storage limits intentionally bound data. Dropped/trimmed traces are marked incomplete.
- Plugin Check currently warns that the `WP Flame` name and `wp-flame` slug contain the WordPress.org-reserved `WP` term. Direct commercial distribution can continue under the working name, but a WordPress.org Community submission requires a naming/channel decision before M7 packaging is frozen.
- The production ZIP intentionally contains Composer's generated PSR-4 autoloader without development metadata. Plugin Check reports the absent `composer.json` as a warning; the package validator proves that only the generated runtime autoloader is shipped.

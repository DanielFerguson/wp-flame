# M6 release-time application assurance review

Review date: 2026-07-16
Scope: public v1 performance-measurement plugin before any licensing or authenticated-update implementation
Outcome: no unresolved release-blocking finding in the reviewed scope; the directly distributed single-edition RC has no updater or entitlement runtime, and any later implementation requires its own follow-up review

This is an assurance review of WP Flame itself. It is not a vulnerability-scanning feature, customer-facing security monitor, or guarantee about the monitored site.

## Severity rule

- **Blocker:** can cause site failure, unbounded overhead/storage, measurement corruption, unauthorized state change/data exposure, or unsafe install/update/removal.
- **Required follow-up:** a later M7 component is not yet present and cannot be approved in advance.
- **Advisory:** hardening or maintainability work that does not invalidate the public v1 contract.

## Findings register

| ID | Area | Result | Evidence / disposition |
| --- | --- | --- | --- |
| M6-A-001 | Runtime capability checks | Pass | Trace, settings, capture-session, comparison export, delete, purge, privacy, and admin rendering paths require `manage_options`. Manual capture cookies are administrator-bound and nonce-bound. |
| M6-A-002 | State-changing request nonces | Pass | Capture start/stop, comparison export, trace deletion, purge, and force-trace flows verify action-specific nonces. Request values are unslashed, sanitized, enum/UUID checked, and byte-bounded. |
| M6-A-003 | Stored personal/sensitive data | Pass | Identity, IP, user-agent, full SQL, full HTTP URL, and full GraphQL text are off by default and require both an individual option and the shared acknowledgement. Export, erasure, anonymous-data limitation, retention, purge, and uninstall behavior match `docs/PRIVACY.md`. |
| M6-A-004 | SQL handling | Pass with documented trusted-table exception | User values use `$wpdb` helpers or prepared placeholders. Dynamic table names are derived only from the trusted WordPress prefix plus static suffixes. SQL comments/literals are normalized for default trace storage. Database instrumentation records bounded failure evidence and delegates the original query unchanged. |
| M6-A-005 | Trace-controlled DOM content | Pass | PHP views escape output. The flame graph bounds and escapes trace strings before parsing SVG, never assigns trace data through raw `innerHTML`, imports the parsed SVG node, and builds tooltip/detail content with `textContent`/DOM methods. Malformed/cyclic/deep/oversized metadata fixtures and axe/browser tests pass. |
| M6-A-006 | Failure isolation | Pass | Stopped collectors no-op, instrumentor failures become capability/incompleteness evidence, persistence failure returns an explicit result without changing the monitored response, and mu-plugin failure enters degraded mode. Browser coverage proves reversible persistence failure. |
| M6-A-007 | Storage and retention bounds | Pass after remediation | Row/byte quotas, trace/span limits, bounded retention, migration locks, and resumable multisite operations are enforced. M6 benchmarking found an unbounded-cost `SUM(LENGTH(LONGTEXT))` quota scan; schema v6 now stores `trace_bytes`, backfills in 500-row batches, and uses the compact numeric field for exact quota totals. Focused integration evidence: 25 tests/393 assertions. |
| M6-A-008 | External communication | Pass for the single-edition RC scope | The current plugin adds no analytics, license, telemetry, update, or WP Flame service request. WordPress/plugin HTTP requests may be observed but are not forwarded. Public documentation states this explicitly. |
| M6-A-009 | Dependency exposure | Pass | Runtime remains dependency-free. Composer audit and npm audit are release gates; the resolved JavaScript dependency graph reports no known advisory at this review. |
| M6-A-010 | Update authenticity and entitlement failure | Not applicable to the direct RC; mandatory follow-up if introduced | The accepted RC has no licensing client, entitlement check, or custom updater. Any later implementation must review metadata authenticity, TLS/host assumptions, package integrity, rollback, staging activation, expiry, consent/disclosure, and service-unavailable behavior. No approval is inherited from this review. |

## Quality-baseline decision

The M0 unit-only statement-coverage floor was deliberately superseded at M6 from 67.14% (2,525/3,761 statements) to 63.41% (4,355/6,868 statements). The covered statement count increased by 1,830 while the measured production surface increased by 3,107 statements through M1–M6. The added WordPress lifecycle, storage, migration, compatibility, and browser workflow paths are also exercised by integration, compatibility, and Playwright gates, but those suites do not contribute to the unit Clover report. The 63.41% floor is now the public-v1 unit non-regression threshold; lowering it again requires another explicit documented review.

## Capability and authorization flow

1. Public requests may be observed only when an explicit manual capture is armed or automatic sampling has been enabled by an administrator.
2. The early mu-plugin performs cheap eligibility gates. A missing/stale/unowned mu-plugin cannot be overwritten silently and results in a visible degraded boundary.
3. Guided capture requires an authenticated administrator, action nonce, bounded server-side session, HttpOnly/SameSite cookie, fixed request population, route claim, request count, and expiry.
4. Trace/settings pages and JSON comparison export remain administrator-only. Export excludes raw spans and sensitive route values by default.
5. All local capture continues independently of any future commercial service; M7 may not weaken this property.

## SQL and storage notes

- Direct SQL used for schema/maintenance interpolates only repository-controlled table names and integer constants; external values are prepared or handled by `$wpdb` CRUD methods.
- Schema v6 is additive. While its bounded backfill is incomplete, v5 storage remains usable and the legacy exact quota calculation remains active. Once complete, every new row stores the encoded JSON byte length and quota aggregation avoids reading the `LONGTEXT` payload.
- Trace JSON is bounded before insert. Oversized traces are leaf-trimmed without orphaning children and carry incomplete/trimmed evidence.
- Environment snapshots are content-addressed and deduplicated. Rollups are versioned, bounded, resumable, and derivable from raw evidence.

## DOM and accessibility notes

- Graph labels, attributes, filters, breadcrumbs, tooltips, and details are treated as untrusted trace-controlled strings.
- SVG construction uses escaped strings plus XML parsing/import. No event attributes or arbitrary markup come from trace metadata.
- Keyboard selection, focus order, filtered hidden state, reduced motion, small-screen operation, semantic controls, and automated axe checks are in the browser gate.

## Release-gate commands

```bash
composer audit
npm audit
composer lint
composer standards
composer analyse
composer test:coverage
composer test:unit
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite integration --exclude-group multisite --fail-on-skipped --do-not-cache-result
WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_MULTISITE=1 ./vendor/bin/phpunit --testsuite integration --fail-on-skipped --do-not-cache-result
npm run test:js
npm run compat:smoke
npm run test:e2e
```

Plugin Check, MariaDB, PHP 8.5, lower-bound WordPress/PHP, package-content, and clean-tag gates also run in CI. A release may not convert a missing CI result into a pass.

## Packaged-plugin review

The assembled `wp-flame-1.3.0-rc.1.zip` was scanned locally with Plugin Check 2.0.0 using the same non-PHPCS checks configured in CI. It returned zero errors. Remaining warnings are documented compatibility/distribution constraints: deliberate direct database access for an APM datastore and instrumentation, early MU-plugin globals, the generated Composer autoloader without development metadata, the external WPGraphQL hook name, and the WordPress.org-reserved `WP Flame` name/slug. WPCS remains a separate enforced no-growth gate, so excluding Plugin Check's duplicate PHPCS review does not remove coding-standard enforcement.

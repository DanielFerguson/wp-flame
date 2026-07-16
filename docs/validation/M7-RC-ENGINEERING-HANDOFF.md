# M7 release-candidate engineering handoff

Verified: 2026-07-16
Candidate: `1.3.0-rc.1`
Engineering state: locally releaseable; official publication remains NO-GO pending the owner-controlled gates below.

## Candidate boundary

- One complete directly supplied paid design-partner GPLv2 package.
- No Community/Pro binary split in the RC.
- No account, entitlement check, remote feature flag, analytics, telemetry, WP Flame service request, or custom updater.
- Performance measurement and diagnosis only; no vulnerability scanning or security-monitoring category.
- Local capture, data, already-installed behavior, removal, and rollback do not depend on a commerce/licensing service.

## Current local gate evidence

| Gate | Result |
| --- | --- |
| PHP unit | 561 tests, 4,354 assertions, pass |
| Statement coverage | 63.54% (4,428/6,969), above 63.41% floor |
| PHP syntax | 103 files, pass |
| WPCS no-growth | 1,368 existing violations across 207 committed groups; no group grew |
| PHPStan | configured analysis pass with no unbaselined errors |
| Composer | strict manifest validation pass; no advisory found |
| npm | JavaScript tests pass; zero advisories |
| WordPress single-site integration | 48 tests, 456 assertions, no skip |
| WordPress multisite integration | 49 tests, 477 assertions, no skip |
| Live compatibility | WooCommerce, Elementor, bbPress, WPGraphQL; Safe, Standard, and Deep pass |
| Browser/accessibility | 5 Playwright workflows pass, including small-screen and axe checks |
| Performance | CI-sized 31-row budget passes at 10 runs; dated 100-run publication remains the public benchmark evidence |
| Plugin Check 2.0.0 | zero errors using CI's non-PHPCS package checks; documented warnings remain |
| Package contents | self-contained operator docs, GPL license, disclosure route, optimized runtime autoloader; dev/internal material excluded |
| Reproducibility | two builds from reviewed committed candidate `3b82fa3` are byte-identical |
| Package lifecycle | clean install, deactivate/reactivate, uninstall, authentic `1.2.0` upgrade, loader migration, schema migration, rollback, data retention, and return to candidate pass |

The local untagged artifact built from reviewed candidate commit `3b82fa3` is approximately 224 KB with SHA-256 `77375591fe41311153b402c34a241f9db9f98d029d5be82105fdac25c485f829`. A second build was byte-identical. This is diagnostic evidence only: the official checksum must derive from the merged exact tag and its commit timestamp.

## Corrected lifecycle evidence

The first package-lifecycle implementation used a current working tree relabelled `1.2.0`. That was not a valid predecessor and produced two false passes:

1. It already contained the ownership marker, hiding the upgrade behavior of pre-marker early loaders.
2. It already contained the new `Lifecycle` class, hiding a fatal when the newer mu-plugin loader was briefly paired with genuinely old application files during rollback.

The gate now reconstructs the predecessor from commit `e58bef570e8e6b53925796de693937957765b581`. The candidate adopts only complete hashes of known WP Flame legacy loaders, refuses unknown/modified collisions, and falls back to the old Bootstrap label when its newer `Lifecycle` helper is absent. CI fetches full history and runs this authentic package lifecycle after compatibility, E2E, accessibility, and performance gates.

## Tagged release contract

- Version pushes matching `v*` run the complete CI workflow.
- The tag-only immutable-release job depends on every quality, PHP matrix, integration, multisite, MariaDB, compatibility/browser/performance/lifecycle, Plugin Check, and package job.
- `bin/release-package.sh` requires a clean working tree and exact tag `v<version>`.
- It builds twice from the tag epoch, refuses byte differences, and writes the ZIP, SHA-256 file, and `wp-flame-release-provenance.v1` JSON.
- CI uploads that bundle only after all dependency jobs pass.

## Documented non-blocking package warnings

- Direct database calls and dynamic table identifiers are intrinsic to the bounded local APM datastore/instrumentation and receive focused review; values remain prepared and identifiers are derived from WordPress prefixes and fixed suffixes.
- The generated Composer runtime autoloader ships without development Composer metadata.
- Early-loader globals, WordPress's `SAVEQUERIES` constant, and the external WPGraphQL hook name are integration constraints.
- `WP Flame` and `wp-flame` use the WordPress.org-reserved `WP` term. The direct paid RC can retain the working name, but this is not approval for a WordPress.org submission.

## Owner-controlled gates still open

Repository authorization and logical candidate commits are complete. Generated caches and artifacts were excluded, and the tracked worktree was clean after commit formation.

1. **Review-branch publication and exact tag:** no Git remote is configured in this workspace. Publish/review/merge `feat/wp-flame-v1-rc`, create `v1.3.0-rc.1`, and require the tag workflow to pass once the intended repository is available.
2. **Seller and policy inputs:** complete `docs/commercial/PUBLICATION-INPUTS.md`, qualified terms/refund/privacy review, public support contact/capacity, checkout/tax/renewal configuration, and final URLs.
3. **Paid-RC entry:** confirm capacity, accept payment only for qualified participants, obtain separate per-site authorization and separate optional research/publication permissions.
4. **M8 evidence:** run 10–20 paid partners, at least 14 qualifying field days, three permissioned compatible before/after cases, and blocker closure.

No local test can convert any of those external records into a pass. The release decision remains `NO-GO` until their evidence is linked from `M8-GO-NO-GO.md`.

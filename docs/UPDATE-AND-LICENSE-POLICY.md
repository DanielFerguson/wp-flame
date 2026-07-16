# Update and license behavior

Status: local single-edition RC behavior is implemented; any later Community/Pro licensing terms remain a hypothesis pending paid design-partner evidence and a public-sales decision.

`1.3.0-rc.1` uses the single-package direct design-partner channel defined in `docs/architecture/RC-DISTRIBUTION.md`. It has no software entitlement check or WP Flame update client. Candidate packages, checksums, notes, and rollback instructions are supplied manually to the small authorized cohort.

## Local measurement core

- Local capture, storage, diagnosis, comparison, privacy, export, and cleanup do not require a WP Flame service.
- The current RC sends no trace, identity, analytics, telemetry, or license request to WP Flame.
- Updates must be obtained through the chosen disclosed distribution channel and must preserve the versioned trace/storage read contract.

## Pro contract that implementation must preserve

- A license may govern Pro updates, support, and separately consented connected services.
- Expiry or a temporary licensing-service failure must not delete data, disable already-installed local features, alter trace accuracy, or interrupt local capture.
- Staging/local environments should not consume production activations.
- External license/update requests require clear disclosure, explicit activation by an administrator, bounded timeouts, TLS, and failure isolation.
- Update metadata must be authenticated. A package must have an immutable version, checksum, provenance, compatibility range, and rollback instructions.
- A failed or unavailable update must leave the currently installed package operating.
- Rollback means installing an older application package that can still read the additive schema. WP Flame will not run destructive database down-migrations.

No licensing or authenticated updater is present in `1.3.0-rc.1`. Checkout may sell only the documented single-package paid design-partner offer; it must not claim a Pro entitlement or automatic update service until the vendor, tax treatment, privacy disclosure, expiry behavior, updater authenticity, and outage tests pass the M7 follow-up review.

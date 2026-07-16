# Community and Pro Measurement Boundary

**Status:** Provisional public-v1 architecture contract; not implemented in the single-edition RC

**Last updated:** 2026-07-16

## Decision

WP Flame Community and WP Flame Pro must share the same measurement truth.

Payment may add monitoring duration, workflow, reporting, support, and automation value. It must never change the meaning, accuracy, completeness disclosure, or essential interpretation of a captured trace.

This boundary is provisional until manually billed design-partner work validates the packaging. Full licensing and updater implementation must not begin until that evidence is reviewed.

For `1.3.0-rc.1`, the accepted distribution architecture is instead one directly supplied paid design-partner package with no edition split and no runtime licensing dependency. See [RC-DISTRIBUTION.md](RC-DISTRIBUTION.md). The Community/Pro contract below constrains a possible later architecture; it is not a claim that two RC editions exist.

## Community owns the measurement core

The Community plugin remains the authoritative owner of:

- Collector, Span, Trace, and trace schema.
- Capture capability and incompleteness reporting.
- Safe, Standard, and one-shot Deep instrumentation contracts.
- Lifecycle, database, WordPress HTTP API, callback, source, and supported GraphQL instrumentation.
- Privacy redaction and sensitive-capture controls.
- Storage schema, migrations, quotas, retention, export, erasure, and uninstall cleanup.
- Versioned performance scoring.
- Essential performance findings needed to understand one trace.
- Interactive trace and span-detail UI.
- Manual capture and a bounded recent history.
- Redacted single-trace export.
- Extension hooks and compatibility contracts used by either edition.

Community must let a user capture one supported workflow, see what was and was not observed, identify a credible measured contributor, and share the essential evidence.

## Pro may add recurring workflow value

Subject to paid validation, Pro may add:

- Controlled scheduled or continuous sampling policies.
- Longer configurable history within the same storage safety contract.
- Saved monitored workflows and filters.
- Compatible cohort comparison and richer historical views.
- Workflow-specific aggregation and guidance.
- Professional report layouts and additional export formats.
- Configuration portability.
- Priority support.
- Later connected or fleet services with separate consent.

Pro guidance may add domain context, but it cannot withhold an essential finding or make a Community trace less accurate.

## Pro must not

Pro must not:

- Replace or fork the core trace schema.
- Recalculate the same trace using a more truthful private algorithm.
- Hide capability failures, dropped spans, truncation, or uncertainty.
- Unlock raw data that Community captured but deliberately concealed from the user.
- Claim complete profiling from the same partial instrumentation.
- Remove privacy bounds or bypass storage quotas.
- Make local capture depend on licensing or network availability.
- Disable already-installed local monitoring when a license expires.
- install or update premium code from the WordPress.org plugin.

## Versioned extension contract

Before Pro code is implemented, the Community plugin must expose a small versioned contract:

- Core and extension contract versions are explicit.
- Pro declares the supported core-version range.
- Unsupported combinations fail closed with an administrator notice.
- Core owns schema migrations and backward hydration.
- Pro stores edition-specific data separately unless a field is part of the public core schema.
- Pro cannot mutate instrumentor results after persistence without preserving the original evidence.
- Filters and actions remain bounded and cannot silently remove completeness warnings.
- Licensing and update failures do not interrupt local tracing.

## Entitlement behavior

The initial entitlement hypothesis is:

- Licenses govern Pro updates, support, and connected services.
- Staging and local environments do not consume production activations.
- Expiration does not delete customer data or disable already-installed local features.
- Failed entitlement requests use a bounded timeout and preserve local operation.
- External requests require disclosure and the consent appropriate to their purpose.

The commercial validation programme may change pricing and site limits without changing the shared measurement contract.

## Distribution constraint

If Community is distributed through WordPress.org:

- It must solve a complete problem without a trial quota.
- Locally implemented premium functionality cannot merely be unlocked by payment.
- Pro code must be distributed separately.
- Community cannot install Pro from an external server.
- Upgrade prompts must be contextual and restrained.

The current commercial source of truth is [../COMMERCIAL-STRATEGY.md](../COMMERCIAL-STRATEGY.md). The implementation sequence and release gates are in [../ROADMAP.md](../ROADMAP.md).

## Validation required before M7

Before the edition boundary becomes final:

1. Run manually billed diagnoses or a lightweight paid design-partner offer.
2. Observe which recurring workflows customers actually value.
3. Verify that Community solves one complete diagnosis without expert-only hidden data.
4. Test whether monitoring, history, comparison, reports, or support drive willingness to pay.
5. Review support and infrastructure cost.
6. Record the accepted boundary in an architecture decision.

Pricing and packaging may remain reversible. Measurement truth may not.

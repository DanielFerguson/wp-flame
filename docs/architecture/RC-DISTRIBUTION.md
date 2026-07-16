# Release-candidate distribution decision

**Status:** Accepted for `1.3.0-rc.1` paid design-partner validation

**Decision date:** 2026-07-16

## Decision

Distribute `1.3.0-rc.1` directly to paid design partners as one complete GPLv2 plugin package. The RC has no Community/Pro code split, account requirement, embedded checkout, license key, telemetry client, remote feature flag, or custom update service.

The RC has no runtime licensing dependency.

Payment records entitlement to the design-partner programme, the supplied builds, onboarding, and the documented support relationship. It does not unlock locally hidden code. The founding offer remains the reversible `$99/year for up to five production sites` hypothesis until the paid gate is evaluated.

The archive is delivered only after payment and site authorization through a controlled manual channel. Every delivery identifies the immutable version, SHA-256 checksum, compatibility statement, release notes, and rollback instructions. Candidate updates are supplied manually during the small RC cohort. WordPress must not be told that an update is available unless WP Flame later implements an authenticated updater that passes the M7 follow-up assurance review.

## Why this is the RC boundary

- It allows real willingness-to-pay and repeat-use evidence without committing to a vendor or edition architecture prematurely.
- Local performance capture cannot fail because a licensing or commerce service is down; no such runtime dependency exists.
- It avoids adding consent, privacy, compatibility, outage, and update-authenticity risk before those services have demonstrated customer value.
- It keeps the measurement product identical for every participant, so commercial packaging cannot distort trace truth.
- It is operationally viable for the deliberately small 10–20-partner RC cohort.

## Explicit non-decisions

This decision does not select the broad public-v1 distribution channel, promise a WordPress.org Community edition, approve a Pro add-on, or select Lemon Squeezy, Freemius, Easy Digital Downloads, or another provider. It also does not make manual fulfillment acceptable for unrestricted public sales.

After the paid design-partner gate, the product owner must choose one of:

1. Continue as one paid directly distributed plugin.
2. Publish a genuinely useful Community plugin and distribute a separate Pro add-on.
3. Change the offer when paid evidence shows that software licensing is not the right commercial model.

Any future licensing, checkout SDK, analytics, connected service, or custom updater is a new trust boundary. It requires explicit administrator disclosure/consent, bounded failure behavior, a data-flow review, authenticated packages, rollback proof, and the M7 application assurance follow-up before release.

## Operational contract

- The RC package remains fully functional without internet access to WP Flame.
- Expiry, cancellation, or refund administration occurs outside the plugin and cannot delete data or disable installed local behavior.
- Trace data remains on the WordPress site unless an authorized operator deliberately exports it.
- The support bundle is redacted by default and sending it is a separate operator action.
- Site installation authorization, trace review, research, testimonial, logo, screenshot, and case-study permissions remain separate records.
- A participant can deactivate and uninstall the plugin using the tested lifecycle path.

## Exit condition

Revisit this decision only after `docs/commercial/PAID-DESIGN-PARTNER-GATE.md` contains the required paid and repeat-use evidence. Preserve this single-package path if no more complex architecture demonstrates enough customer and operational value to justify its risk.

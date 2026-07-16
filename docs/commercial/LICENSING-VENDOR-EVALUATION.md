# Checkout, licensing, and update vendor evaluation

Reviewed: 2026-07-16
Decision status: shortlist only; implementation is blocked by the paid design-partner gate.

## Recommendation for the validation stage

Use a hosted checkout or manual invoice for the first five paid transactions without embedding a licensing SDK in WP Flame. Lemon Squeezy is the leading lightweight checkout hypothesis because it acts as merchant of record, handles sales tax/VAT, supports subscriptions, can issue license keys with activation limits, and exposes activate/validate/deactivate APIs. Do not connect those keys to the RC plugin yet; record entitlements manually during the design-partner stage.

After the paid gate, choose between:

1. **Lemon Squeezy plus a small WP Flame-owned updater**, if merchant-of-record simplicity and control of the plugin UX outweigh the engineering/security cost of an updater.
2. **Freemius**, if a WordPress-native licensing/update/checkout stack materially reduces launch/support work and its SDK, opt-in, data flows, and edition model can satisfy WP Flame's local-first/minimal-runtime contract.
3. **Easy Digital Downloads Software Licensing**, if owning the commerce stack and WordPress-native updater behavior outweigh the store, tax, reliability, and maintenance work.

This is not a vendor selection. No candidate has yet passed the required outage, privacy, update-authenticity, rollback, multisite, and expiry tests.

## Current official capability evidence

| Candidate | Confirmed capability | Fit | Principal validation risk |
| --- | --- | --- | --- |
| Lemon Squeezy | Merchant of record; tax/VAT handling; hosted checkout/customer portal; subscription-linked license keys; activation limits; activate/validate/deactivate License API; webhooks | Strong for fast paid validation and reducing tax operations | The reviewed documentation does not provide a WordPress-specific authenticated updater. WP Flame would own update metadata/package authorization and its assurance burden. License API responses can contain customer/order data that must never be persisted unnecessarily on a monitored site. |
| Freemius | WordPress SDK provides licensing, account/upgrade screens, checkout integration, release management, staged rollout, and premium updates | Strongest WordPress-specific integrated path | Adds a substantial runtime SDK and administrator/account UX. The default opt-in can share profile, website, product, theme, and plugin information; WP Flame would require a deliberately reviewed consent/data configuration and must ensure Community works without connection. |
| Easy Digital Downloads Software Licensing | License generation/activation limits/expiry, local-host handling, customer site management, WordPress update metadata/downloads, version requirements, staged rollouts, and API updater | High control and familiar WordPress update behavior | Requires operating the store/API and validating tax/merchant-of-record responsibilities, availability, security, updater-class conflicts, backups, and customer account operations. |

Official references:

- [Lemon Squeezy payments and merchant-of-record role](https://docs.lemonsqueezy.com/help/payments)
- [Lemon Squeezy License API](https://docs.lemonsqueezy.com/api/license-api)
- [Lemon Squeezy license-key generation](https://docs.lemonsqueezy.com/help/licensing/generating-license-keys)
- [Freemius WordPress SDK](https://freemius.com/help/documentation/wordpress-sdk/)
- [Freemius software update distribution](https://freemius.com/help/documentation/wordpress/software-updates-distribution/)
- [Freemius opt-in behavior](https://freemius.com/help/documentation/wordpress-sdk/features/opt-in-screen/)
- [EDD Software Licensing](https://easydigitaldownloads.com/docs/software-licensing-usage-instructions/)
- [EDD license and version API](https://easydigitaldownloads.com/docs/software-licensing-api/)
- [EDD platform-version requirements](https://easydigitaldownloads.com/docs/software-licensing-minimum-requirements/)

## Mandatory proof before implementation approval

Each remaining candidate must demonstrate:

- Exact data sent on activation, validation, update check, download, deactivation, refund, expiry, and uninstall.
- Administrator consent and disclosure before the first WP Flame-originated request.
- A five-second maximum request timeout outside customer trace collection, with cached entitlement and exponential backoff.
- Local Community capture and already-installed Pro features continuing during DNS, TLS, API, malformed-response, rate-limit, expired-key, and disabled-key failures.
- Staging/local classification that cannot consume production activations accidentally and has an override/audit path.
- Multisite/network behavior and least-privilege license administration.
- Authenticated update metadata, version/compatibility constraints, immutable package checksum, safe temporary install, failure recovery, and application-package rollback.
- No remote code loading, no Community-installed premium download from WordPress.org, and no destructive schema downgrade.
- Refund, chargeback, renewal, cancellation, grace-period, and account-deletion reconciliation.
- Export/deletion and vendor data-processing terms suitable for the published privacy notice.

## Decision scorecard to complete after paid evidence

Score each 0–5 only after a sandbox proof:

| Criterion | Weight | Lemon Squeezy | Freemius | EDD SL |
| --- | ---: | ---: | ---: | ---: |
| Paid users' required workflow | 20 | — | — | — |
| Tax/MoR operational fit | 15 | — | — | — |
| WordPress updater authenticity/recovery | 15 | — | — | — |
| Local-first privacy/consent fit | 15 | — | — | — |
| Outage and expiry behavior | 10 | — | — | — |
| Runtime footprint/compatibility | 10 | — | — | — |
| Support/admin workload | 10 | — | — | — |
| Migration/exit risk | 5 | — | — | — |

The selected architecture decision must include the sandbox evidence, data-flow diagram, failure matrix, exit plan, and M7 follow-up assurance result. A pricing preference is not enough to approve updater code.

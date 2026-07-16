# Paid release-candidate offer and onboarding copy

Status: website/checkout handoff draft. Replace owner/link placeholders and complete `PUBLICATION-INPUTS.md` before accepting payment.

## Landing-page offer

### Find what is consuming time inside a slow WordPress request

WP Flame is a local performance flight recorder for dynamic WordPress workflows. Capture one bounded request, see the WordPress lifecycle, plugin/theme ownership, compatible database work, WordPress HTTP requests, and supported callbacks that were actually observed, then compare a compatible before/after cohort after making a change.

The paid design-partner release is for agencies, WooCommerce operators, developers, and technical site owners with a real slow dynamic workflow they want to understand.

### Founding offer

**$99 `[CURRENCY]` for one year, covering up to five supported production sites.**

Included:

- The complete `1.3.0-rc.1` GPLv2 plugin package and eligible RC updates supplied manually.
- Checksum, release notes, compatibility information, and rollback instructions for every supplied build.
- Founder onboarding for one qualifying workflow.
- The published software-support scope during the RC programme.
- A weekly opportunity to report defects and influence the public-v1 operating workflow.

The plugin has no account requirement, license check, telemetry client, remote feature flag, or automatic WP Flame update service. Trace data remains in the site's WordPress database unless an authorized operator deliberately exports it.

### What it does

- Guided Standard capture and bounded one-shot Deep callback diagnostics.
- Visible capability, capture-start, completeness, truncation, and confidence evidence.
- Interactive flame-style request timeline and safe span details.
- Evidence-rich Top Opportunities with measured impact and verification guidance.
- Compatible route-cohort comparison instead of misleading two-trace claims.
- Local quotas, retention, migration health, redacted export, privacy tools, and safe uninstall.

### What it does not do

- It does not automatically optimize or repair a site.
- It is not complete PHP, infrastructure, browser, uptime, or Core Web Vitals monitoring.
- It cannot observe page-cache hits that never execute WordPress/PHP.
- It cannot guarantee every query, network call, callback, plugin, or theme function on every stack.
- It is not a vulnerability scanner or security-monitoring service.
- It does not guarantee a particular speed or commercial outcome.

Each trace states what was and was not observed. Start in Safe mode and verify compatibility on the target stack.

### Who should apply

Apply when you can provide:

- A WordPress 6.0+ site on PHP 7.4+ within the published compatibility boundary.
- One repeatable slow dynamic workflow such as checkout, account, search/filtering, editor, authenticated dashboard, REST/AJAX, cron, or another request that reaches WordPress.
- A person authorized to install, deactivate, and remove the plugin and restore the site if required.
- Time for onboarding, one remediation attempt, compatible verification, and brief feedback.

This RC is not suitable when the only symptom is a cached/static page, frontend rendering/network performance, a security review, an active production incident requiring emergency response, or a site where the applicant lacks installation authority.

### CTA

**Apply for the paid WP Flame design-partner release**

Payment occurs only after fit and capacity are confirmed. Applying does not authorize site access, trace review, research recording, testimonials, logos, screenshots, or case-study publication.

## Application form

Collect the minimum needed for qualification:

1. Name, work email, organization, role, and country/region for commercial handling.
2. Agency, WooCommerce/operator, developer, host, or other customer type.
3. Approximate number of WordPress sites managed and requested supported production-site count.
4. Redacted description of the slow dynamic workflow and business/user consequence.
5. Whether the workflow is repeatable in staging, production, or both.
6. WordPress/PHP/database/hosting class, multisite state, and important plugin categories; do not request credentials or a database export.
7. Confirmation that the applicant has or can obtain installation authorization.
8. Confirmation that backups/restoration and an authorized remover exist.
9. Availability for onboarding and compatible after-change verification.
10. Acceptance that this is a paid RC with documented limitations and manual updates.

Do not put trace contents, URLs containing customer identifiers, credentials, cookies, license keys, health details, or sensitive client information in the application form.

## Qualification decision

Accept only when:

- The workflow reaches WordPress/PHP and falls within a supported request population.
- The applicant's desired outcome is performance diagnosis/verification, not an unsupported category.
- The environment and operational owner can follow conservative capture, backup, and removal requirements.
- Cohort capacity is available and the support owner can meet the published response target.

Record `accepted`, `waitlist`, or `declined` plus a bounded reason. Do not accept poor-fit revenue that would force unsupported product claims.

## Pre-payment disclosure block

Before payment, show and require acknowledgement of:

- Exact price, currency, tax treatment, site/support entitlement, term, renewal behavior, and delivery method.
- Product scope and prominent limitations.
- Compatibility/system requirements and conservative production guidance.
- GPLv2 software license and the distinction between software rights and paid delivery/support.
- Terms, privacy, refund/cancellation, support, and update behavior.
- That joining does not grant the seller access or publication rights.

Store the accepted terms/policy versions and transaction evidence in the selected private commerce system, not in this repository.

## Acceptance and authorization sequence

1. Confirm fit and cohort capacity.
2. Send the final offer, policies, price/tax/renewal details, and payment route.
3. Confirm payment and assign opaque partner/site IDs.
4. Obtain written authorization for each named environment before installation.
5. Separately offer trace-review, research-note/recording, quote, logo, screenshot, and case-study permissions; none is required to receive the software unless trace review is separately agreed as part of onboarding.
6. Confirm backups, restoration, installer/remover, and incident contacts.
7. Deliver the official tag-workflow archive, checksum, provenance, notes, compatibility, and rollback material.
8. Follow `docs/validation/M8-RC-PROGRAM.md` for onboarding and evidence.

## Fulfillment message template

Subject: `WP Flame [VERSION] paid RC package and onboarding`

> Your authorized WP Flame paid design-partner package is ready.
>
> Version: `[VERSION]`
> SHA-256: `[OFFICIAL CHECKSUM]`
> Supported environments: `[COMPATIBILITY URL]`
> Release notes and limitations: `[URL]`
> Installation/quickstart: `[URL]`
> Rollback/removal: `[URL]`
>
> Verify the checksum before installation. Begin in Safe mode, confirm capture and storage health, and use Deep only as the documented one-shot diagnostic. This delivery does not authorize us to access your site or receive trace data. Please use `[SUPPORT CHANNEL]` for a WP Flame defect and do not send credentials or unreviewed sensitive exports.

Never send a ZIP before the official exact-tag workflow passes. Never substitute a local dirty-build or synthetic release-simulation checksum.

## Post-onboarding message

Ask five short questions after the first useful trace:

1. What did you believe was slow before using WP Flame?
2. What finding or span evidence changed or confirmed that belief?
3. Which capability/completeness limitation was unclear?
4. What action will you test, and what compatible cohort defines success?
5. Would you return for a second incident, and which ongoing workflow would justify the price?

Record support time and comprehension failures alongside the answers. Ask about public quotes or case studies only through the separate permission process.

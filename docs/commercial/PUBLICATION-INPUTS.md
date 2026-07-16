# Public release-candidate publication inputs

Status: incomplete owner inputs; checkout and public onboarding must remain closed.

This is the shortest decision sheet required to turn the verified engineering candidate into an operable paid design-partner release. Complete it in a private operational system where a value is confidential; record only publishable answers or opaque record references here.

## Fixed RC decisions

- Product: WP Flame `1.3.0-rc.1` performance measurement and diagnosis plugin.
- Offer: `$99/year for up to five production sites` as a reversible founding hypothesis.
- Distribution: one complete directly supplied GPLv2 package; no Community/Pro split in the RC.
- Fulfillment: manual checksum-verified package, release notes, compatibility statement, and rollback instructions.
- Runtime services: no licensing, update, analytics, telemetry, or WP Flame service dependency.
- Scope: performance monitoring and diagnosis only; no vulnerability scanning.
- Refund hypothesis: 14 calendar days on the initial annual software purchase, subject to the completed legal inputs below.
- Cohort: 10–20 paid design partners with separate per-site authorization and separate content/research permissions.

Changing a fixed decision requires updating its architecture/policy source and rerunning the relevant release gate.

## Seller and checkout

| Required input | Owner value / private record reference |
| --- | --- |
| Legal seller name | Pending |
| Trading/product name | Pending |
| Business address and jurisdiction | Pending |
| Public billing/support contact | Pending |
| Settlement currency | Pending |
| Tax registration and invoice requirements | Pending |
| Payment method/provider for first five transactions | Pending |
| Merchant-of-record or seller-of-record responsibility | Pending |
| Renewal behavior and notice timing | Pending |
| Cancellation method | Pending |
| Refund request method and settlement timing | Pending |
| Privacy/data-processing contacts | Pending |

The first transactions may use a manual invoice or hosted checkout. Do not embed a vendor SDK or license client in the RC.

## Public URLs

| Surface | Final URL | Reviewer/date |
| --- | --- | --- |
| Product/limitations page | Pending | Pending |
| Pricing and included support | Pending | Pending |
| Terms of sale/use | Pending | Pending |
| Privacy and data handling | Pending | Pending |
| Refund policy | Pending | Pending |
| Support scope/contact | Pending | Pending |
| Compatibility/requirements | Pending | Pending |
| Mode and measured-overhead evidence | Pending | Pending |
| Installation/quickstart | Pending | Pending |
| Release notes/checksum/provenance | Pending | Pending |
| Responsible disclosure for WP Flame itself | Pending | Pending |

Website copy must not describe automatic optimization, browser/Core Web Vitals monitoring, complete PHP profiling, complete query/network coverage, vulnerability scanning, automatic updates, a Pro edition, or public case-study results that the candidate does not provide.

## Support capacity

| Required decision | Owner value |
| --- | --- |
| Named support inbox/form | Pending |
| Supported days and timezone | Pending |
| Initial response target, explicitly not an uptime SLA | Pending |
| Maximum active founding cohort | Pending, no more than 20 |
| Escalation owner for a plausible P0 | Pending |
| Refund/billing owner | Pending |
| RC update notification channel | Pending |

Measure actual onboarding minutes, support minutes, response time, refunds, and expert-service requests. Do not promise priority support levels that the operator has not demonstrated.

## Repository release authorization

The current working tree contains both roadmap implementation and changes that pre-dated autonomous execution. Before forming the official tag, the repository owner must provide one of these explicit instructions:

- Approve the complete current candidate scope for reviewed commits, while excluding generated `.phpunit.result.cache`, ZIPs, build output, test results, environment homes, and distribution output; or
- Identify the pre-existing paths/hunks that must remain outside the RC commits.

After authorization, review the staged diff, create logical commits, push the review branch, obtain the intended review, merge, create exact tag `v1.3.0-rc.1`, and require the tag workflow's immutable release job to pass. A local dirty-worktree ZIP or synthetic test tag is never the official artifact.

## Publication authorization

Record all of the following before giving the candidate to a production participant:

- Commercial/legal reviewer and review date:
- Engineering release owner and exact tag workflow URL:
- Official archive SHA-256 and provenance record:
- Product owner GO for the paid-RC entry gate:
- First cohort capacity and recruitment source:
- Access-controlled location of payment, authorization, consent, and field-exposure records:

Until these inputs are complete, the correct decision in `docs/validation/M8-GO-NO-GO.md` remains NO-GO.

Use `docs/TERMS-OF-SALE-DRAFT.md` as the operational transaction skeleton. It intentionally leaves seller, tax, renewal, liability, governing-law, and dispute terms incomplete until qualified review.

Use `docs/commercial/RC-OFFER-AND-ONBOARDING.md` for the website offer, application fields, fit decision, pre-payment disclosure, authorization sequence, fulfillment message, and first-use questions.

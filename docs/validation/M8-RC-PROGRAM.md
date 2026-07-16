# M8 paid design-partner release-candidate programme

Status: ready to operate; recruitment and field evidence have not started.

## Outcome

Run `1.3.0-rc.1` with 10–20 paid design partners on real slow dynamic WordPress workflows, close every P0, make every deferred P1 safe and public, and assemble enough evidence for a defensible public-v1 decision.

This programme is for performance measurement and diagnosis only. It does not offer vulnerability scanning, security monitoring, general incident response, or unrestricted access to a participant's site.

## Entry gate

Do not begin production onboarding until:

- The candidate is built from a reviewed, clean exact version tag and its archive, checksum, provenance, and release notes are retained.
- The complete tag CI/release gate passes.
- The seller identity, checkout terms, support channel, refund policy, privacy notice, and programme price are visible before payment.
- The site owner has separately accepted the commercial terms and authorized installation on each named site.
- Any access to trace exports, screenshots, meetings, research notes, quotes, or case-study material has its own explicit scope and consent.
- A restoration path and a person authorized to deactivate/remove the plugin are identified.

## Cohort

Recruit 10–20 paying participants, weighted toward agencies and WooCommerce operators. Each participant must contribute at least one reproducibly slow dynamic workflow such as checkout, account, search, filtering, editor, API, cron, or authenticated dashboard activity. Do not count a page-cache hit as the participant's primary workflow.

Keep a portfolio view across:

- PHP, WordPress, database, hosting, persistent-object-cache, multisite, and traffic conditions.
- Standard and one-shot Deep capture.
- Frontend, REST, AJAX, cron, CLI, GraphQL only where its capability is proven, and authenticated requests.
- Healthy, degraded, and unavailable early-capture states.

## Per-site record

Create one private record per authorized site. Use opaque participant and site IDs in the programme register.

| Field | Required record |
| --- | --- |
| Authorization | Named site, owner/authority, allowed environment, dates, installer/remover |
| Consent | Trace review, screenshots, research notes, quote, logo, and case study recorded separately |
| Environment | Redacted support bundle, hosting class, versions, cache state, multisite state |
| Workflow | Exact steps, request population, expected symptom, business consequence |
| Baseline | Candidate version, mode, cohort definition, sample count, median/p95, completeness |
| Finding | Impact, evidence, confidence, action, limitations, participant understanding |
| Change | Owner, timestamp, deployment scope, rollback, unrelated changes |
| Verification | Compatible cohort, sample count, median/p95 change, error/health checks |
| Operations | Capture overhead, storage/quota, pruning, support minutes, incidents |
| Permission | Exact material approved for public use and approval date |

Never put credentials, cookies, license keys, raw SQL literals, unreviewed URLs, IP addresses, or personal data in the programme register.

## Onboarding sequence

1. Confirm payment and the M7 paid-evidence fields.
2. Record site authorization and each optional consent independently.
3. Confirm backup/restoration and removal ownership.
4. Install the checksum-verified candidate in staging where practical, then follow the approved production scope.
5. Review capture health, early-capture ownership, storage quota, retention, and privacy defaults before tracing the target workflow.
6. Capture a Standard baseline first. Use Deep only as a one-shot, operator-triggered diagnostic when needed.
7. Walk the participant through completeness, capability, Top Opportunities, span evidence, and limitations without coaching them toward a desired answer.
8. Record interpretation errors, unsupported expectations, and support time even when the software behaved as designed.
9. Agree on one independently owned remediation and a compatible before/after verification window.
10. Confirm uninstall/cleanup expectations and the next review date.

## Weekly operating review

Hold one evidence review per active week and record:

- Active, completed, withdrawn, and awaiting-evidence participants.
- Paid transactions, refunds, repeat use, support time, and requested human services.
- Candidate exposure days and any fatal, data-loss, privacy, storage, update, or overhead event.
- Misleading high-confidence conclusions and participant interpretation failures.
- Median/p95 overhead by mode and environment, quota/pruning health, and degraded-mode incidence.
- New blockers, severity changes, fixes, retest ownership, and publicly visible limitations.
- Case-study progress and the exact consent available.

Use `M8-BLOCKER-REGISTER.md` for defects; do not bury a release blocker in meeting notes.

## Minimum evidence for exit

- At least 10 paid participants onboarded and at least 5 meeting the M7 transaction evidence definition.
- At least 3 participants demonstrate repeat use after the initial guided session.
- At least 3 permissioned case studies show compatible before/after measurement and a verified improvement.
- At least 14 consecutive calendar days of RC exposure with no WP Flame-caused fatal error, data loss, privacy exposure, unbounded storage, or unexplained WP Flame-caused regression.
- No open P0.
- Every deferred P1 has a safe workaround or visible degradation, a public limitation, an owner, and explicit go/no-go approval.
- All rows in `M8-GO-NO-GO.md` have current evidence and an accountable approver.

Paid demand, diagnostic usefulness, measurement truth, production safety, and operational supportability are separate gates. Strength in one cannot waive failure in another.

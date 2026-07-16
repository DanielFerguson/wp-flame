# Public v1 go/no-go evidence ledger

Status: **NO-GO — field programme and commercial/release gates are incomplete.**

The roadmap checklist is the release contract. This ledger distinguishes engineering evidence already obtained from field and publication evidence that cannot be simulated locally. A row reaches `pass` only when its linked evidence is current for the exact release candidate.

Allowed states: `pass`, `conditional`, `pending`, `failed`, or `not-applicable` with an approved explanation.

## Measurement truth

| Gate | State | Current evidence / missing proof |
| --- | --- | --- |
| Trace identity, mode, capabilities, start stage, score version, completeness | Conditional | Deterministic/unit/integration evidence exists; confirm across RC field workflows. |
| Missing capture cannot improve score | Conditional | Score v2 and incomplete-capture fixtures exist; field-review misleading conclusions. |
| Lifecycle phases | Conditional | Request-lifecycle fixtures exist; exercise qualifying RC populations. |
| Request population segmentation | Conditional | Classifier, route, cohort tests exist; verify real cohort interpretation. |
| Self-observation excluded | Conditional | Automated/compatibility evidence exists; observe RC overhead and traces. |
| Advertised integration evidence | Pending | Publish only integrations with exact end-to-end evidence on the candidate. |

## Production safety

| Gate | State | Current evidence / missing proof |
| --- | --- | --- |
| Published mode overhead budgets | Conditional | Reproducible benchmark evidence exists; field distributions remain pending. |
| Deep is one-shot/time-boxed | Conditional | Automated browser/unit evidence exists; confirm operator understanding. |
| Storage quota/resumable retention | Conditional | Unit/integration/compatibility evidence exists; field soak remains pending. |
| Mu-plugin ownership/removal | Conditional | Lifecycle/package evidence exists; field managed-host behavior remains pending. |
| Persistence/migration safe degradation | Conditional | Automated failure and lifecycle evidence exists; RC incidents remain pending. |
| Conservative default capture | Conditional | Configuration and browser evidence exists; field support/overhead outcome remains pending. |

## Product usefulness

| Gate | State | Current evidence / missing proof |
| --- | --- | --- |
| Capture without editing settings | Conditional | E2E flow passes; validate unaided participant completion. |
| Findings show impact/evidence/confidence/action | Conditional | Contract/tests pass; validate correct participant interpretation. |
| Safe supporting span details | Conditional | UI and DOM-safety gates pass; field usefulness remains pending. |
| Compatible before/after comparison | Conditional | Cohort comparison tests/E2E pass; three real verified cases pending. |
| Redacted-by-default reports/support bundle | Conditional | Automated redaction tests pass; participant review behavior remains pending. |
| Browser and accessibility workflows | Conditional | Local gate passes; tag CI and field compatibility remain pending. |

## Release and commercial evidence

| Gate | State | Current evidence / missing proof |
| --- | --- | --- |
| Complete CI/compatibility matrix | Pending | Local M6 evidence is green; version-tag CI is configured to run every gate before the release-artifact job, but the reviewed exact tag has not run. |
| No open P0 | Pending | No known field P0, but RC exposure has not started. |
| Deferred P1s are visibly safe | Pending | Populate blocker register during RC. |
| Public benchmark/compatibility/privacy/limitations/support/refund pages | Pending | Repository content/drafts exist; public URLs, seller details, and legal review are absent. |
| Clean tagged checksummed archive | Pending | Determinism and lifecycle pass locally; clean tag publication is absent. |
| Three verified cases | Pending | Use `M8-CASE-STUDY-TEMPLATE.md`; none recorded. |
| Paid demand and packaging | Pending | M7 paid design-partner gate has no transaction evidence. |
| Support capacity and refund behavior | Pending | Measure actual minutes, contacts, requests, and refunds during paid RC. |

## Decision record

- Candidate/version and checksum:
- Decision date:
- Exposure window and active site count:
- Paid/repeat-use evidence:
- Open P0/P1/P2 counts:
- Approved deferred P1 rows:
- Case-study approvals:
- Engineering approver:
- Product/commercial approver:
- Privacy/legal publication reviewer:
- Decision: `GO`, `NO-GO`, or `RETEST`
- Rationale and next evidence date:

No single approver may convert missing field evidence into a pass. A GO requires all P0-class gates to pass and every remaining condition to have the explicit treatment required by the roadmap.

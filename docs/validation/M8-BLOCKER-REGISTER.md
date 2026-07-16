# M8 public-v1 blocker register

Status: open for RC operation. No field incidents have been entered.

## Severity rules

- **P0:** site failure, data loss, privacy exposure, incorrect high-confidence conclusion, update-authenticity failure, unbounded overhead/storage, or unsafe install/upgrade/removal. A P0 cannot be waived.
- **P1:** significant correctness, compatibility, accessibility, or workflow defect with a demonstrably safe workaround or visible degradation path.
- **P2:** improvement that does not invalidate measurement, safety, or the core diagnosis workflow.

If impact or cause is uncertain, use the higher plausible severity until evidence narrows it. Commercial prerequisites and missing field evidence belong in the gate table below, not as software defects.

## Defects

| ID | Severity | State | Opened | Environment/workflow | Evidence and consequence | Workaround/degradation | Owner | Fix/retest evidence | Public limitation | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| — | — | No field incidents recorded | — | — | — | — | — | — | — | — |

Allowed states: `triage`, `confirmed`, `fixing`, `retest`, `closed`, or `deferred-P1`. A closed row must retain the candidate/fix version and independent retest evidence. Never delete a closed blocker.

## Programme and release gates

| Gate | Current state | Required evidence | Owner |
| --- | --- | --- | --- |
| M7 paid design-partner evidence | Blocked on real transactions | Five paid transactions, three repeat users, workflow and objection records, refund/support evidence | Product owner |
| Edition and distribution decision | Pending paid evidence | Approved Community/Pro boundary and chosen channel | Product owner |
| Checkout/licensing/updater | Not implemented by design | Vendor sandbox proof and application assurance follow-up if Pro proceeds | Product + engineering |
| Seller/support/legal publication | Drafts only | Seller identity, contact, terms, privacy, refund, support, tax and checkout configuration reviewed and published | Product owner + qualified adviser |
| Official RC artifact | Local proof only | Reviewed commit, exact tag, full green tag gate, immutable ZIP/checksum/provenance | Release owner |
| Paid RC cohort | Not started | 10–20 authorized paid partners with qualifying workflows | Product owner |
| Field safety window | Not started | 14 consecutive qualifying days and exposure log | Release owner |
| Permissioned cases | Not started | Three independently approved compatible before/after cases | Product owner |

## Triage contract

1. Stop or narrow capture immediately when a plausible P0 affects safety, privacy, storage, or site operation.
2. Preserve redacted evidence, version, mode, capabilities, completeness, request population, environment, and reproduction steps.
3. Separate WP Flame overhead/cause from the site's pre-existing slowness.
4. Assign severity, owner, containment, and the next evidence date.
5. Retest the packaged fix against both the reported workflow and the relevant deterministic/compatibility gate.
6. Record closure or, for a P1 only, the visible degradation and explicit deferral approval.

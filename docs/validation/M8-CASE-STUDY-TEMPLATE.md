# Permissioned performance case-study template

Status: template. Remove instructional text before publication.

## Permission record

- Participant/site opaque IDs:
- Named approver and authority:
- Approved public name, logo, quote, screenshots, metrics, and environment details (record each separately):
- Approval date and approved final document version:
- Withdrawal/contact route:

No material is public merely because the participant joined the paid programme. Keep the signed/recorded authorization outside this repository.

## The workflow and consequence

Describe one real dynamic request population, how a user triggers it, and why its latency matters. State what WP Flame does not measure in this case.

## Measurement contract

| Property | Baseline | Verification |
| --- | --- | --- |
| WP Flame/application version | | |
| Environment snapshot/cohort key | | |
| Request population and exact steps | | |
| Capture mode and capability state | | |
| Completeness/start stage | | |
| Window and sample count | | |
| Median/p95 duration | | |
| Error/result health | | |

Explain any cohort difference. Do not publish a before/after percentage when the populations are incompatible or when unrelated changes prevent attribution.

## Evidence and finding

- Finding, impact, evidence, and confidence shown by WP Flame:
- Source/plugin/theme attribution and how it was independently checked:
- Limitations or missing instrumentation:
- Participant's unaided interpretation:

## Change

Record the independently chosen remediation, owner, deployment time, rollback, and other changes during the window. WP Flame diagnosis does not prove that every proposed change is safe.

## Verified result

Report absolute and relative median/p95 changes, sample counts, error/health outcomes, and the measurement uncertainty. Include WP Flame capture overhead separately from site latency.

## Honest conclusion

State what the evidence supports, what it does not support, the time/support required, and whether the participant returned to use WP Flame again. Avoid universal claims from one site.

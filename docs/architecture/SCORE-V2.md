# Capability-aware Score v2 and finding contract

Status: public v1 scoring contract
Last verified: 2026-07-16

## Score v2

Current captures use `Score::VERSION = 2`. The score evaluates observed request duration, supported external HTTP time, database query count, database-time ratio, and supported slow callbacks.

Each factor persists:

- Factor key, label, display value, and score.
- Configured and applied weight.
- `observed`, `not_requested`, or `unavailable` status.
- Required capability and bounded reason.

Unavailable and intentionally unrequested factors receive no applied weight and display as unknown. Their weight is renormalized across observed factors only; they are never assumed to be perfect. A Safe trace can therefore have a strong score for what Safe actually observed, but it cannot claim an excellent database or callback result.

The snapshot also persists the overall score, grade, score version, observed configured weight, and capability-cohort hash. Historical detail views use that snapshot instead of recalculating under later thresholds. Score v1 traces remain readable and retain their stored overall score; because v1 did not persist factors, the detail view explicitly labels its recalculated factor breakdown as legacy. Score versions and capability cohorts never mix in trends.

## Finding contract

Every current finding exposes:

- Related span IDs.
- Owning source and the captured source version where the environment snapshot can resolve it.
- Measured inclusive impact.
- Evidence count.
- Low, medium, or high confidence.
- Required capture capability.
- Machine-readable action type.
- Plain-language evidence.
- Concrete next action or handoff guidance.
- A repeatable verification instruction.

Database, HTTP, callback, and cache rules do not run for Score v2 traces unless their required capability was captured. Incomplete, trimmed, dropped, or auto-closed traces force finding confidence to low. The incomplete-capture finding explains that missing evidence is unknown.

The v1 performance rule set covers slow unique queries, repeated query fingerprints, observable failed queries, slow or failed external HTTP, blocking early HTTP, slow callbacks, cache evidence/uncertainty, incomplete captures, and configured route budgets. It does not change site code or settings automatically.

## Compatible trends

Daily rollups keep route, request type, instrumentation mode, capability cohort, rollup version, and score version isolated. A committed duration histogram produces bounded p50 and p95 estimates without decoding raw traces or presenting a single average as a distribution.

Every displayed cohort discloses its seven-day period, sample count, complete count, mode, rollup version, score version, and capability identity. Histogram estimates are bucket upper bounds; the overflow bucket represents at least 60 seconds and must not be presented as exact precision.

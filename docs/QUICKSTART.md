# WP Flame quickstart

WP Flame records bounded, server-side evidence for WordPress requests that execute PHP. It does not change the site to make it faster.

## 1. Install and check health

1. Install and activate the WP Flame ZIP in **Plugins**.
2. Open **Tools > WP Flame**.
3. Review the capture-health panel. A managed host may prevent the early mu-plugin from being installed; WP Flame remains usable but labels the early lifecycle boundary unavailable.
4. Leave automatic capture off while learning the product.

## 2. Capture one workflow

1. Choose **Standard** for the first diagnosis.
2. Select the matching request population: frontend, wp-admin, AJAX, REST, or supported GraphQL.
3. Arm one to twenty requests. Use one request for an initial inspection and a repeated cohort for comparison.
4. Perform the slow action in the same browser.
5. Open the exact stored trace from the capture progress panel.

Use **Safe** when compatibility matters more than database detail. Use **Deep** only for one focused request when supported WordPress callback timing is needed; Deep automatically expires.

## 3. Read the result

Read the screen from top to bottom:

1. **Capture report:** what WP Flame observed, what was unavailable, where observation started, and whether the trace is complete for the requested capabilities.
2. **Top opportunities:** measured contribution, supporting evidence, confidence, owner where known, safest next action, and how to verify it.
3. **Technical timeline:** inclusive/self time and the bounded evidence for a selected lifecycle, database, HTTP, callback, or supported GraphQL span.

Unavailable telemetry is unknown, not a healthy zero. A low-confidence finding should be treated as a lead to investigate rather than a final conclusion.

## 4. Verify a change

1. Capture a baseline cohort for one normalized route and request population.
2. Make one controlled change.
3. Capture the same workflow again with the same mode and compatible environment.
4. Use the comparison panel. Ten observations per side are directional; twenty compatible observations per side are required before WP Flame can label the result verified or show p95.
5. Export a redacted local comparison if it needs to be shared.

## 5. Get support evidence

Run:

```bash
wp flame support-bundle > wp-flame-support.json
```

The default bundle excludes site URLs, traces, paths, SQL, HTTP URLs, GraphQL documents, identities, IP addresses, user agents, and license data. `--include-components` adds active plugin/theme identifiers and versions; inspect that output before sharing it.

See [FEATURE-GUIDE.md](FEATURE-GUIDE.md), [MODE-AND-OVERHEAD.md](MODE-AND-OVERHEAD.md), [PRIVACY.md](PRIVACY.md), and [compatibility/MATRIX.md](compatibility/MATRIX.md) for the full operating boundary.

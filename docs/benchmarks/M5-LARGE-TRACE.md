# M5 large-trace rendering benchmark

Recorded: 2026-07-16

## Purpose

Verify that the maximum supported browser model remains bounded and navigable before public-v1 browser automation is added in M6.

## Fixture and method

- Input: 6,000 valid root spans.
- Supported render cap: 5,000 spans.
- Width: 1,000 CSS pixels in the deterministic DOM harness.
- Runtime: Node.js executing `tests/js/flame-graph-oversized.test.js` on the local development machine.
- Command: `WP_FLAME_BENCHMARK=1 node tests/js/flame-graph-oversized.test.js`.
- This measures JavaScript normalization, tree construction, SVG string construction, parsing through the test DOM shim, and listener setup. It is not a browser paint or end-to-end interaction benchmark.

## Results

| Run | Input | Rendered | Elapsed |
| ---: | ---: | ---: | ---: |
| 1 | 6,000 | 5,000 | 22 ms |
| 2 | 6,000 | 5,000 | 30 ms |
| 3 | 6,000 | 5,000 | 26 ms |
| 4 | 6,000 | 5,000 | 21 ms |
| 5 | 6,000 | 5,000 | 21 ms |

Median: 22 ms. Maximum: 30 ms.

The automated assertion also rejects rendering span 5,001 and fails if this deterministic harness exceeds 2,000 ms. Browser paint, focus movement, filtering, and detail inspection remain part of the M5 live-browser gate and the M6 automated browser matrix.

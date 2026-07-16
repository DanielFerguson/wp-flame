#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';

const input = path.resolve(process.cwd(), process.argv[2] || 'build/benchmarks');
const minimumRuns = Number.parseInt(process.env.WP_FLAME_PERFORMANCE_MIN_RUNS || '10', 10);

function newestReport(directory) {
  const files = fs.readdirSync(directory)
    .filter((file) => /^m6-overhead-.*\.json$/.test(file))
    .map((file) => ({ file, modified: fs.statSync(path.join(directory, file)).mtimeMs }))
    .sort((left, right) => right.modified - left.modified);
  if (!files.length) throw new Error(`No M6 benchmark JSON exists in ${directory}`);
  return path.join(directory, files[0].file);
}

const reportPath = fs.statSync(input).isDirectory() ? newestReport(input) : input;
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const failures = [];

function assert(condition, message) {
  if (!condition) failures.push(message);
}

function ceiling(row) {
  if (row.scenario === 'vanilla-home' && row.mode === 'sampled_out') return [50, 100];
  if (row.scenario === 'vanilla-home' && row.mode === 'safe') return [100, 150];
  if (row.scenario === 'vanilla-home' && row.mode === 'standard') return [100, 200];
  if (row.scenario === 'vanilla-home' && row.mode === 'deep') return [150, 250];
  if (row.scenario === 'queries-100') return [250, 500];
  if (row.scenario === 'spans-2000') return [300, 600];
  if (row.scenario === 'maximum-supported-trace') return [500, 1000];
  if (['wp-flame-dashboard', 'woocommerce-admin'].includes(row.scenario)) return [500, 1000];
  return [250, 500];
}

assert(report.schema === 'wp-flame-overhead.v1', 'Unexpected benchmark schema.');
assert(Array.isArray(report.measurements) && report.measurements.length === 31, 'Expected exactly 31 benchmark rows.');

for (const row of report.measurements || []) {
  const label = `${row.scenario}/${row.mode}`;
  assert(row.runs >= minimumRuns, `${label}: ${row.runs} runs is below ${minimumRuns}.`);
  assert(row.failedRequests === 0, `${label}: ${row.failedRequests} request(s) failed.`);
  assert(row.responseStatusEquivalent === true, `${label}: response status changed.`);
  if (!['wp-flame-dashboard', 'woocommerce-admin'].includes(row.scenario)) {
    assert(row.responseBodyEquivalent === true, `${label}: normalized response body changed.`);
  }
  assert(row.truncatedTraces === 0, `${label}: trace-size truncation occurred.`);
  assert(row.averageTraceBytes <= 1250000, `${label}: average trace exceeded 1.25 MB.`);
  assert(row.databaseGrowthBytes <= Math.max(1, row.runs) * 1500000, `${label}: trace JSON growth exceeded 1.5 MB/request.`);

  if (row.mode === 'disabled' || row.mode === 'sampled_out') {
    assert(row.traces === 0, `${label}: expected no stored trace.`);
  }
  if (row.mode !== 'disabled') {
    const [p50, p95] = ceiling(row);
    assert(row.p50DeltaMs <= p50, `${label}: p50 delta ${row.p50DeltaMs} ms exceeds ${p50} ms.`);
    assert(row.p95DeltaMs <= p95, `${label}: p95 delta ${row.p95DeltaMs} ms exceeds ${p95} ms.`);
  }
}

const maximum = (report.measurements || []).find((row) => row.scenario === 'maximum-supported-trace' && row.mode === 'standard');
assert(maximum && maximum.droppedSpans > 0, 'Maximum trace must prove bounded span dropping.');

if (failures.length) {
  process.stderr.write(`Performance budget failed for ${reportPath}:\n- ${failures.join('\n- ')}\n`);
  process.exit(1);
}

process.stdout.write(`Performance budget passed for ${reportPath} (${report.measurements.length} rows, minimum ${minimumRuns} runs).\n`);

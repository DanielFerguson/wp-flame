#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runs = Number.parseInt(process.env.WP_FLAME_BENCHMARK_RUNS || '100', 10);
const warmup = Number.parseInt(process.env.WP_FLAME_BENCHMARK_WARMUP || '10', 10);
if ((!Number.isInteger(runs) || runs < 100) && process.env.WP_FLAME_ALLOW_SHORT_BENCHMARK !== '1') {
  throw new Error('Release evidence requires at least 100 measured runs. Set WP_FLAME_ALLOW_SHORT_BENCHMARK=1 only for harness development.');
}
if (!Number.isInteger(warmup) || warmup < 1) throw new Error('Warm-up count must be a positive integer.');

const baseUrl = process.env.WP_FLAME_BASE_URL || 'http://localhost:8888';
const outputDir = path.resolve(root, process.env.WP_FLAME_BENCHMARK_OUTPUT_DIR || 'build/benchmarks');
const wpEnv = path.resolve(root, 'node_modules/.bin/wp-env');
fs.mkdirSync(outputDir, { recursive: true });

function wp(args) {
  return execFileSync(wpEnv, ['run', 'cli', 'wp', ...args, '--allow-root'], {
    cwd: root,
    encoding: 'utf8',
    env: { ...process.env, WP_ENV_HOME: process.env.WP_FLAME_WP_ENV_HOME || path.resolve(root, '.wp-env-home') },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

function cleanWpOutput(output) {
  return output.split(/\r?\n/).map((line) => line.trim()).filter((line) => line && !line.startsWith('ℹ') && !line.startsWith('✔')).join('\n');
}

function wpValue(args) {
  const lines = cleanWpOutput(wp(args)).split('\n').filter(Boolean);
  return lines.at(-1) || '';
}

function option(name, value) {
  wp(['option', 'update', name, String(value)]);
}

function configure(mode) {
  const values = {
    wp_flame_deep_mode_expires_at: 0,
    wp_flame_trace_audience: 'everyone',
    wp_flame_max_spans: 5000,
    wp_flame_max_trace_bytes: 8388608,
    wp_flame_min_callback_ms: 0.5,
    wp_flame_storage_quota_rows: 1000000,
    wp_flame_storage_quota_mb: 10240,
    wp_flame_capture_paused_reason: '',
  };
  if (mode === 'disabled') {
    values.wp_flame_enabled = 0;
  } else {
    values.wp_flame_enabled = 1;
    values.wp_flame_sample_rate = mode === 'sampled_out' ? 1000000 : 1;
    values.wp_flame_instrumentation_mode = mode === 'deep' ? 'standard' : mode === 'sampled_out' ? 'standard' : mode;
    if (mode === 'deep') values.wp_flame_deep_mode_expires_at = Math.floor(Date.now() / 1000) + 900;
  }
  const encoded = Buffer.from(JSON.stringify(values)).toString('base64');
  wp(['eval', `$values=json_decode(base64_decode('${encoded}'),true); foreach($values as $key=>$value){update_option($key,$value);}`]);
}

function percentile(values, fraction) {
  if (!values.length) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const rank = (sorted.length - 1) * fraction;
  const low = Math.floor(rank);
  const high = Math.ceil(rank);
  return Number((sorted[low] + (sorted[high] - sorted[low]) * (rank - low)).toFixed(3));
}

function normalizeBody(body) {
  return body
    .replace(/\?ver=\d{10}/g, '?ver={timestamp}')
    .replace(/<!-- wp-flame-benchmark-peak:\d+ -->/g, '<!-- wp-flame-benchmark-peak:{bytes} -->')
    .replace(/<!-- wp-flame-benchmark-query-count:\d+ -->/g, '<!-- wp-flame-benchmark-query-count:{count} -->')
    .replace(/_wpnonce=[A-Za-z0-9]+/g, '_wpnonce={nonce}');
}

function checksum(body) {
  return crypto.createHash('sha256').update(normalizeBody(body)).digest('hex');
}

function markerMemory(body) {
  const match = body.match(/<!-- wp-flame-benchmark-peak:(\d+) -->/);
  return match ? Number(match[1]) : null;
}

function markerQueryCount(body) {
  const match = body.match(/<!-- wp-flame-benchmark-query-count:(\d+) -->/);
  return match ? Number(match[1]) : null;
}

function addBenchmarkQuery(url, fixture = '') {
  const parsed = new URL(url, baseUrl);
  parsed.searchParams.set('wp_flame_benchmark', '1');
  if (fixture) parsed.searchParams.set('wp_flame_fixture', fixture);
  return parsed.toString();
}

async function request(scenario) {
  const started = performance.now();
  try {
    const response = await fetch(scenario.url, {
      method: scenario.method || 'GET',
      headers: { connection: 'close', ...(scenario.body ? { 'content-type': scenario.contentType || 'application/json' } : {}) },
      body: scenario.body,
      redirect: 'follow',
    });
    const body = await response.text();
    return { elapsedMs: performance.now() - started, status: response.status, body, memory: markerMemory(body), queryCount: markerQueryCount(body), error: null };
  } catch (error) {
    return { elapsedMs: performance.now() - started, status: 0, body: '', memory: null, queryCount: null, error: String(error) };
  }
}

function traceMaxId() {
  return Number(wpValue(['db', 'query', 'SELECT COALESCE(MAX(id),0) FROM wp_flame_traces', '--skip-column-names'])) || 0;
}

function traceStats(afterId) {
  const sql = `SELECT COUNT(*),COALESCE(AVG(peak_memory),0),COALESCE(AVG(LENGTH(trace_data)),0),COALESCE(SUM(LENGTH(trace_data)),0),COALESCE(SUM(trace_data LIKE '%\"trace_truncated\":true%'),0),COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(trace_data,'$.dropped_span_count')) AS UNSIGNED)),0) FROM wp_flame_traces WHERE id > ${Number(afterId)}`;
  const line = cleanWpOutput(wp(['db', 'query', sql, '--skip-column-names'])).split('\n').find((value) => /^\d+(?:\.\d+)?\t/.test(value));
  if (!line) return { traces: 0, averagePeakMemoryBytes: null, averageTraceBytes: 0, databaseGrowthBytes: 0, truncatedTraces: 0, droppedSpans: 0 };
  const values = line.split('\t').map(Number);
  return {
    traces: values[0], averagePeakMemoryBytes: values[1] || null, averageTraceBytes: values[2],
    databaseGrowthBytes: values[3], truncatedTraces: values[4], droppedSpans: values[5],
  };
}

async function measurePublic(scenario, mode) {
  process.stderr.write(`Measuring ${scenario.name} / ${mode}\n`);
  configure(mode);
  for (let index = 0; index < warmup; index += 1) await request(scenario);
  const beforeId = traceMaxId();
  const samples = [];
  for (let index = 0; index < runs; index += 1) samples.push(await request(scenario));
  const statuses = [...new Set(samples.map((sample) => sample.status))];
  const hashes = [...new Set(samples.map((sample) => checksum(sample.body)))];
  const memories = samples.map((sample) => sample.memory).filter(Number.isFinite);
  const queryCounts = samples.map((sample) => sample.queryCount).filter(Number.isFinite);
  return {
    scenario: scenario.name, mode, runs, warmup,
    p50Ms: percentile(samples.map((sample) => sample.elapsedMs), 0.5),
    p95Ms: percentile(samples.map((sample) => sample.elapsedMs), 0.95),
    observedPeakMemoryP50Bytes: percentile(memories, 0.5),
    referenceQueryCountP50: percentile(queryCounts, 0.5),
    statuses, stableResponseBody: hashes.length === 1, responseChecksum: hashes.length === 1 ? hashes[0] : null,
    failedRequests: samples.filter((sample) => sample.error !== null).length,
    ...traceStats(beforeId),
  };
}

async function login(page) {
  await page.goto(`${baseUrl}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);
}

async function adminRequest(requestContext, scenario) {
  const started = performance.now();
  try {
    const response = await requestContext.get(scenario.url, { failOnStatusCode: false });
    const body = await response.text();
    return { elapsedMs: performance.now() - started, status: response.status(), body, memory: markerMemory(body), error: null };
  } catch (error) {
    return { elapsedMs: performance.now() - started, status: 0, body: '', memory: null, error: String(error) };
  }
}

async function measureAdmin(requestContext, scenario, mode) {
  process.stderr.write(`Measuring ${scenario.name} / ${mode}\n`);
  configure(mode);
  for (let index = 0; index < warmup; index += 1) await adminRequest(requestContext, scenario);
  const beforeId = traceMaxId();
  const samples = [];
  for (let index = 0; index < runs; index += 1) samples.push(await adminRequest(requestContext, scenario));
  const times = samples.map((sample) => sample.elapsedMs);
  const statuses = samples.map((sample) => sample.status);
  const memories = samples.map((sample) => sample.memory).filter(Number.isFinite);
  return {
    scenario: scenario.name, mode, runs, warmup, p50Ms: percentile(times, 0.5), p95Ms: percentile(times, 0.95),
    observedPeakMemoryP50Bytes: percentile(memories, 0.5), statuses: [...new Set(statuses)],
    stableResponseBody: null, responseChecksum: null, failedRequests: samples.filter((sample) => sample.error !== null).length, ...traceStats(beforeId),
  };
}

function environment() {
  return {
    recordedAtUtc: new Date().toISOString(), host: `${os.type()} ${os.release()} ${os.arch()}`,
    cpu: os.cpus()[0]?.model || 'unknown', logicalCpuCount: os.cpus().length, memoryBytes: os.totalmem(),
    node: process.version, wordpress: wpValue(['core', 'version']), php: wpValue(['eval', 'echo PHP_VERSION;']),
    database: wpValue(['db', 'query', 'SELECT VERSION()', '--skip-column-names']),
    activePlugins: cleanWpOutput(wp(['plugin', 'list', '--status=active', '--field=name'])).split('\n').filter(Boolean),
  };
}

function attachDeltas(measurements) {
  const baselines = new Map(measurements.filter((row) => row.mode === 'disabled').map((row) => [row.scenario, row]));
  for (const row of measurements) {
    const baseline = baselines.get(row.scenario);
    row.p50DeltaMs = baseline ? Number((row.p50Ms - baseline.p50Ms).toFixed(3)) : null;
    row.p95DeltaMs = baseline ? Number((row.p95Ms - baseline.p95Ms).toFixed(3)) : null;
    row.responseStatusEquivalent = baseline ? JSON.stringify(row.statuses) === JSON.stringify(baseline.statuses) : null;
    row.responseBodyEquivalent = baseline && baseline.responseChecksum && row.responseChecksum ? baseline.responseChecksum === row.responseChecksum : null;
    row.peakMemoryDeltaBytes = baseline && baseline.observedPeakMemoryP50Bytes && row.observedPeakMemoryP50Bytes
      ? row.observedPeakMemoryP50Bytes - baseline.observedPeakMemoryP50Bytes : null;
  }
}

function markdown(report) {
  const rows = report.measurements.map((row) => `| ${row.scenario} | ${row.mode} | ${row.runs} | ${row.p50Ms} | ${row.p95Ms} | ${row.p50DeltaMs ?? 'n/a'} | ${row.p95DeltaMs ?? 'n/a'} | ${row.peakMemoryDeltaBytes ?? 'n/a'} | ${Math.round(row.averageTraceBytes || 0)} | ${row.databaseGrowthBytes} | ${row.droppedSpans} | ${row.truncatedTraces} | ${row.responseStatusEquivalent ?? 'n/a'} | ${row.responseBodyEquivalent ?? 'dynamic/n/a'} |`).join('\n');
  return `# WP Flame M6 overhead benchmark\n\nRecorded ${report.environment.recordedAtUtc}. Warm-up: ${warmup}; measured runs per row: ${runs}.\n\n| Scenario | Mode | n | p50 ms | p95 ms | p50 delta | p95 delta | peak-memory delta bytes | avg trace bytes | DB growth bytes | dropped spans | truncated | status equivalent | body equivalent |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- |\n${rows}\n\n## Method and limits\n\nSequential local requests include loopback HTTP and application execution. Anonymous response bodies are normalized only for the benchmark memory marker, nonce values, and bbPress's timestamp asset version. Authenticated admin documents use a logged-in Playwright request context, measure server rendering and transfer without loading subresources, and are status-checked only because their nonces and live data are dynamic; M5 separately benchmarks and interacts with the client-rendered maximum trace. CPU deltas are not reported because this Docker Desktop environment does not expose per-request CPU accounting. Captured trace memory is recorded where available; the fixture's near-end footer marker supplies a comparable disabled baseline for HTML requests. REST, AJAX, cron, and GraphQL do not render that marker, so their disabled peak-memory delta is unavailable. Results describe this exact environment and are not a universal hosting guarantee.\n`;
}

const managedOptions = [
  'wp_flame_deep_mode_expires_at', 'wp_flame_trace_audience', 'wp_flame_max_spans',
  'wp_flame_max_trace_bytes', 'wp_flame_min_callback_ms', 'wp_flame_storage_quota_rows',
  'wp_flame_storage_quota_mb', 'wp_flame_capture_paused_reason', 'wp_flame_enabled',
  'wp_flame_sample_rate', 'wp_flame_instrumentation_mode', 'wp_flame_full_query_text',
  'wp_flame_full_http_url', 'wp_flame_full_graphql_query', 'wp_flame_track_users',
  'wp_flame_track_ips', 'wp_flame_track_user_agent',
];
const managedOptionsEncoded = Buffer.from(JSON.stringify(managedOptions)).toString('base64');
const originalOptions = wpValue(['eval', `$names=json_decode(base64_decode('${managedOptionsEncoded}'),true); $state=[]; foreach($names as $name){$sentinel=new stdClass(); $value=get_option($name,$sentinel); $state[$name]=['exists'=>!($value instanceof stdClass),'value'=>$value instanceof stdClass?null:$value];} echo base64_encode(serialize($state));`]);
let restored = false;
function restoreEnvironment() {
  if (restored) return;
  restored = true;
  try {
    wp(['eval', `$state=unserialize(base64_decode('${originalOptions}')); foreach($state as $name=>$item){if($item['exists']){update_option($name,$item['value']);}else{delete_option($name);}} $fixture=WPMU_PLUGIN_DIR . '/wp-flame-benchmark-fixture.php'; if(file_exists($fixture)){unlink($fixture);}`]);
  } catch (error) {
    process.stderr.write(`Unable to restore benchmark options: ${String(error)}\n`);
  }
}
process.on('exit', restoreEnvironment);

const fixture = fs.readFileSync(path.resolve(root, 'tests/Fixtures/wp-flame-benchmark-fixture.php')).toString('base64');
wp(['eval', `file_put_contents(WPMU_PLUGIN_DIR . '/wp-flame-benchmark-fixture.php', base64_decode('${fixture}'));`]);
wp(['plugin', 'activate', 'wp-flame']);
option('wp_flame_full_query_text', 0); option('wp_flame_full_http_url', 0); option('wp_flame_full_graphql_query', 0);
option('wp_flame_track_users', 0); option('wp_flame_track_ips', 0); option('wp_flame_track_user_agent', 0);

const productUrl = wpValue(['eval', '$id=(int)get_posts(["post_type"=>"product","numberposts"=>1,"fields"=>"ids"])[0]; echo get_permalink($id);']);
const elementorUrl = wpValue(['eval', '$ids=get_posts(["post_type"=>"page","numberposts"=>20,"fields"=>"ids"]); foreach($ids as $id){if(get_post_meta($id,"_elementor_edit_mode",true)){echo get_permalink($id); break;}}']);
const checkoutId = wpValue(['option', 'get', 'woocommerce_checkout_page_id']);
const checkoutUrl = wpValue(['eval', `echo get_permalink(${Number(checkoutId) || 0});`]);

const publicScenarios = [
  { name: 'stack-home', url: addBenchmarkQuery('/') },
  { name: 'woocommerce-product', url: addBenchmarkQuery(productUrl || '/') },
  { name: 'woocommerce-checkout', url: addBenchmarkQuery(checkoutUrl || '/checkout/') },
  { name: 'elementor-page', url: addBenchmarkQuery(elementorUrl || '/') },
  { name: 'rest-index', url: addBenchmarkQuery('/wp-json/') },
  { name: 'ajax-unknown-action', method: 'POST', url: addBenchmarkQuery('/wp-admin/admin-ajax.php'), body: 'action=wp_flame_benchmark_missing', contentType: 'application/x-www-form-urlencoded' },
  { name: 'cron-runner', method: 'POST', url: addBenchmarkQuery('/wp-cron.php') },
  { name: 'graphql-operation', method: 'POST', url: addBenchmarkQuery('/graphql'), body: JSON.stringify({ operationName: 'WPFlameBenchmark', query: 'query WPFlameBenchmark { generalSettings { title } }' }) },
  { name: 'queries-100', url: addBenchmarkQuery('/', 'queries-100') },
  { name: 'spans-2000', url: addBenchmarkQuery('/', 'spans-2000') },
  { name: 'maximum-supported-trace', url: addBenchmarkQuery('/', 'spans-maximum') },
];

const resumePath = process.env.WP_FLAME_BENCHMARK_RESUME ? path.resolve(root, process.env.WP_FLAME_BENCHMARK_RESUME) : '';
let measurements = resumePath
  ? JSON.parse(fs.readFileSync(resumePath, 'utf8')).measurements.filter((row) => !['wp-flame-dashboard', 'woocommerce-admin'].includes(row.scenario))
  : [];
if (!resumePath) {
  const activeBeforeVanilla = cleanWpOutput(wp(['plugin', 'list', '--status=active', '--field=name'])).split('\n').filter(Boolean);
  for (const slug of activeBeforeVanilla) {
    if (slug !== 'wp-flame') wp(['plugin', 'deactivate', slug]);
  }
  const vanillaHome = { name: 'vanilla-home', url: addBenchmarkQuery('/') };
  for (const mode of ['disabled', 'sampled_out', 'safe', 'standard', 'deep']) measurements.push(await measurePublic(vanillaHome, mode));
  for (const slug of activeBeforeVanilla) {
    if (slug !== 'wp-flame') wp(['plugin', 'activate', slug]);
  }

  // The restored full compatibility stack represents the claimed integrations.
  measurements.push(await measurePublic(publicScenarios[0], 'disabled'));
  measurements.push(await measurePublic(publicScenarios[0], 'standard'));
  for (const scenario of publicScenarios.slice(1)) {
    measurements.push(await measurePublic(scenario, 'disabled'));
    measurements.push(await measurePublic(scenario, 'standard'));
  }
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await login(page);
const requestContext = page.context().request;
for (const scenario of [
  { name: 'wp-flame-dashboard', url: addBenchmarkQuery('/wp-admin/tools.php?page=wp-flame') },
  { name: 'woocommerce-admin', url: addBenchmarkQuery('/wp-admin/admin.php?page=wc-admin') },
]) {
  measurements.push(await measureAdmin(requestContext, scenario, 'disabled'));
  measurements.push(await measureAdmin(requestContext, scenario, 'standard'));
}
await browser.close();

attachDeltas(measurements);
const report = { schema: 'wp-flame-overhead.v1', methodology: { runs, warmup, sequential: true }, environment: environment(), measurements };
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const jsonPath = path.join(outputDir, `m6-overhead-${stamp}.json`);
const markdownPath = path.join(outputDir, `m6-overhead-${stamp}.md`);
fs.writeFileSync(jsonPath, `${JSON.stringify(report, null, 2)}\n`);
fs.writeFileSync(markdownPath, markdown(report));
restoreEnvironment();
process.stdout.write(`${jsonPath}\n${markdownPath}\n`);

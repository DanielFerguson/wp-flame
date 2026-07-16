#!/usr/bin/env node

import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const wpEnv = path.resolve(root, 'node_modules/.bin/wp-env');
const baseUrl = process.env.WP_FLAME_BASE_URL || 'http://localhost:8888';

function wp(args) {
  return execFileSync(wpEnv, ['run', 'cli', 'wp', ...args, '--allow-root'], {
    cwd: root,
    encoding: 'utf8',
    env: { ...process.env, WP_ENV_HOME: process.env.WP_FLAME_WP_ENV_HOME || path.resolve(root, '.wp-env-home') },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

function clean(output) {
  return output.split(/\r?\n/).map((line) => line.trim()).filter((line) => line && !line.startsWith('ℹ') && !line.startsWith('✔')).join('\n');
}

function value(args) {
  return clean(wp(args)).split('\n').filter(Boolean).at(-1) || '';
}

if (value(['plugin', 'is-active', 'query-monitor']) !== '') {
  // `wp plugin is-active` is intentionally silent on success.
}

const options = ['wp_flame_enabled', 'wp_flame_trace_audience', 'wp_flame_sample_rate', 'wp_flame_instrumentation_mode'];
const names = Buffer.from(JSON.stringify(options)).toString('base64');
const original = value(['eval', `$names=json_decode(base64_decode('${names}'),true); $state=[]; foreach($names as $name){$sentinel=new stdClass(); $item=get_option($name,$sentinel); $state[$name]=['exists'=>!($item instanceof stdClass),'value'=>$item instanceof stdClass?null:$item];} echo base64_encode(serialize($state));`]);
const queryMonitorDropIn = value(['eval', '$file=WP_CONTENT_DIR . "/db.php"; echo file_exists($file) ? base64_encode(file_get_contents($file)) : "";']);
let restored = false;
let queryMonitorDeactivated = false;
function restore() {
  if (restored) return;
  restored = true;
  if (queryMonitorDeactivated) {
    if (queryMonitorDropIn) wp(['eval', `file_put_contents(WP_CONTENT_DIR . '/db.php', base64_decode('${queryMonitorDropIn}'));`]);
    wp(['plugin', 'activate', 'query-monitor']);
    queryMonitorDeactivated = false;
  }
  wp(['eval', `$state=unserialize(base64_decode('${original}')); foreach($state as $name=>$item){if($item['exists']){update_option($name,$item['value']);}else{delete_option($name);}}`]);
}
process.on('exit', restore);

wp(['eval', 'update_option("wp_flame_enabled",1); update_option("wp_flame_trace_audience","everyone"); update_option("wp_flame_sample_rate",1); update_option("wp_flame_instrumentation_mode","standard");']);
const beforeId = Number(value(['db', 'query', 'SELECT COALESCE(MAX(id),0) FROM wp_flame_traces', '--skip-column-names'])) || 0;

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
page.setDefaultTimeout(120000);
page.setDefaultNavigationTimeout(120000);
await page.goto(`${baseUrl}/wp-login.php`);
await page.locator('#user_login').fill('admin');
await page.locator('#user_pass').fill('password');
await Promise.all([
  page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'), { waitUntil: 'domcontentloaded' }),
  page.locator('#wp-submit').click(),
]);

const response = await page.goto(`${baseUrl}/?wp_flame_benchmark=1&wp_flame_fixture=queries-100&wp_flame_accuracy_reference=1`, { waitUntil: 'domcontentloaded' });
const html = await page.content();
const marker = html.match(/wp-flame-benchmark-query-count:(\d+)/);
const queryMonitor = await page.locator('#wp-admin-bar-query-monitor').innerText().catch(() => '');
const queryMonitorQueries = await page.locator('#wp-admin-bar-query-monitor .qm-queries').innerText().catch(() => '');

function traceAfter(afterId) {
  const sql = `SELECT trace_id,query_count,total_ms,JSON_UNQUOTE(JSON_EXTRACT(trace_data,'$.capabilities.database.status')),JSON_UNQUOTE(JSON_EXTRACT(trace_data,'$.capabilities.database.reason')) FROM wp_flame_traces WHERE id > ${afterId} AND request_type='frontend' ORDER BY id ASC LIMIT 1`;
  const trace = clean(wp(['db', 'query', sql, '--skip-column-names'])).split('\n').find((line) => /^[a-f0-9-]{36}\t/i.test(line)) || '';
  const [traceId = '', queryCount = '', observedDurationMs = '', databaseStatus = '', databaseReason = ''] = trace.split('\t');
  return {
    traceId,
    queryCount: queryCount === '' ? null : Number(queryCount),
    observedDurationMs: observedDurationMs === '' ? null : Number(observedDurationMs),
    databaseStatus,
    databaseReason,
  };
}

const withQueryMonitorTrace = traceAfter(beforeId);

wp(['plugin', 'deactivate', 'query-monitor']);
queryMonitorDeactivated = true;
wp(['eval', '$file=WP_CONTENT_DIR . "/db.php"; if(file_exists($file)){unlink($file);}']);
const withoutQueryMonitorBeforeId = Number(value(['db', 'query', 'SELECT COALESCE(MAX(id),0) FROM wp_flame_traces', '--skip-column-names'])) || 0;
const withoutQueryMonitorResponse = await page.goto(`${baseUrl}/?wp_flame_benchmark=1&wp_flame_fixture=queries-100&wp_flame_accuracy_reference=2`, { waitUntil: 'domcontentloaded' });
const withoutQueryMonitorHtml = await page.content();
const withoutQueryMonitorMarker = withoutQueryMonitorHtml.match(/wp-flame-benchmark-query-count:(\d+)/);
const withoutQueryMonitorTrace = traceAfter(withoutQueryMonitorBeforeId);
await browser.close();

if (queryMonitorDropIn) wp(['eval', `file_put_contents(WP_CONTENT_DIR . '/db.php', base64_decode('${queryMonitorDropIn}'));`]);
wp(['plugin', 'activate', 'query-monitor']);
queryMonitorDeactivated = false;

restore();

const result = {
  recordedAtUtc: new Date().toISOString(),
  withQueryMonitor: {
    responseStatus: response?.status() || 0,
    wordpressQueryCountAtFooter: marker ? Number(marker[1]) : null,
    queryMonitorQueries,
    queryMonitorLabel: queryMonitor.replace(/\s+/g, ' ').trim(),
    wpFlame: withQueryMonitorTrace,
  },
  withCoreWpdb: {
    responseStatus: withoutQueryMonitorResponse?.status() || 0,
    wordpressQueryCountAtFooter: withoutQueryMonitorMarker ? Number(withoutQueryMonitorMarker[1]) : null,
    wpFlame: withoutQueryMonitorTrace,
  },
};

if (
  result.withQueryMonitor.responseStatus !== 200
  || result.withQueryMonitor.wordpressQueryCountAtFooter === null
  || result.withQueryMonitor.queryMonitorLabel === ''
  || result.withQueryMonitor.wpFlame.traceId === ''
  || result.withCoreWpdb.responseStatus !== 200
  || result.withCoreWpdb.wordpressQueryCountAtFooter === null
  || result.withCoreWpdb.wpFlame.traceId === ''
  || result.withCoreWpdb.wpFlame.databaseStatus !== 'captured'
  || result.withCoreWpdb.wpFlame.queryCount < 100
) {
  process.stderr.write(`${JSON.stringify(result, null, 2)}\n`);
  process.exit(1);
}

process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

test.describe.configure({ mode: 'serial' });

const dashboardPath = '/wp-admin/tools.php?page=wp-flame';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php')),
    page.locator('#wp-submit').click(),
  ]);
  await page.goto('/wp-admin/');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

async function armSession(page, { mode = 'standard', phase = 'observation', count = 1 } = {}) {
  await page.goto(dashboardPath);
  const title = mode === 'deep' ? 'One Deep trace' : 'Guided Standard session';
  const form = page.locator('form.wp-flame-capture-option').filter({ hasText: title });
  await expect(form).toHaveCount(1);
  await form.getByLabel('Workflow type').selectOption('frontend');
  if (mode === 'standard') {
    await form.getByLabel('Requests to capture').fill(String(count));
    await form.getByLabel('Purpose').selectOption(phase);
  }
  await form.getByRole('button', { name: mode === 'deep' ? 'Arm one Deep trace' : 'Arm capture session' }).click({ force: true });
  await expect(page.getByText('Capture armed. Open the page or workflow in this browser now')).toBeVisible();

  for (let request = 0; request < count; request += 1) {
    await page.goto('/');
  }
  await page.goto(dashboardPath);

  const card = page.locator('article.wp-flame-session').first();
  await expect(card).toContainText(`${count} of ${count} stored · complete`);
  await expect(card).toContainText('frontend · GET /');
  const href = await card.getByRole('link', { name: 'Open latest stored trace' }).getAttribute('href');
  expect(href).toBeTruthy();
  return { card, href };
}

function wpEnv(args) {
  const binary = path.resolve(__dirname, '../../node_modules/.bin/wp-env');
  return execFileSync(binary, ['run', 'cli', 'wp', ...args, '--allow-root'], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    env: {
      ...process.env,
      WP_ENV_HOME: process.env.WP_FLAME_WP_ENV_HOME || path.resolve(__dirname, '../../.wp-env-home'),
    },
  });
}

function wpTablePrefix() {
  const output = wpEnv(['db', 'prefix']);
  const prefix = output.split(/\r?\n/).map((line) => line.trim()).find((line) => /^[A-Za-z0-9_]+_$/.test(line));
  if (!prefix) throw new Error(`Could not resolve wp-env table prefix from: ${output}`);
  return prefix;
}

test.beforeAll(() => {
  // Each browser run must establish its own complete early-capture fixture;
  // an already-active plugin does not rerun WordPress activation hooks.
  wpEnv(['plugin', 'deactivate', 'wp-flame']);
  wpEnv(['plugin', 'activate', 'wp-flame']);
  wpEnv(['eval', 'if (!defined("WP_FLAME_MU_VERSION") || WP_FLAME_MU_VERSION !== WP_FLAME_VERSION) { throw new RuntimeException("WP Flame early loader is missing or stale after activation."); }']);
});

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('current-page admin-bar capture stores an exact trace', async ({ page }) => {
  await page.goto('/');
  const capture = page.locator('#wp-admin-bar-wp-flame-trace > .ab-item');
  await expect(capture).toBeVisible();
  await capture.click();
  await page.waitForLoadState('domcontentloaded');
  await page.goto(dashboardPath);
  const result = page.getByRole('link', { name: /View flame graph/ });
  await expect(result).toBeVisible();
  await expect(result).toHaveAttribute('href', /trace_id=/);
});

test('guided Standard capture supports accessible diagnosis and small screens @accessibility', async ({ page }) => {
  const { href } = await armSession(page, { mode: 'standard', phase: 'observation', count: 1 });
  await page.goto(href);

  await expect(page.getByRole('region', { name: 'Capture report' })).toContainText('Complete for requested capabilities');
  await expect(page.getByRole('region', { name: 'Top opportunities' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Technical timeline' })).toBeVisible();

  const selectableSpan = page.locator('.wp-flame-span[tabindex="0"]').first();
  await expect(selectableSpan).toBeVisible();
  await selectableSpan.press('Enter');
  await expect(page.locator('#wp-flame-span-detail')).toContainText('Inclusive time');
  await expect(page.locator('#wp-flame-span-detail')).toContainText('Self time');

  const search = page.getByRole('searchbox', { name: 'Search spans' });
  await search.fill('no-span-can-match-this-value');
  await expect(page.getByRole('status')).toContainText(/^0 of \d+ spans match$/);
  const hidden = page.locator('.wp-flame-span[aria-hidden="true"]');
  expect(await hidden.count()).toBeGreaterThan(0);
  await expect(hidden.first()).toHaveAttribute('tabindex', '-1');
  await search.fill('');

  const accessibility = await new AxeBuilder({ page })
    .include('.wp-flame-capture-report')
    .include('.wp-flame-top-opportunities')
    .include('.wp-flame-graph-tools')
    .withTags(['wcag2a', 'wcag2aa'])
    .analyze();
  expect(accessibility.violations).toEqual([]);

  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.getByRole('region', { name: 'Technical timeline' })).toBeVisible();
  const timelineBounds = await page.locator('.wp-flame-graph-tools').evaluate((element) => ({
    client: element.clientWidth,
    scroll: element.scrollWidth,
  }));
  expect(timelineBounds.scroll).toBeLessThanOrEqual(timelineBounds.client + 1);
});

test('one-shot Deep capture completes once and reports callback capability', async ({ page }) => {
  const { href, card } = await armSession(page, { mode: 'deep', count: 1 });
  await expect(card).toContainText('Observation · Deep');
  await page.goto(href);
  await expect(page.getByRole('region', { name: 'Capture report' })).toContainText('deep');
  await expect(page.getByRole('region', { name: 'Capture report' })).toContainText('Callbacks: Captured');

  await page.goto('/');
  await page.goto(dashboardPath);
  await expect(page.locator('article.wp-flame-session').first()).toContainText('1 of 1 stored · complete');
});

test('single-request cohorts stay insufficient and export redacted local JSON', async ({ page }) => {
  await armSession(page, { mode: 'standard', phase: 'baseline', count: 1 });
  await armSession(page, { mode: 'standard', phase: 'after', count: 1 });

  const baseline = page.getByLabel('Baseline session');
  const after = page.getByLabel('After session');
  await baseline.selectOption(await baseline.locator('option').nth(1).getAttribute('value'));
  await after.selectOption(await after.locator('option').nth(1).getAttribute('value'));
  await page.getByRole('button', { name: 'Compare compatible evidence' }).click({ force: true });

  const comparison = page.getByRole('region', { name: 'Insufficient comparison' });
  await expect(comparison).toContainText('1 baseline · 1 after');
  await expect(comparison).toContainText('Too few observations');
  await expect(comparison.getByRole('heading', { name: 'Observed response distribution' })).toBeVisible();
  await expect(comparison.getByRole('heading', { name: 'Capture capability comparison' })).toBeVisible();
  await expect(comparison.getByRole('checkbox')).not.toBeChecked();

  const downloadPromise = page.waitForEvent('download');
  await comparison.getByRole('button', { name: 'Download local JSON report' }).click({ force: true });
  const download = await downloadPromise;
  const downloadPath = await download.path();
  const report = JSON.parse(fs.readFileSync(downloadPath, 'utf8'));
  expect(report.status).toBe('insufficient');
  expect(report.report.redacted_by_default).toBe(true);
  expect(report.report.includes_sensitive_fields).toBe(false);
  expect(report.report.excludes).toContain('raw_sql');
  expect(report).not.toHaveProperty('raw_spans');
  expect(report.baseline).not.toHaveProperty('spans');
  expect(report.after).not.toHaveProperty('spans');
});

test('capture persistence failure remains visible and recoverable', async ({ page }) => {
  await page.goto(dashboardPath);
  const prefix = wpTablePrefix();
  const table = `${prefix}flame_sessions`;
  const temporary = `${table}_e2e_${process.pid}`;
  wpEnv(['db', 'query', `RENAME TABLE ${table} TO ${temporary}`]);
  try {
    const form = page.locator('form.wp-flame-capture-option').filter({ hasText: 'Guided Standard session' });
    await form.getByLabel('Requests to capture').fill('1');
    await form.getByRole('button', { name: 'Arm capture session' }).click({ force: true });
    await expect(page.getByText('The capture session could not be saved. Check storage health, then retry.')).toBeVisible();
  } finally {
    wpEnv(['db', 'query', `RENAME TABLE ${temporary} TO ${table}`]);
  }
  await page.reload();
  await expect(page.getByRole('region', { name: 'Find what is slowing a workflow' })).toBeVisible();
});

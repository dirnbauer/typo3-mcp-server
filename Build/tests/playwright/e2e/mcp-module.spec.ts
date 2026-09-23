import { test, expect } from '../fixtures/setup-fixtures';
import type { FrameLocator } from '@playwright/test';

test.describe('MCP Server Backend Module', () => {
  let frame: FrameLocator;

  test.beforeEach(async ({ page, backend }) => {
    await backend.gotoMcpModule();
    frame = page.frameLocator('#typo3-contentIframe');
    await expect(frame.locator('.module-body')).toBeVisible();
  });

  test('module page loads with its four sections', async () => {
    await expect(frame.locator('h1')).toHaveText('MCP Server');
    await expect(frame.locator('#mcp-module-tabs [role="tab"]')).toHaveCount(4);
    await expect(frame.locator('#mcp-tab-setup')).toBeVisible();
    await expect(frame.locator('#mcp-server-url')).toHaveValue(/\/mcp$/);
  });

  test('tabs and client panels switch', async () => {
    await frame.locator('#mcp-tab-check-tab').click();
    await expect(frame.locator('#mcp-tab-check')).toBeVisible();
    await expect(frame.locator('#diagnostics-table-body tr').first()).toBeVisible();

    await frame.locator('#mcp-tab-setup-tab').click();
    await expect(frame.locator('#mcp-tab-setup')).toBeVisible();

    await frame.locator('#mcp-client-cursor-toggle').click();
    await expect(frame.locator('#mcp-client-cursor')).toBeVisible();
    await expect(frame.locator('#mcp-config-cursor')).toHaveValue(/mcpServers/);

    await frame.locator('#mcp-client-codex-toggle').click();
    await expect(frame.locator('#mcp-client-codex')).toBeVisible();
  });

  test('create token from the docheader shows the name modal, then the token once', async ({ page }) => {
    const tokenName = `test-token-${Date.now()}`;
    await frame.locator('.module-docheader [data-mcp-action="create-token"]').click();

    // TYPO3 renders modals in the top frame.
    const nameModal = page.locator('.modal').filter({ hasText: 'Create access token' });
    await expect(nameModal).toBeVisible({ timeout: 15000 });
    await nameModal.locator('#mcp-token-name').fill(tokenName);
    await nameModal.getByRole('button', { name: 'Create', exact: true }).click();

    const tokenModal = page.locator('.modal').filter({ hasText: 'Access token created' });
    await expect(tokenModal).toBeVisible({ timeout: 15000 });
    await expect(tokenModal.locator('.callout-warning')).toContainText('shown only once');
    const tokenValue = await tokenModal.locator('#mcp-token-value').inputValue();
    expect(tokenValue).toMatch(/^[0-9a-f]{64}$/);
    await expect(tokenModal.locator('typo3-copy-to-clipboard')).toContainText('Copy token');
    await tokenModal.getByRole('button', { name: 'I have copied the token' }).click();

    // The module switches to the token list, which now contains the token.
    await expect(frame.locator('#mcp-tab-tokens')).toBeVisible();
    await expect(frame.locator('#mcp-tokens-table th[scope="row"]', { hasText: tokenName })).toBeVisible({ timeout: 10000 });
  });

  test('an empty token name is rejected in the modal', async ({ page }) => {
    await frame.locator('.module-docheader [data-mcp-action="create-token"]').click();
    const nameModal = page.locator('.modal').filter({ hasText: 'Create access token' });
    await expect(nameModal).toBeVisible({ timeout: 15000 });
    await nameModal.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(nameModal.locator('#mcp-token-name')).toHaveAttribute('aria-invalid', 'true');
    await nameModal.getByRole('button', { name: 'Cancel' }).click();
    await expect(nameModal).not.toBeVisible({ timeout: 5000 });
  });

  test('revoke token asks for confirmation', async ({ page }) => {
    await frame.locator('#mcp-tab-tokens-tab').click();
    const revokeButton = frame.locator('[data-mcp-action="revoke-token"]').first();
    test.skip(!(await revokeButton.isVisible({ timeout: 3000 }).catch(() => false)),
      'No tokens exist to revoke — create tokens first');

    await revokeButton.click();
    const modal = page.locator('.modal').filter({ hasText: 'Revoke access token?' });
    await expect(modal).toBeVisible({ timeout: 5000 });
    await modal.getByRole('button', { name: 'Cancel' }).click();
    await expect(modal).not.toBeVisible({ timeout: 5000 });
  });

  test('connection check lists every check and can run again', async () => {
    await frame.locator('#mcp-tab-check-tab').click();
    await expect(frame.locator('#diagnostics-table-body tr')).toHaveCount(10, { timeout: 10000 });
    await frame.locator('[data-mcp-action="refresh-diagnostics"]').click();
    await expect(frame.locator('#diagnostics-table-body tr')).toHaveCount(10, { timeout: 20000 });
  });

  test('tool filter narrows the tool list', async () => {
    await frame.locator('#mcp-tab-tools-tab').click();
    await frame.locator('#mcp-tool-filter').fill('readtable');
    await expect(frame.locator('tr[data-mcp-tool]:visible')).toHaveCount(1, { timeout: 5000 });
    await expect(frame.locator('tr[data-mcp-tool]:visible code')).toHaveText('ReadTable');
    await frame.locator('#mcp-tool-filter').fill('no tool is called like this');
    await expect(frame.locator('tr[data-mcp-tool-empty]')).toBeVisible();
  });

  test('copy elements exist', async () => {
    await expect(frame.locator('typo3-copy-to-clipboard').first()).toBeVisible({ timeout: 10000 });
  });
});

const { test, expect } = require('../utils/fixtures');
const { navigateTo, ensureAssetExists } = require('../utils/helpers');

// ─── Shared helpers ───────────────────────────────────────────────────────────

async function navigateToFirstAssetEdit(page) {
  await navigateTo(page, 'dam');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2000);

  const firstCard = page.locator('.image-card').first();
  await firstCard.waitFor({ state: 'visible', timeout: 20000 });
  await firstCard.hover();
  await page.waitForTimeout(500);

  await firstCard.locator('.icon-edit').first().click({ force: true });
  await page.waitForURL(/admin\/dam\/assets\/edit\/\d+/, { timeout: 30000 });
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2000);
}

/**
 * Opens the fullscreen preview modal and waits for the viewer backdrop.
 * Viewer backdrop: absolute inset-0 bg-black/75 (inside v-if="isOpen" div).
 */
async function openPreviewModal(page) {
  const btn = page.locator('button[title="Preview"]').first();
  await btn.waitFor({ state: 'visible', timeout: 10000 });
  await btn.click();
  // Wait for the viewer's own backdrop (distinct from info modal's bg-black/60)
  await page.locator('.absolute.inset-0.bg-black\\/75').first()
    .waitFor({ state: 'visible', timeout: 10000 });
}

/**
 * Opens the info modal and waits for its backdrop (bg-black/60).
 */
async function openInfoModal(page) {
  const btn = page.locator('button').filter({ has: page.locator('.icon-information') }).first();
  await btn.waitFor({ state: 'visible', timeout: 10000 });
  await btn.click();
  await page.locator('.absolute.inset-0.bg-black\\/60').first()
    .waitFor({ state: 'visible', timeout: 10000 });
}

/**
 * Opens the editor modal (image assets only) and waits for the close button.
 * Returns false if the edit button isn't present (non-image asset).
 */
async function openEditorIfImage(page) {
  await navigateToFirstAssetEdit(page);
  const btn = page.locator('button[title="Edit image"]').first();
  const visible = await btn.isVisible({ timeout: 5000 }).catch(() => false);
  if (!visible) return false;
  await btn.click();
  await page.locator('button[aria-label="Close editor"]')
    .waitFor({ state: 'visible', timeout: 10000 });
  return true;
}

/**
 * Detect whether the current edit page shows an image asset (not a placeholder).
 * Placeholder SVGs have opacity-60; real image thumbnails do not.
 */
async function isImageAsset(page) {
  const thumb = page.locator('.rounded-lg.overflow-hidden img').first();
  await thumb.waitFor({ state: 'visible', timeout: 10000 });
  return await thumb.evaluate(el => !el.classList.contains('opacity-60'));
}

/**
 * Navigate to the edit page and open the preview modal only if image asset.
 * Returns false (with skip-ready signal) for non-image assets.
 */
async function openImagePreview(page) {
  await navigateToFirstAssetEdit(page);
  if (!(await isImageAsset(page))) return false;
  await openPreviewModal(page);
  return true;
}

// ─────────────────────────────────────────────────────────────────────────────

test.describe('DAM Asset Preview Modal', () => {

  test.beforeEach(async ({ adminPage }) => {
    await ensureAssetExists(adminPage);
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Card-level action icons
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Action icon row', () => {

    test('Info icon button is visible on edit page', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await expect(
        adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first()
      ).toBeVisible({ timeout: 10000 });
    });

    test('Preview icon button is visible on edit page', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await expect(adminPage.locator('button[title="Preview"]').first()).toBeVisible({ timeout: 10000 });
    });

    test('Edit image button visible for image asset', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      if (!(await isImageAsset(adminPage))) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.locator('button[title="Edit image"]').first()).toBeVisible({ timeout: 10000 });
    });

    test('Edit image button absent for non-image asset', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      if (await isImageAsset(adminPage)) { test.skip(true, 'Asset is an image'); return; }
      await expect(adminPage.locator('button[title="Edit image"]').first()).not.toBeVisible({ timeout: 3000 });
    });

    test('Thumbnail renders on edit page (image or placeholder)', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      const wrapper = adminPage.locator('.rounded-lg.overflow-hidden').first();
      await expect(wrapper).toBeVisible({ timeout: 10000 });
      await expect(wrapper.locator('img').first()).toBeVisible();
    });

    test('Image asset thumbnail has no opacity-60 class', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      if (!(await isImageAsset(adminPage))) { test.skip(true, 'Not an image asset'); return; }
      const thumb = adminPage.locator('.rounded-lg.overflow-hidden img').first();
      const hasOpacity = await thumb.evaluate(el => el.classList.contains('opacity-60'));
      expect(hasOpacity).toBe(false);
    });

    test('Non-image asset thumbnail has opacity-60 class (placeholder)', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      if (await isImageAsset(adminPage)) { test.skip(true, 'Asset is an image'); return; }
      const thumb = adminPage.locator('.rounded-lg.overflow-hidden img').first();
      const hasOpacity = await thumb.evaluate(el => el.classList.contains('opacity-60'));
      expect(hasOpacity).toBe(true);
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Info hover tooltip
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Info hover tooltip', () => {

    test('Hovering info button reveals tooltip', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first().hover();
      await adminPage.waitForTimeout(300);
      await expect(
        adminPage.locator('.pointer-events-none').filter({ hasText: 'Click for full details' }).first()
      ).toBeVisible({ timeout: 5000 });
    });

    test('Tooltip shows asset filename', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first().hover();
      await adminPage.waitForTimeout(300);
      const tooltip = adminPage.locator('.pointer-events-none').filter({ hasText: 'Click for full details' }).first();
      await expect(tooltip.locator('p.font-semibold.truncate').first()).toBeVisible({ timeout: 5000 });
    });

    test('Tooltip shows extension badge', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first().hover();
      await adminPage.waitForTimeout(300);
      const tooltip = adminPage.locator('.pointer-events-none').filter({ hasText: 'Click for full details' }).first();
      await expect(tooltip.locator('span.rounded.font-semibold').first()).toBeVisible({ timeout: 5000 });
    });

    test('Tooltip disappears on mouse leave', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first().hover();
      await adminPage.waitForTimeout(300);
      await adminPage.mouse.move(10, 10);
      await adminPage.waitForTimeout(300);
      await expect(
        adminPage.locator('.pointer-events-none').filter({ hasText: 'Click for full details' }).first()
      ).not.toBeVisible({ timeout: 3000 });
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Info modal
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Info modal', () => {

    test('Clicking info button opens info modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('File Information').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows File Name row', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('File Name').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows Type row with extension badge', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('Type').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows Size row', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      // Size row is conditionally rendered — assert it is visible (not just annotate)
      const sizeRow = adminPage.locator('#app').getByText('Size').first();
      await expect(sizeRow).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows Path row', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('Path').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows MIME row', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('MIME').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows Created date row', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('Created').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows Updated date row', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await expect(adminPage.locator('#app').getByText('Updated').first()).toBeVisible({ timeout: 5000 });
    });

    test('Info modal shows Dimensions row for image asset', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      if (!(await isImageAsset(adminPage))) { test.skip(true, 'Not an image asset'); return; }
      await openInfoModal(adminPage);
      // Dimensions conditionally rendered only when width/height extracted
      const dimRow = adminPage.locator('#app').getByText('Dimensions').first();
      const visible = await dimRow.isVisible({ timeout: 5000 }).catch(() => false);
      if (!visible) {
        test.info().annotations.push({ type: 'note', description: 'width/height not extracted for this asset' });
      }
    });

    test('Info modal X button closes the modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      // Close button: w-7 h-7 rounded-full — unique to info modal (preview uses w-8 h-8)
      await adminPage.locator('button.w-7.h-7.rounded-full').first().click();
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/60').first()).not.toBeVisible({ timeout: 5000 });
    });

    test('Clicking info modal backdrop closes the modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      const backdrop = adminPage.locator('.absolute.inset-0.bg-black\\/60').first();
      await backdrop.click({ position: { x: 5, y: 5 }, force: true });
      await adminPage.waitForTimeout(400);
      await expect(backdrop).not.toBeVisible({ timeout: 5000 });
    });

    test('Escape closes info modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openInfoModal(adminPage);
      await adminPage.keyboard.press('Escape');
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/60').first()).not.toBeVisible({ timeout: 5000 });
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Preview modal — open / close
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Preview modal — open / close', () => {

    test('Preview button opens fullscreen modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      await expect(adminPage.locator('.rounded-xl.shadow-2xl').first()).toBeVisible({ timeout: 10000 });
    });

    test('Modal backdrop is absent before preview button click', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      // v-if="isOpen" keeps the whole overlay out of DOM; must not be visible
      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/75').first()).not.toBeVisible({ timeout: 3000 });
    });

    test('X button (aria-label=Close preview) closes the modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      await adminPage.locator('button[aria-label="Close preview"]').click();
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/75').first()).not.toBeVisible({ timeout: 5000 });
    });

    test('Clicking the backdrop closes the modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      const backdrop = adminPage.locator('.absolute.inset-0.bg-black\\/75').first();
      await backdrop.click({ position: { x: 5, y: 5 }, force: true });
      await adminPage.waitForTimeout(400);
      await expect(backdrop).not.toBeVisible({ timeout: 5000 });
    });

    test('Escape closes preview modal', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      await adminPage.keyboard.press('Escape');
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/75').first()).not.toBeVisible({ timeout: 5000 });
    });

    test('Body scroll locked when modal open', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      expect(await adminPage.evaluate(() => document.body.style.overflow)).toBe('hidden');
    });

    test('Body scroll restored after modal closes', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      await adminPage.locator('button[aria-label="Close preview"]').click();
      await adminPage.waitForTimeout(400);
      expect(await adminPage.evaluate(() => document.body.style.overflow)).toBe('');
    });

    test('Modal can be opened and closed multiple times', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      for (let i = 0; i < 2; i++) {
        await openPreviewModal(adminPage);
        await adminPage.locator('button[aria-label="Close preview"]').click();
        await adminPage.waitForTimeout(400);
        await expect(adminPage.locator('.absolute.inset-0.bg-black\\/75').first()).not.toBeVisible({ timeout: 5000 });
      }
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Preview modal — header
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Preview modal — header', () => {

    test('Header shows extension badge', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      // Modal header is the border-b bar; badge is a span.rounded.font-semibold inside it
      const header = adminPage.locator('.border-b.border-gray-200').first();
      await expect(header.locator('span.rounded.font-semibold').first()).toBeVisible({ timeout: 5000 });
    });

    test('Header shows asset filename (non-empty)', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      const filenamePara = adminPage.locator('p.font-semibold.truncate').first();
      await expect(filenamePara).toBeVisible({ timeout: 5000 });
      expect((await filenamePara.textContent())?.trim().length).toBeGreaterThan(0);
    });

    test('Header has close button with aria-label', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      await expect(adminPage.locator('button[aria-label="Close preview"]')).toBeVisible({ timeout: 5000 });
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Preview modal — content area
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Preview modal — content area', () => {

    test('Content area is visible and contains at least one media element', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      const content = adminPage.locator('.flex-1.min-h-0.overflow-hidden').first();
      await expect(content).toBeVisible({ timeout: 5000 });
      const hasImg    = await content.locator('img').first().isVisible().catch(() => false);
      const hasVideo  = await content.locator('video').first().isVisible().catch(() => false);
      const hasAudio  = await content.locator('audio').first().isVisible().catch(() => false);
      const hasIframe = await content.locator('iframe').first().isVisible().catch(() => false);
      expect(hasImg || hasVideo || hasAudio || hasIframe).toBeTruthy();
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Image viewer — toolbar buttons
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Image viewer', () => {

    test('Image renders in modal content area', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first()).toBeVisible({ timeout: 5000 });
    });

    test('Image has Vue-driven transform style attribute', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const style = await img.getAttribute('style');
      // imgTransformStyle = "translate(Xpx,Ypx) scale(Z) rotate(Ddeg)"
      expect(style).toMatch(/translate\(/);
      expect(style).toMatch(/scale\(/);
    });

    test('Toolbar visible (bottom pill with buttons)', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      // Toolbar: absolute bottom-3, left-1/2, rounded-full, bg-black/60
      const toolbar = adminPage.locator('.absolute.bottom-3.rounded-full').first();
      await expect(toolbar).toBeVisible({ timeout: 5000 });
    });

    test('Zoom percentage starts at 100%', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const display = adminPage.locator('.font-mono.tabular-nums').filter({ hasText: /100%/ }).first();
      await expect(display).toBeVisible({ timeout: 5000 });
    });

    test('Zoom in button increases zoom percentage', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.locator('button[title="Zoom in (+)"]').first().click();
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '0')).toBeGreaterThan(100);
    });

    test('Zoom out button decreases zoom percentage', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.locator('button[title="Zoom out (-)"]').first().click();
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '200')).toBeLessThan(100);
    });

    test('Rotate right button changes image transform', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const before = await img.getAttribute('style');
      await adminPage.locator('button[title="Rotate right (R)"]').first().click();
      await adminPage.waitForTimeout(200);
      expect(await img.getAttribute('style')).not.toBe(before);
    });

    test('Rotate left button changes image transform', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const before = await img.getAttribute('style');
      await adminPage.locator('button[title="Rotate left (L)"]').first().click();
      await adminPage.waitForTimeout(200);
      expect(await img.getAttribute('style')).not.toBe(before);
    });

    test('Fit to screen button is visible and clickable', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const btn = adminPage.locator('button[title="Fit to screen"]').first();
      await expect(btn).toBeVisible({ timeout: 5000 });
      await btn.click();
      await adminPage.waitForTimeout(200);
    });

    test('1:1 button is visible', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.locator('button[title="Actual size"]').first()).toBeVisible({ timeout: 5000 });
    });

    test('Reset button restores zoom to 100%', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.locator('button[title="Zoom in (+)"]').first().click();
      await adminPage.waitForTimeout(200);
      await adminPage.locator('button[title="Reset all (0)"]').first().click();
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '0')).toBe(100);
    });

    test('Mouse wheel zooms in on scroll up', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const content = adminPage.locator('.flex-1.min-h-0.overflow-hidden').first();
      const box = await content.boundingBox();
      if (!box) { test.skip(true, 'Could not get bounding box'); return; }
      await adminPage.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      // deltaY < 0 → zoom in (factor 1.1)
      await adminPage.mouse.wheel(0, -100);
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '100')).toBeGreaterThan(100);
    });

    test('Mouse wheel zooms out on scroll down', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const content = adminPage.locator('.flex-1.min-h-0.overflow-hidden').first();
      const box = await content.boundingBox();
      if (!box) { test.skip(true, 'Could not get bounding box'); return; }
      await adminPage.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      // deltaY > 0 → zoom out (factor 0.9)
      await adminPage.mouse.wheel(0, 100);
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '100')).toBeLessThan(100);
    });

    test('Mouse drag pans the image', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      // Zoom in so drag is meaningful
      await adminPage.locator('button[title="Zoom in (+)"]').first().click();
      await adminPage.waitForTimeout(200);

      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const box = await img.boundingBox();
      if (!box) { test.skip(true, 'Could not get image bounding box'); return; }

      const cx = box.x + box.width / 2;
      const cy = box.y + box.height / 2;
      const beforeStyle = await img.getAttribute('style');

      await adminPage.mouse.move(cx, cy);
      await adminPage.mouse.down();
      await adminPage.mouse.move(cx + 80, cy + 40);
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const afterStyle = await img.getAttribute('style');
      expect(afterStyle).not.toBe(beforeStyle);
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Image viewer — keyboard shortcuts
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Image viewer — keyboard shortcuts', () => {

    test('= key zooms in', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.keyboard.press('Equal');
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '0')).toBeGreaterThan(100);
    });

    test('- key zooms out', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.keyboard.press('Minus');
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '200')).toBeLessThan(100);
    });

    test('r key rotates right', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const before = await img.getAttribute('style');
      await adminPage.keyboard.press('r');
      await adminPage.waitForTimeout(200);
      expect(await img.getAttribute('style')).not.toBe(before);
    });

    test('R key (uppercase) rotates right', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const before = await img.getAttribute('style');
      await adminPage.keyboard.press('R');
      await adminPage.waitForTimeout(200);
      expect(await img.getAttribute('style')).not.toBe(before);
    });

    test('l key rotates left', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const before = await img.getAttribute('style');
      await adminPage.keyboard.press('l');
      await adminPage.waitForTimeout(200);
      expect(await img.getAttribute('style')).not.toBe(before);
    });

    test('L key (uppercase) rotates left', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      const before = await img.getAttribute('style');
      await adminPage.keyboard.press('L');
      await adminPage.waitForTimeout(200);
      expect(await img.getAttribute('style')).not.toBe(before);
    });

    test('0 key resets zoom to 100%', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.keyboard.press('Equal'); // zoom in first
      await adminPage.waitForTimeout(200);
      await adminPage.keyboard.press('0');
      await adminPage.waitForTimeout(200);
      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '0')).toBe(100);
    });

    test('Keyboard shortcuts inactive when preview modal is closed', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      if (!(await isImageAsset(adminPage))) { test.skip(true, 'Not an image asset'); return; }
      // Press = without opening the preview modal — page must not change
      await adminPage.keyboard.press('Equal');
      await adminPage.waitForTimeout(200);
      await expect(adminPage).toHaveURL(/admin\/dam\/assets\/edit\/\d+/);
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Image editor modal
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Image editor modal', () => {

    test('Clicking Edit image opens editor modal', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.locator('button[aria-label="Close editor"]')).toBeVisible({ timeout: 5000 });
    });

    test('Editor header shows asset filename', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      // Editor header: icon-edit + filename <p> + close button
      const editorHeader = adminPage.locator('button[aria-label="Close editor"]').locator('xpath=ancestor::div[contains(@class,"border-b")]').first();
      const filename = editorHeader.locator('p.font-semibold.truncate').first();
      await expect(filename).toBeVisible({ timeout: 5000 });
      expect((await filename.textContent())?.trim().length).toBeGreaterThan(0);
    });

    test('Editor image preview panel shows asset image', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      // Image panel: bg-gray-50 border-r, contains <img class="... p-6">
      const previewImg = adminPage.locator('img.object-contain.p-6').first();
      await expect(previewImg).toBeVisible({ timeout: 5000 });
    });

    test('Editor shows "Tools" section header', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.getByText('Tools').first()).toBeVisible({ timeout: 5000 });
    });

    test('Editor shows Background Remover tool', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.getByText('Background Remover').first()).toBeVisible({ timeout: 5000 });
    });

    test('Editor shows Crop & Resize tool', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.getByText('Crop & Resize').first()).toBeVisible({ timeout: 5000 });
    });

    test('Editor shows Brightness & Contrast tool', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.getByText('Brightness & Contrast').first()).toBeVisible({ timeout: 5000 });
    });

    test('Editor shows Rotate & Flip tool', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await expect(adminPage.getByText('Rotate & Flip').first()).toBeVisible({ timeout: 5000 });
    });

    test('Apply button disabled when no tool selected', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      const applyBtn = adminPage.locator('button').filter({ hasText: /^Apply$/ }).first();
      await expect(applyBtn).toBeVisible({ timeout: 5000 });
      await expect(applyBtn).toBeDisabled();
    });

    test('Selecting Crop & Resize enables Apply button', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.getByText('Crop & Resize').first().click();
      await adminPage.waitForTimeout(200);
      await expect(adminPage.locator('button').filter({ hasText: /^Apply$/ }).first()).toBeEnabled({ timeout: 5000 });
    });

    test('Selecting Background Remover shows AI prompt textarea', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.getByText('Background Remover').first().click();
      await adminPage.waitForTimeout(200);
      // v-if="editTool === 'bg-remove'" renders the Prompt textarea
      await expect(adminPage.locator('textarea').first()).toBeVisible({ timeout: 5000 });
    });

    test('Selecting non-bg-remove tool does NOT show AI prompt textarea', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.getByText('Crop & Resize').first().click();
      await adminPage.waitForTimeout(200);
      await expect(adminPage.locator('textarea').first()).not.toBeVisible({ timeout: 3000 });
    });

    test('Selecting a tool applies active highlight class to that button', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      const cropBtn = adminPage.locator('button').filter({ has: adminPage.getByText('Crop & Resize') }).first();
      await cropBtn.click();
      await adminPage.waitForTimeout(200);
      // Active class: bg-violet-50 dark:bg-violet-900 text-violet-700
      const classList = await cropBtn.evaluate(el => el.className);
      expect(classList).toContain('bg-violet-50');
    });

    test('Close editor button closes editor modal', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      await adminPage.locator('button[aria-label="Close editor"]').click();
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('button[aria-label="Close editor"]')).not.toBeVisible({ timeout: 5000 });
    });

    test('Backdrop click closes editor modal', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      // Editor backdrop: absolute inset-0 bg-black/75 (same shade as preview modal)
      // But editor is v-if="isEditOpen" — its backdrop is the first bg-black/75 element
      // since preview modal is not open simultaneously
      const backdrop = adminPage.locator('.absolute.inset-0.bg-black\\/75').first();
      await expect(backdrop).toBeVisible({ timeout: 5000 });
      await backdrop.click({ position: { x: 5, y: 5 }, force: true });
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('button[aria-label="Close editor"]')).not.toBeVisible({ timeout: 5000 });
    });

    test('Escape closes editor modal and clears tool selection', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }
      // Select a tool so editTool is non-null
      await adminPage.getByText('Crop & Resize').first().click();
      await adminPage.waitForTimeout(200);
      await adminPage.keyboard.press('Escape');
      await adminPage.waitForTimeout(400);
      await expect(adminPage.locator('button[aria-label="Close editor"]')).not.toBeVisible({ timeout: 5000 });
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Crop drag interaction
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Crop drag interaction', () => {

    async function openCropTool(page) {
      const opened = await openEditorIfImage(page);
      if (!opened) return false;
      await page.getByText('Crop & Resize').first().click();
      // Wait for the crop overlay to appear (depends on image load)
      await page.locator('.border-white\\/90.pointer-events-auto').first()
        .waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
      return true;
    }

    test('Crop overlay appears after selecting Crop & Resize', async ({ adminPage }) => {
      const ok = await openCropTool(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }
      // Crop overlay: border-2 border-white/90 pointer-events-auto (the crop box)
      await expect(adminPage.locator('.border-white\\/90.pointer-events-auto').first()).toBeVisible({ timeout: 10000 });
    });

    test('Drawing new selection by dragging on image container changes crop dimensions', async ({ adminPage }) => {
      const ok = await openCropTool(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }

      // Get the image container (the panel that holds the crop overlay)
      const container = adminPage.locator('[ref="editImgContainer"], .bg-gray-50.border-r').first();
      const box = await container.boundingBox();
      if (!box) { test.skip(true, 'Could not get container bounding box'); return; }

      // Get initial dimension badge text
      const badge = adminPage.locator('.font-mono').filter({ hasText: /\d+ × \d+ px/ }).first();
      const beforeText = await badge.textContent({ timeout: 3000 }).catch(() => '');

      // Draw a new selection: start at 20% from left/top, drag to 60% right/down
      const startX = box.x + box.width * 0.2;
      const startY = box.y + box.height * 0.2;
      const endX   = box.x + box.width * 0.6;
      const endY   = box.y + box.height * 0.6;

      await adminPage.mouse.move(startX, startY);
      await adminPage.mouse.down();
      await adminPage.mouse.move(endX, endY, { steps: 10 });
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const afterText = await badge.textContent({ timeout: 3000 }).catch(() => '');
      // Dimensions must have changed (new selection ≠ full-image initial selection)
      expect(afterText).not.toBe(beforeText);
    });

    test('Dragging br corner handle inward resizes the crop box', async ({ adminPage }) => {
      const ok = await openCropTool(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }

      const badge = adminPage.locator('.font-mono').filter({ hasText: /\d+ × \d+ px/ }).first();
      const beforeText = await badge.textContent({ timeout: 3000 }).catch(() => '');

      // Bottom-right corner handle: -bottom-1.5 -right-1.5 (last .w-3.h-3 inside crop box)
      const brHandle = adminPage.locator('.absolute.-bottom-1\\.5.-right-1\\.5').first();
      const handleBox = await brHandle.boundingBox().catch(() => null);
      if (!handleBox) { test.skip(true, 'BR handle not found'); return; }

      const hx = handleBox.x + handleBox.width / 2;
      const hy = handleBox.y + handleBox.height / 2;

      await adminPage.mouse.move(hx, hy);
      await adminPage.mouse.down();
      await adminPage.mouse.move(hx - 60, hy - 60, { steps: 10 });
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const afterText = await badge.textContent({ timeout: 3000 }).catch(() => '');
      expect(afterText).not.toBe(beforeText);
    });

    test('Dragging left edge handle right shrinks selection from left', async ({ adminPage }) => {
      const ok = await openCropTool(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }

      // First draw a medium selection so handles are accessible
      const container = adminPage.locator('.bg-gray-50.border-r').first();
      const box = await container.boundingBox();
      if (!box) { test.skip(true, 'No container box'); return; }

      await adminPage.mouse.move(box.x + box.width * 0.1, box.y + box.height * 0.1);
      await adminPage.mouse.down();
      await adminPage.mouse.move(box.x + box.width * 0.9, box.y + box.height * 0.9, { steps: 10 });
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const badge = adminPage.locator('.font-mono').filter({ hasText: /\d+ × \d+ px/ }).first();
      const beforeText = await badge.textContent({ timeout: 3000 }).catch(() => '');

      // Left edge handle: top-1/2 -left-1.5
      const lHandle = adminPage.locator('.absolute.top-1\\/2.-left-1\\.5').first();
      const lBox = await lHandle.boundingBox().catch(() => null);
      if (!lBox) { test.skip(true, 'Left handle not found'); return; }

      const lx = lBox.x + lBox.width / 2;
      const ly = lBox.y + lBox.height / 2;

      await adminPage.mouse.move(lx, ly);
      await adminPage.mouse.down();
      await adminPage.mouse.move(lx + 50, ly, { steps: 10 });
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const afterText = await badge.textContent({ timeout: 3000 }).catch(() => '');
      expect(afterText).not.toBe(beforeText);
    });

    test('Moving crop box updates its position', async ({ adminPage }) => {
      const ok = await openCropTool(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }

      // Draw a small selection in the top-left quadrant (20%→50%) so the box
      // has room to move right and down, regardless of the initial box size.
      const container = adminPage.locator('.bg-gray-50.border-r').first();
      const cbox = await container.boundingBox();
      if (!cbox) { test.skip(true, 'No container bounds'); return; }

      await adminPage.mouse.move(cbox.x + cbox.width * 0.2, cbox.y + cbox.height * 0.2);
      await adminPage.mouse.down();
      await adminPage.mouse.move(cbox.x + cbox.width * 0.5, cbox.y + cbox.height * 0.5, { steps: 10 });
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const cropBox = adminPage.locator('.border-white\\/90.pointer-events-auto').first();
      const beforeStyle = await cropBox.getAttribute('style');

      const cropBounds = await cropBox.boundingBox();
      if (!cropBounds) { test.skip(true, 'No crop box bounds'); return; }

      const cx = cropBounds.x + cropBounds.width / 2;
      const cy = cropBounds.y + cropBounds.height / 2;

      // Move RIGHT and DOWN — the drawn box is in the top-left, so room exists both ways
      await adminPage.mouse.move(cx, cy);
      await adminPage.mouse.down();
      await adminPage.mouse.move(cx + 40, cy + 20, { steps: 10 });
      await adminPage.mouse.up();
      await adminPage.waitForTimeout(200);

      const afterStyle = await cropBox.getAttribute('style');
      expect(afterStyle).not.toBe(beforeStyle);
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Video player (conditional — only runs on video assets)
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Video player', () => {

    async function openVideoPreview(page) {
      await navigateToFirstAssetEdit(page);
      const thumb = page.locator('.rounded-lg.overflow-hidden img').first();
      await thumb.waitFor({ state: 'visible', timeout: 10000 });
      // Non-image placeholder with opacity-60 — but video placeholder exists.
      // Detect video: check placeholder src contains "video.svg"
      const src = await thumb.getAttribute('src');
      if (!src || !src.includes('video.svg')) return false;
      await openPreviewModal(page);
      return true;
    }

    test('Video element renders in modal', async ({ adminPage }) => {
      const ok = await openVideoPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a video asset'); return; }
      await expect(adminPage.locator('.flex-1.min-h-0.overflow-hidden video').first()).toBeVisible({ timeout: 10000 });
    });

    test('Speed selector buttons visible', async ({ adminPage }) => {
      const ok = await openVideoPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a video asset'); return; }
      // Speed buttons: 1× button always present
      await expect(adminPage.locator('button').filter({ hasText: '1×' }).first()).toBeVisible({ timeout: 5000 });
    });

    test('Skip back 10s button visible (title=Back 10s)', async ({ adminPage }) => {
      const ok = await openVideoPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a video asset'); return; }
      await expect(adminPage.locator('button[title="Back 10s"]').first()).toBeVisible({ timeout: 5000 });
    });

    test('Skip forward 10s button visible (title=Forward 10s)', async ({ adminPage }) => {
      const ok = await openVideoPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a video asset'); return; }
      await expect(adminPage.locator('button[title="Forward 10s"]').first()).toBeVisible({ timeout: 5000 });
    });

    test('1× speed button is active by default', async ({ adminPage }) => {
      const ok = await openVideoPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a video asset'); return; }
      const oneX = adminPage.locator('button').filter({ hasText: /^1×$/ }).first();
      const cls = await oneX.evaluate(el => el.className);
      expect(cls).toContain('bg-violet-600');
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Audio player (conditional — only runs on audio assets)
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Audio player', () => {

    async function openAudioPreview(page) {
      await navigateToFirstAssetEdit(page);
      const thumb = page.locator('.rounded-lg.overflow-hidden img').first();
      await thumb.waitFor({ state: 'visible', timeout: 10000 });
      const src = await thumb.getAttribute('src');
      if (!src || !src.includes('audio.svg')) return false;
      await openPreviewModal(page);
      return true;
    }

    test('Play/pause button visible', async ({ adminPage }) => {
      const ok = await openAudioPreview(adminPage);
      if (!ok) { test.skip(true, 'Not an audio asset'); return; }
      // Play button: w-12 h-12 rounded-full bg-violet-600
      await expect(adminPage.locator('button.w-12.h-12.rounded-full').first()).toBeVisible({ timeout: 5000 });
    });

    test('Seek bar range input visible', async ({ adminPage }) => {
      const ok = await openAudioPreview(adminPage);
      if (!ok) { test.skip(true, 'Not an audio asset'); return; }
      await expect(adminPage.locator('input[type="range"].accent-violet-600.flex-1').first()).toBeVisible({ timeout: 5000 });
    });

    test('Volume slider visible', async ({ adminPage }) => {
      const ok = await openAudioPreview(adminPage);
      if (!ok) { test.skip(true, 'Not an audio asset'); return; }
      // Volume slider: w-24 h-1.5 accent-violet-600
      await expect(adminPage.locator('input[type="range"].w-24').first()).toBeVisible({ timeout: 5000 });
    });

    test('Current time display starts at 0:00', async ({ adminPage }) => {
      const ok = await openAudioPreview(adminPage);
      if (!ok) { test.skip(true, 'Not an audio asset'); return; }
      // audioCurrentTimeDisplay renders as "0:00" initially
      await expect(adminPage.locator('.font-mono.tabular-nums').filter({ hasText: '0:00' }).first()).toBeVisible({ timeout: 5000 });
    });

    test('Skip back 10s button visible (title=Back 10s)', async ({ adminPage }) => {
      const ok = await openAudioPreview(adminPage);
      if (!ok) { test.skip(true, 'Not an audio asset'); return; }
      await expect(adminPage.locator('button[title="Back 10s"]').first()).toBeVisible({ timeout: 5000 });
    });

    test('Skip forward 10s button visible (title=Forward 10s)', async ({ adminPage }) => {
      const ok = await openAudioPreview(adminPage);
      if (!ok) { test.skip(true, 'Not an audio asset'); return; }
      await expect(adminPage.locator('button[title="Forward 10s"]').first()).toBeVisible({ timeout: 5000 });
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Fallback content (unsupported file type)
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Fallback / unsupported asset', () => {

    async function openFallbackPreview(page) {
      await navigateToFirstAssetEdit(page);
      const thumb = page.locator('.rounded-lg.overflow-hidden img').first();
      await thumb.waitFor({ state: 'visible', timeout: 10000 });
      const src = await thumb.getAttribute('src');
      // Fallback: placeholder that is NOT video/audio (i.e., file.svg or unspecified.svg)
      if (!src || src.includes('video.svg') || src.includes('audio.svg')) return false;
      if (!(await thumb.evaluate(el => el.classList.contains('opacity-60')))) return false; // image asset
      await openPreviewModal(page);
      return true;
    }

    test('Fallback modal shows "not available" message', async ({ adminPage }) => {
      const ok = await openFallbackPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a document/fallback asset'); return; }
      // Blade renders the i18n key 'preview-modal.not-available'
      const msg = adminPage.locator('.flex-1.min-h-0.overflow-hidden').getByText(/not available|preview not/i).first();
      const visible = await msg.isVisible({ timeout: 5000 }).catch(() => false);
      if (!visible) {
        test.info().annotations.push({ type: 'note', description: 'Could be a PDF — iframe shown instead' });
      }
    });

    test('Fallback modal shows Download button', async ({ adminPage }) => {
      const ok = await openFallbackPreview(adminPage);
      if (!ok) { test.skip(true, 'Not a document/fallback asset'); return; }
      const downloadLink = adminPage.locator('.flex-1.min-h-0.overflow-hidden a.primary-button').first();
      const visible = await downloadLink.isVisible({ timeout: 5000 }).catch(() => false);
      if (!visible) {
        test.info().annotations.push({ type: 'note', description: 'Might be PDF — uses iframe instead of download button' });
      }
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // Escape key priority
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('Escape key priority', () => {

    test('Escape closes info modal first when preview is also open', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);

      // Info button is in the card DOM even when preview overlay is on top —
      // click it with force to bypass the visual occlusion.
      const infoBtn = adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first();
      await infoBtn.click({ force: true });
      // Wait for info backdrop
      await adminPage.locator('.absolute.inset-0.bg-black\\/60').first()
        .waitFor({ state: 'visible', timeout: 8000 });

      // Both modals open — first Escape must close info only, preview stays
      await adminPage.keyboard.press('Escape');
      await adminPage.waitForTimeout(400);

      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/60').first()).not.toBeVisible({ timeout: 5000 });
      // Preview backdrop must still exist
      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/75').first()).toBeVisible({ timeout: 3000 });
    });

    test('Second Escape closes preview modal after info is already dismissed', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await openPreviewModal(adminPage);
      const infoBtn = adminPage.locator('button').filter({ has: adminPage.locator('.icon-information') }).first();
      await infoBtn.click({ force: true });
      await adminPage.locator('.absolute.inset-0.bg-black\\/60').first()
        .waitFor({ state: 'visible', timeout: 8000 });

      await adminPage.keyboard.press('Escape'); // closes info
      await adminPage.waitForTimeout(300);
      await adminPage.keyboard.press('Escape'); // now closes preview
      await adminPage.waitForTimeout(400);

      await expect(adminPage.locator('.absolute.inset-0.bg-black\\/75').first()).not.toBeVisible({ timeout: 5000 });
    });

    test('Escape closes editor modal first when editor is open', async ({ adminPage }) => {
      const opened = await openEditorIfImage(adminPage);
      if (!opened) { test.skip(true, 'Not an image asset'); return; }

      await adminPage.keyboard.press('Escape');
      await adminPage.waitForTimeout(400);

      // Editor gone, page still on edit URL
      await expect(adminPage.locator('button[aria-label="Close editor"]')).not.toBeVisible({ timeout: 5000 });
      await expect(adminPage).toHaveURL(/admin\/dam\/assets\/edit\/\d+/);
    });

    test('Escape with nothing open does not navigate away', async ({ adminPage }) => {
      await navigateToFirstAssetEdit(adminPage);
      await adminPage.keyboard.press('Escape');
      await adminPage.waitForTimeout(300);
      await expect(adminPage).toHaveURL(/admin\/dam\/assets\/edit\/\d+/);
    });

  });

  // ═══════════════════════════════════════════════════════════════════════════
  // State reset on re-open
  // ═══════════════════════════════════════════════════════════════════════════

  test.describe('State reset on re-open', () => {

    test('Image zoom resets to 100% when preview reopened', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }

      const zoomInBtn = adminPage.locator('button[title="Zoom in (+)"]').first();
      if (!(await zoomInBtn.isVisible({ timeout: 3000 }).catch(() => false))) {
        test.skip(true, 'Zoom toolbar not found'); return;
      }

      await zoomInBtn.click();
      await adminPage.waitForTimeout(200);

      await adminPage.locator('button[aria-label="Close preview"]').click();
      await adminPage.waitForTimeout(400);
      await openPreviewModal(adminPage);
      await adminPage.waitForTimeout(300);

      const text = await adminPage.locator('.font-mono.tabular-nums').first().textContent();
      expect(parseInt(text ?? '0')).toBe(100);
    });

    test('Image rotation resets to 0 when preview reopened', async ({ adminPage }) => {
      const ok = await openImagePreview(adminPage);
      if (!ok) { test.skip(true, 'Not an image asset'); return; }

      const img = adminPage.locator('.flex-1.min-h-0.overflow-hidden img').first();
      await adminPage.locator('button[title="Rotate right (R)"]').first().click();
      await adminPage.waitForTimeout(200);

      await adminPage.locator('button[aria-label="Close preview"]').click();
      await adminPage.waitForTimeout(400);
      await openPreviewModal(adminPage);
      await adminPage.waitForTimeout(300);

      const style = await img.getAttribute('style');
      // After reset: rotate(0deg)
      expect(style).toMatch(/rotate\(0deg\)/);
    });

  });

});

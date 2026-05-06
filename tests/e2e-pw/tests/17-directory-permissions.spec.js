const { test, expect } = require('../utils/fixtures');
const { navigateTo, generateUid } = require('../utils/helpers');

/**
 * Right-click a directory row in the DAM tree and click "Add Directory".
 * Used here to seed nested directories before exercising the permission
 * manager — the manager page must render the full tree, not just the level
 * with directly-checked items.
 */
async function addChildDirectoryUnder(page, parentName, name) {
  const wrapper = parentName === 'Root'
    ? page.locator('.tree-container').first()
    : page.locator('.tree-container-details').filter({ hasText: parentName }).first();

  const row = parentName === 'Root'
    ? wrapper.locator('> div.flex').first()
    : wrapper.locator('> .flex.cursor-pointer').first();

  await row.scrollIntoViewIfNeeded();
  await row.click({ button: 'right', force: true });

  await page.getByText('Add Directory').first().click({ force: true });

  const nameInput = page.getByPlaceholder('Name').first();
  await nameInput.waitFor({ state: 'visible', timeout: 10000 });
  await nameInput.fill(name);
  await page.getByRole('button', { name: 'Save Directory' }).click();
  await page.waitForTimeout(1500);

  // Confirm the new dir actually rendered in the DAM tree before returning,
  // otherwise tests that immediately navigate to /admin/dam/permissions can
  // race the server-side directoryTree query against an in-flight create.
  await page.locator('.tree-container').first()
    .getByText(name, { exact: true })
    .first()
    .waitFor({ state: 'visible', timeout: 15000 })
    .catch(() => {});
}

test.describe('DAM Directory Permissions Manager', () => {
  test('Manage Permissions link on DAM page navigates to manager', async ({ adminPage }) => {
    await navigateTo(adminPage, 'dam');

    const manageLink = adminPage.getByRole('link', {
      name: 'DAM Directory Permissions',
    }).first();

    await expect(manageLink).toBeVisible();
    await manageLink.click();

    await adminPage.waitForURL(/\/admin\/dam\/permissions/, { timeout: 15000 });
    await expect(
      adminPage.locator('#app').getByText('DAM Directory Permissions').first()
    ).toBeVisible();
  });

  test('Back button on manager page returns to DAM', async ({ adminPage }) => {
    await navigateTo(adminPage, 'damPermissions');

    const backLink = adminPage.getByRole('link', { name: 'Back to DAM' });
    await expect(backLink).toBeVisible();
    await backLink.click();

    await adminPage.waitForURL((url) => /\/admin\/dam(\?|$|#)/.test(url.toString()), {
      timeout: 15000,
    });
  });

  test('Save Permissions button visible for super-admin', async ({ adminPage }) => {
    await navigateTo(adminPage, 'damPermissions');

    // permission_type=all bypass — Save must render.
    await expect(
      adminPage.getByRole('button', { name: 'Save Permissions' })
    ).toBeVisible();
  });

  test('Tree renders every directory regardless of grant depth', async ({ adminPage, uid }) => {
    // Seed a 3-level chain: PermRoot{uid} → PermMid{uid} → PermLeaf{uid}.
    // Plus a sibling under root so we can prove sibling branches survive.
    const root = `PermRoot_${uid}`;
    const mid = `PermMid_${uid}`;
    const leaf = `PermLeaf_${uid}`;
    const sibling = `PermSibling_${uid}`;

    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, 'Root', root);
    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, root, mid);
    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, mid, leaf);
    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, root, sibling);

    await navigateTo(adminPage, 'damPermissions');

    // All four nested names must render even with no grants checked — the
    // bug we fixed was the Admin tree.view collapsing siblings of deep grants.
    for (const name of [root, mid, leaf, sibling]) {
      await expect(
        adminPage.locator('#app').getByText(name).first()
      ).toBeVisible({ timeout: 10000 });
    }
  });

  test('Saving a grant persists across reloads', async ({ adminPage, uid }) => {
    const dirName = `PermPersist_${uid}`;

    // Seed
    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, 'Root', dirName);

    await navigateTo(adminPage, 'damPermissions');

    // Find the row for the seeded dir, check its checkbox.
    const row = adminPage.locator('label').filter({ hasText: dirName }).first();
    await row.waitFor({ state: 'visible', timeout: 10000 });
    const checkbox = row.locator('input[type="checkbox"][name="directories[]"]').first();

    // The checkbox itself is `class="hidden peer"` (display:none) and only
    // the wrapping <label> is interactive; clicking the label triggers the
    // native label-toggles-input behavior, which Vue's @change picks up.
    if (! (await checkbox.isChecked().catch(() => false))) {
      await row.click();
      await expect(checkbox).toBeChecked({ timeout: 5000 });
    }

    await adminPage.getByRole('button', { name: 'Save Permissions' }).click();

    // Wait for either redirect-with-flash or page reload.
    await adminPage.waitForURL(/\/admin\/dam\/permissions/, { timeout: 20000 });

    // Re-find the row after reload — the checkbox should still be checked.
    const persistedRow = adminPage.locator('label').filter({ hasText: dirName }).first();
    await persistedRow.waitFor({ state: 'visible', timeout: 10000 });
    const persistedBox = persistedRow.locator('input[type="checkbox"][name="directories[]"]').first();

    await expect(persistedBox).toBeChecked();
  });

  test('Role picker renders using the same control as the ACL permission type select', async ({ adminPage }) => {
    await navigateTo(adminPage, 'damPermissions');

    // The picker now uses the shared admin select control (v-select-handler),
    // matching the role-edit "permission_type" pattern. Identify by the
    // hidden form input the control emits.
    const roleInput = adminPage.locator('[name="dam_permission_role_id"]').first();
    await expect(roleInput).toBeAttached();
  });

  /*
   * View-only mode is enforced server-side and covered by Pest:
   *   - hides the role picker for view-only admins
   *   - shows only granted directories to view-only admins (no full tree)
   *   - shows a friendly empty-state for view-only admins with zero grants
   *   - restricts view-only admins to their own role in the dropdown
   *   - blocks view-only admins from fetching other roles via show
   *
   * Reproducing those flows in Playwright requires a separate
   * custom-role admin storage-state (the current global-setup
   * authenticates as super-admin only), which is out of scope here.
   * The tests below assert the update-mode (super-admin) UI surface
   * still renders the affordances after the view-only refactor — i.e.
   * regression coverage for super-admin.
   */

  test('Update mode renders the role label heading + dropdown (regression)', async ({ adminPage }) => {
    await navigateTo(adminPage, 'damPermissions');

    // Heading still rendered above the dropdown for update-capable admins.
    await expect(
      adminPage.locator('#app').getByText('Role', { exact: true }).first()
    ).toBeVisible();

    // Picker template renders the shared admin select control. Vue replaces
    // the custom <v-dam-permission-role-picker> element on mount, so assert
    // against the rendered control's id rather than the source tag name.
    await expect(
      adminPage.locator('#dam_permission_role_id').first()
    ).toBeAttached();
  });

  test('Update mode keeps the empty-state placeholder hidden', async ({ adminPage }) => {
    await navigateTo(adminPage, 'damPermissions');

    // The view-only "no grants" placeholder must NOT appear for super-admin
    // since they always see the full tree regardless of their own role's
    // grants.
    await expect(
      adminPage.getByText('No directories are granted to your role yet.')
    ).toHaveCount(0);
  });

  test('Tree uses the ACL-style markup (v-tree-container + peer checkboxes)', async ({ adminPage }) => {
    await navigateTo(adminPage, 'damPermissions');

    // Outer wrappers must match the ACL tree structure.
    await expect(adminPage.locator('.v-tree-container').first()).toBeAttached();
    await expect(adminPage.locator('.v-tree-item-wrapper').first()).toBeAttached();

    // Native checkbox is hidden via `class="hidden peer"` — the visual icon is
    // a sibling span. Both must be present for at least one node.
    const peerInput = adminPage.locator('input[type="checkbox"].hidden.peer[name="directories[]"]').first();
    await expect(peerInput).toBeAttached();

    const checkboxIcon = adminPage.locator('span.icon-checkbox-normal').first();
    await expect(checkboxIcon).toBeAttached();
  });

  test('Chevron click collapses and re-expands a parent directory', async ({ adminPage, uid }) => {
    const parentName = `PermChevParent_${uid}`;
    const childName = `PermChevChild_${uid}`;

    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, 'Root', parentName);
    await navigateTo(adminPage, 'dam');
    await addChildDirectoryUnder(adminPage, parentName, childName);

    await navigateTo(adminPage, 'damPermissions');

    // Locate the parent node by the label containing the seeded name.
    const parentNode = adminPage
      .locator('.v-tree-item')
      .filter({ has: adminPage.locator('label').filter({ hasText: parentName }) })
      .first();

    // Initially expanded — child label visible inside the subtree.
    const childLabel = parentNode.locator('label').filter({ hasText: childName }).first();
    await expect(childLabel).toBeVisible({ timeout: 10000 });

    // Click parent's chevron — it is the first <i> with icon-chevron-down inside
    // the parent v-tree-item. Children should become hidden.
    const chevron = parentNode.locator('> i.icon-chevron-down').first();
    await chevron.click();
    await expect(childLabel).toBeHidden({ timeout: 5000 });

    // Click again — children visible.
    const chevronCollapsed = parentNode.locator('> i.icon-chevron-right').first();
    await chevronCollapsed.click();
    await expect(childLabel).toBeVisible({ timeout: 5000 });
  });

});

import { expect, test } from '@playwright/test';
import path from 'node:path';

test('public catalog is reachable in the isolated E2E environment', async ({ page }, testInfo) => {
    await page.goto('/');

    await expect(page).toHaveURL(/\/katalog$/);
    await expect(page.getByRole('link', { name: 'Katalog' })).toBeVisible();

    await page.screenshot({
        path: path.join(process.cwd(), 'tests', 'e2e', 'screenshots', `${testInfo.project.name}-catalog.png`),
        fullPage: true,
    });
});

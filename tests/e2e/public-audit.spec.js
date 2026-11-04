import { expect, test } from '@playwright/test';
import path from 'node:path';

const screenshotPath = (testInfo, name) => path.join(
    process.cwd(),
    'tests',
    'e2e',
    'screenshots',
    `${testInfo.project.name}-${name}.png`,
);

test('guest can inspect the empty public catalog responsively', async ({ page }, testInfo) => {
    await page.goto('/katalog');

    await expect(page).toHaveTitle(/BUMDes|Katalog/i);
    await expect(page.getByRole('link', { name: 'Katalog' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('ecommerce_bumdes_restore');

    await page.screenshot({ path: screenshotPath(testInfo, 'catalog-empty'), fullPage: true });
});

test('guest can open login and registration forms', async ({ page }, testInfo) => {
    await page.goto('/login');
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
    await page.screenshot({ path: screenshotPath(testInfo, 'login'), fullPage: true });

    await page.goto('/register');
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await page.screenshot({ path: screenshotPath(testInfo, 'register'), fullPage: true });
});

test('invalid guest login stays on validation flow without creating data', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('invalid-e2e@example.invalid');
    await page.getByLabel('Password').fill('invalid-password');
    await page.getByRole('button', { name: /log in|masuk/i }).click();

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText('ecommerce_bumdes_restore');
});

test('guest access to protected business pages redirects to login', async ({ page }) => {
    const protectedPaths = [
        '/checkout',
        '/pesanan',
        '/admin/dashboard',
        '/umkm/orders',
        '/kepala-bumdes/dashboard',
    ];

    for (const protectedPath of protectedPaths) {
        await page.goto(protectedPath);
        await expect(page).toHaveURL(/\/login\?redirect=|\/login/);
    }
});

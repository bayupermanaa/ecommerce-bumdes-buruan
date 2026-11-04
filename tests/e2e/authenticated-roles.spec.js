import { expect, test } from '@playwright/test';
import path from 'node:path';

const credentials = {
    admin_bumdes: ['admin.e2e@example.test', 'password123'],
    pemilik_umkm: ['umkm.e2e@example.test', 'password123'],
    pelanggan: ['pelanggan.e2e@example.test', 'password123'],
    kepala_bumdes: ['kepala.e2e@example.test', 'password123'],
    kurir: ['kurir.e2e@example.test', 'password123'],
};

const dashboards = {
    admin_bumdes: '/admin/dashboard',
    pemilik_umkm: '/umkm/dashboard',
    pelanggan: '/pelanggan/dashboard',
    kepala_bumdes: '/kepala-bumdes/dashboard',
    kurir: '/kurir/dashboard',
};

const roleChecks = {
    admin_bumdes: {
        text: 'Ringkasan operasional',
        paths: ['/admin/umkm-stores', '/admin/products', '/admin/pembayaran', '/admin/pencairan-umkm', '/admin/audit-keuangan'],
    },
    pemilik_umkm: {
        text: 'Kelola profil toko',
        paths: ['/umkm/stores', '/umkm/products', '/umkm/orders', '/umkm/commission-agreements', '/umkm/pencairan'],
    },
    pelanggan: {
        text: 'Jelajahi Katalog',
        paths: ['/katalog', '/katalog/produk-e2e-buruan', '/keranjang', '/checkout', '/pesanan'],
    },
    kepala_bumdes: {
        text: 'Pantau bisnis dalam satu pandangan',
        paths: ['/kepala-bumdes/laporan-umkm', '/kepala-bumdes/laporan-komisi', '/kepala-bumdes/laporan-hak-umkm', '/kepala-bumdes/laporan-pencairan-umkm'],
    },
    kurir: {
        text: 'Fitur pengiriman belum tersedia',
        paths: [],
    },
};

const screenshotDirectories = {
    admin_bumdes: 'admin',
    pemilik_umkm: 'umkm',
    pelanggan: 'customer',
    kepala_bumdes: 'kepala-bumdes',
    kurir: 'kurir',
};

async function login(page, role) {
    const [email, password] = credentials[role];
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: /log in|masuk/i }).click();
    await expect(page).toHaveURL(new RegExp(dashboards[role].replaceAll('/', '\\/')));
}

async function assertAccessiblePages(page, paths) {
    for (const target of paths) {
        const response = await page.goto(target);
        expect(response?.status(), target).toBe(200);
    }
}

for (const role of Object.keys(credentials)) {
    test(`${role} can login, view its dashboard, and use its permitted pages`, async ({ page }, testInfo) => {
        await login(page, role);
        await expect(page.locator('body')).toContainText(roleChecks[role].text);
        await page.screenshot({
            path: path.join(process.cwd(), 'docs', 'screenshots', screenshotDirectories[role], `${testInfo.project.name}-dashboard.png`),
            fullPage: true,
        });
        if (role === 'pelanggan') {
            await page.goto('/katalog');
            await expect(page.locator('body')).toContainText('Produk E2E Buruan');
        }
        await assertAccessiblePages(page, roleChecks[role].paths);

        if (role === 'kurir') {
            await expect(page.locator('body')).toContainText('Fitur pengiriman belum tersedia');
            await expect(page.locator('a[href*="delivery"], a[href*="pengiriman"]')).toHaveCount(0);
        }
    });
}

test('guest is redirected and authenticated role cannot open another role dashboard', async ({ page }) => {
    for (const target of Object.values(dashboards)) {
        await page.goto(target);
        await expect(page).toHaveURL(/\/login/);
    }

    await page.context().clearCookies();
    await login(page, 'admin_bumdes');
    for (const target of Object.values(dashboards).filter((target) => target !== dashboards.admin_bumdes)) {
        const response = await page.goto(target);
        expect(response?.status(), target).toBe(403);
    }
});

test('customer logout ends the authenticated browser session', async ({ page }) => {
    await login(page, 'pelanggan');
  await page.goto('/profile');
    await page.locator('nav button:visible').first().click();
    await page.locator('form[action$="/logout"] a:visible').first().click();
    await expect(page).toHaveURL(/\/katalog|\/login/);
    await page.goto('/pelanggan/dashboard');
    await expect(page).toHaveURL(/\/login/);
});

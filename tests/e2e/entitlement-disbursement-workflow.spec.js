import { expect, test } from '@playwright/test';

const users = {
    umkm: ['umkm.e2e@example.test', 'password123', '/umkm/dashboard'],
    adminA: ['admin.e2e@example.test', 'password123', '/admin/dashboard'],
    adminB: ['admin.verify.e2e@example.test', 'password123', '/admin/dashboard'],
    kepala: ['kepala.e2e@example.test', 'password123', '/kepala-bumdes/dashboard'],
};

async function login(page, user) {
    const [email, password, dashboard] = users[user];
    await page.context().clearCookies();
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: /log in|masuk/i }).click();
    await expect(page).toHaveURL(new RegExp(dashboard.replaceAll('/', '\\/')));
}

function syntheticTransferFile() {
    return {
        name: 'bukti-transfer-e2e.png',
        mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    };
}

async function fillTransferForm(page) {
    const form = page.locator('form[action*="catat-transfer"]').first();
    await expect(form).toBeVisible();
    await form.locator('[name="transfer_reference"]').fill(`E2E-BANK-${Date.now()}`);
    const recordedAtUtc = new Date(Date.now() - 60_000).toISOString().slice(0, 16);
    await form.locator('[name="transfer_recorded_at"]').fill(recordedAtUtc);
    await form.locator('[name="proof"]').setInputFiles(syntheticTransferFile());
    await form.getByRole('checkbox').check();
    const validity = await form.evaluate((element) => ({
        valid: element.checkValidity(),
        invalid: Array.from(element.elements)
            .filter((field) => !field.checkValidity())
            .map((field) => ({ name: field.name, message: field.validationMessage })),
    }));
    expect(validity).toEqual({ valid: true, invalid: [] });
    const submit = form.locator('button[type="submit"]', { hasText: 'Catat Transfer' });
    await expect(submit).toBeEnabled();
    const responsePromise = page.waitForResponse((response) =>
        response.request().method() === 'POST' && response.url().includes('/catat-transfer'),
    );
    await submit.click();
    const response = await responsePromise;
    console.log(`transfer response: ${response.status()} ${response.headers().location ?? response.url()}`);
    expect(response.status()).toBeLessThan(400);
}

test('synthetic entitlement request, approval, transfer recording, and independent verification', async ({ page }) => {
    await login(page, 'umkm');
    await page.goto('/umkm/pencairan');
    await expect(page.locator('body')).toContainText('Saldo tersedia');
    await expect(page.locator('body')).toContainText('Rp90.00');
    await page.getByRole('button', { name: 'Ajukan Seluruh Saldo Tersedia' }).click();
    await expect(page.locator('body')).toContainText('Pengajuan pencairan telah dibuat');
    await expect(page.locator('body')).toContainText('Menunggu Pemeriksaan');

    await login(page, 'adminA');
    await page.goto('/admin/pencairan-umkm');
    await page.getByRole('button', { name: 'Mulai Pemeriksaan' }).first().click();
    await expect(page.locator('body')).toContainText('Pemeriksaan pengajuan dimulai');
    await page.getByRole('button', { name: 'Selesaikan Pemeriksaan' }).first().click();
    await expect(page.locator('body')).toContainText('Pemeriksaan Admin selesai');

    await login(page, 'kepala');
    await page.goto('/kepala-bumdes/persetujuan-pencairan');
    await page.getByRole('button', { name: 'Setujui' }).first().click();
    await expect(page.locator('body')).toContainText('Pengajuan disetujui');

    await login(page, 'adminA');
    await page.goto('/admin/pencairan-umkm');
    await fillTransferForm(page);
    await expect(page.locator('body')).toContainText('Transfer dicatat dan menunggu verifikasi');
    await expect(page.locator('body')).toContainText('Menunggu verifikasi');

    await expect(page.getByRole('button', { name: 'Verifikasi Transfer' })).toHaveCount(0);
    await login(page, 'adminB');
    await page.goto('/admin/pencairan-umkm');
    await page.getByRole('button', { name: 'Verifikasi Transfer' }).first().click();
    await expect(page.locator('body')).toContainText('Transfer diverifikasi dan pencairan dinyatakan paid');
    await expect(page.locator('body')).toContainText('Dibayar');

    await login(page, 'umkm');
    await page.goto('/umkm/pencairan');
    await expect(page.locator('body')).toContainText('Selesai Dibayarkan');
    await expect(page.locator('body')).toContainText('Rp0.00');

    await login(page, 'kepala');
    await page.goto('/kepala-bumdes/laporan-hak-umkm');
    await expect(page.locator('body')).toContainText('Selesai Dicairkan');
    await expect(page.locator('body')).toContainText('Rp90');
    await page.goto('/kepala-bumdes/laporan-pencairan-umkm');
    await expect(page.locator('body')).toContainText('Berhasil Dicairkan');
});

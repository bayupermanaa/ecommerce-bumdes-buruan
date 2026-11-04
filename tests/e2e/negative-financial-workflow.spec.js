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

function syntheticFile(name = 'failure-e2e.png') {
    return {
        name,
        mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    };
}

test('cancellation, transfer failure, self-verification, and UI retry guards', async ({ page }) => {
    await login(page, 'umkm');
    await page.goto('/umkm/pencairan');
    await expect(page.getByRole('button', { name: 'Ajukan Seluruh Saldo Tersedia' })).toBeEnabled();

    // The first synthetic entitlement is reserved and released through the real UI.
    await page.getByRole('button', { name: 'Ajukan Seluruh Saldo Tersedia' }).click();
    await expect(page.locator('body')).toContainText('Pengajuan pencairan telah dibuat');
    const cancellationForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Batalkan Pengajuan' }) });
    await cancellationForm.getByRole('textbox', { name: 'Alasan pembatalan' }).fill('Pembatalan E2E yang sah');
    page.once('dialog', (dialog) => dialog.accept());
    await cancellationForm.getByRole('button', { name: 'Batalkan Pengajuan' }).click();
    await expect(page.locator('body')).toContainText('Pengajuan dibatalkan');
    await expect(page.locator('body')).toContainText('Dibatalkan');

    // A cancelled request exposes no second cancellation action.
    await expect(page.getByRole('button', { name: 'Batalkan Pengajuan' })).toHaveCount(0);

    // The remaining entitlement is used for the approved/transfer-failure flow.
    await page.getByRole('button', { name: 'Ajukan Seluruh Saldo Tersedia' }).click();
    await expect(page.locator('body')).toContainText('Pengajuan pencairan telah dibuat');

    await login(page, 'adminA');
    await page.goto('/admin/pencairan-umkm');
    await page.getByRole('button', { name: 'Mulai Pemeriksaan' }).first().click();
    await page.getByRole('button', { name: 'Selesaikan Pemeriksaan' }).first().click();

    await login(page, 'kepala');
    await page.goto('/kepala-bumdes/persetujuan-pencairan');
    await page.getByRole('button', { name: 'Setujui' }).first().click();
    await expect(page.locator('body')).toContainText('Pengajuan disetujui');

    await login(page, 'adminA');
    await page.goto('/admin/pencairan-umkm');
    const transferForm = page.locator('form[action*="catat-transfer"]').first();
    await transferForm.locator('[name="transfer_reference"]').fill(`E2E-NEG-TRANSFER-${Date.now()}`);
    await transferForm.locator('[name="transfer_recorded_at"]').fill(new Date(Date.now() - 60_000).toISOString().slice(0, 16));
    await transferForm.locator('[name="proof"]').setInputFiles(syntheticFile('transfer-e2e.png'));
    await transferForm.getByRole('checkbox').check();
    await transferForm.getByRole('button', { name: 'Catat Transfer' }).click();
    await expect(page.locator('body')).toContainText('Transfer dicatat dan menunggu verifikasi');

    // The recording Admin cannot verify its own transfer.
    await expect(page.getByRole('button', { name: 'Verifikasi Transfer' })).toHaveCount(0);

    const failureForm = page.locator('form[action*="catat-kegagalan"]').first();
    await failureForm.locator('[name="reason"]').fill('Bank menyatakan transfer gagal secara final');
    await failureForm.locator('[name="transfer_reference"]').fill('E2E-NEG-FAILED-001');
    await failureForm.locator('[name="proof"]').setInputFiles(syntheticFile('failure-e2e.png'));
    await failureForm.getByRole('button', { name: 'Catat Bukti Kegagalan' }).click();
    await expect(page.locator('body')).toContainText('Bukti kegagalan dicatat');
    await expect(page.locator('body')).toContainText('belum terverifikasi');

    await login(page, 'adminB');
    await page.goto('/admin/pencairan-umkm');
    const confirmForm = page.locator('form[action*="konfirmasi-kegagalan"]').first();
    await confirmForm.getByRole('checkbox', { name: 'Bank menyatakan gagal final' }).check();
    await confirmForm.getByRole('button', { name: 'Konfirmasi Kegagalan' }).click();
    await expect(page.locator('body')).toContainText('Kegagalan transfer dikonfirmasi');
    await expect(page.locator('body')).toContainText('Transfer Gagal');

    // A terminal failed transfer exposes no second confirmation action.
    await expect(page.getByRole('button', { name: 'Konfirmasi Kegagalan' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Verifikasi Transfer' })).toHaveCount(0);
});

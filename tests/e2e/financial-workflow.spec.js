import { expect, test } from '@playwright/test';

async function login(page, role) {
    const credentials = {
        pelanggan: ['pelanggan.e2e@example.test', 'password123'],
        admin_bumdes: ['admin.e2e@example.test', 'password123'],
    };
    const dashboards = {
        pelanggan: '/pelanggan/dashboard',
        admin_bumdes: '/admin/dashboard',
    };
    const [email, password] = credentials[role];
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: /log in|masuk/i }).click();
    await expect(page).toHaveURL(new RegExp(dashboards[role].replaceAll('/', '\\/')));
}

test('synthetic pickup checkout, bank proof submission, and admin confirmation', async ({ page }) => {
    await login(page, 'pelanggan');

    await page.goto('/katalog/produk-e2e-buruan');
    await expect(page.locator('body')).toContainText('Produk E2E Buruan');
    const addButton = page.getByRole('button', { name: 'Tambah ke Keranjang' });
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts?.ready);
    await page.waitForFunction(() => Array.from(document.images).every((image) => image.complete));
    await addButton.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' }));
    await page.waitForTimeout(500);
    const box = await addButton.boundingBox();
    if (!box) throw new Error('Tombol cart tidak memiliki bounding box.');
    const hit = await page.evaluate(({ x, y }) => {
        const element = document.elementFromPoint(x, y);
        return element?.matches('button[type="submit"]') ?? false;
    }, { x: box.x + box.width / 2, y: box.y + box.height / 2 });
    expect(hit).toBe(true);
    await addButton.click();
    await page.goto('/checkout');
    await page.getByLabel('Ambil di Toko Penjual').click();
    await page.getByLabel('Nama penerima').fill('Pelanggan E2E');
    await page.getByLabel('Nomor telepon').fill('0800000001');
    await expect(page.locator('[data-order-button]')).toBeEnabled();
    await page.locator('[data-order-button]').click();
    await expect(page).toHaveURL(/\/pesanan\/\d+$/);

    const orderPath = new URL(page.url()).pathname;
    const orderNumber = (await page.locator('h1').first().innerText()).trim();
    await page.getByRole('link', { name: 'Ajukan Pembayaran' }).click();
    await expect(page).toHaveURL(/\/pesanan\/\d+\/pembayaran$/);
    const bankAccount = page.getByLabel('Rekening resmi BUMDes', { exact: true });
    const bankOption = bankAccount.locator('option').filter({ hasText: 'Bank E2E Sintetis' }).first();
    await bankAccount.selectOption(await bankOption.getAttribute('value'));
    await page.getByLabel('Nama pengirim').fill('Pelanggan E2E');
    await page.getByLabel('Referensi transfer').fill(`E2E-${Date.now()}`);
    await page.locator('#transfer_proof').setInputFiles({
        name: 'bukti-e2e.png',
        mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    });
    await page.getByRole('button', { name: 'Ajukan Pembayaran' }).click();
    await expect(page).toHaveURL(new RegExp(orderPath.replaceAll('/', '\\/')));
    await expect(page.locator('body')).toContainText(/menunggu|pembayaran/i);

    await page.context().clearCookies();
    await login(page, 'admin_bumdes');
    await page.goto(`/admin/pembayaran?search=${encodeURIComponent(orderNumber)}`);
    await page.getByRole('link', { name: /Detail/ }).first().click();
    await expect(page.locator('body')).toContainText('Menunggu Verifikasi');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Konfirmasi Pembayaran' }).click();
    await expect(page.locator('body')).toContainText('Dibayar');

    await page.context().clearCookies();
    await login(page, 'pelanggan');
    await page.goto(orderPath);
    await expect(page.locator('body')).toContainText('Dibayar');
});

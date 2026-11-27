<?php
/**
 * Leviosa Crafts - Payment Page
 * Displays QRIS, SeaBank, and DANA details, allows uploading proof of payment,
 * shows rejection reasons, and enables re-upload.
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/upload.php';

$orderCode = trim($_GET['order_id'] ?? '');
if (empty($orderCode)) {
    header("Location: " . BASE_URL . "/tracking.php");
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE order_code = ?");
$stmt->execute([$orderCode]);
$order = $stmt->fetch();

if (!$order) {
    setFlash('danger', 'Pesanan dengan Order ID tersebut tidak ditemukan.');
    header("Location: " . BASE_URL . "/tracking.php");
    exit;
}

$pageTitle = "Pembayaran Pesanan - #" . $order['order_code'];

// Check if blocked because custom quote pending or shipping rate pending
$isCustomQuotePending = ($order['order_type'] !== 'ready_design' && $order['order_status'] === 'menunggu_persetujuan_harga');
$isPendingShippingRate = ($order['shipping_status'] === 'pending_admin_rate');

// Fetch Active Payment Methods
$paymentMethods = $db->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch Latest Payment Attempt (if any)
$stmtPay = $db->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$stmtPay->execute([$order['id']]);
$latestPayment = $stmtPay->fetch();

// -----------------------------------------------------------------------------
// POST: Upload Proof of Payment
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment_proof'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token keamanan CSRF tidak valid. Silakan coba kembali.');
        header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
        exit;
    }

    if ($isCustomQuotePending) {
        setFlash('danger', 'Pesanan custom ini masih menunggu penawaran harga dari admin.');
        header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
        exit;
    }

    if ($isPendingShippingRate) {
        setFlash('danger', 'Pesanan masih menunggu penetapan tarif ongkir dari admin (> 10 km).');
        header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
        exit;
    }

    if ($order['payment_status'] === 'pembayaran_berhasil') {
        setFlash('info', 'Pembayaran pesanan ini sudah diverifikasi dan berhasil.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($orderCode));
        exit;
    }

    if ($order['order_status'] === 'kedaluwarsa') {
        setFlash('danger', 'Pesanan ini sudah kedaluwarsa karena melewati batas waktu 24 jam.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($orderCode));
        exit;
    }

    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $methodName = 'Transfer Manual';
    foreach ($paymentMethods as $pm) {
        if ($pm['id'] === $paymentMethodId) {
            $methodName = $pm['name'];
            break;
        }
    }

    if (empty($_FILES['proof_image']['name'])) {
        setFlash('danger', 'Silakan pilih foto tangkapan layar (screenshot) bukti transfer Anda.');
        header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
        exit;
    }

    $uploadResult = Uploader::upload($_FILES['proof_image'], 'assets/uploads/payments/');
    if (!$uploadResult['success']) {
        setFlash('danger', $uploadResult['error']);
        header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
        exit;
    }

    $proofPath = $uploadResult['path'];

    $db->beginTransaction();
    try {
        // Record payment in payments table
        $stmtIns = $db->prepare("
            INSERT INTO payments (order_id, payment_method_id, method_name, amount, proof_image, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmtIns->execute([
            $order['id'],
            $paymentMethodId ?: null,
            $methodName,
            $order['total_amount'],
            $proofPath
        ]);

        // Update Order status to 'menunggu_verifikasi'
        $stmtUpd = $db->prepare("
            UPDATE orders
            SET payment_status = 'menunggu_verifikasi',
                order_status = 'menunggu_verifikasi',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpd->execute([$order['id']]);

        // History log
        logOrderStatus($order['id'], 'menunggu_verifikasi', "Bukti pembayaran diunggah via $methodName. Menunggu verifikasi admin.", 'customer');

        $db->commit();

        setFlash('success', 'Bukti pembayaran berhasil diunggah! Tim admin Leviosa Crafts akan segera memverifikasi.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($orderCode));
        exit;
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'Gagal memproses unggah bukti transfer: ' . $e->getMessage());
        header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow" style="text-align: center;">
        <span class="section-subtitle">Verifikasi Manual</span>
        <h1 class="section-title">Pembayaran Pesanan</h1>
        <p class="section-desc">Order ID: <strong><?= e($order['order_code']) ?></strong> &bull; Total Tagihan: <strong style="color: var(--primary-dark);"><?= formatRupiah($order['total_amount']) ?></strong></p>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">

        <!-- 1. BLOCKED CONDITIONS -->
        <?php if ($isCustomQuotePending): ?>
            <div style="background: #FFFBEB; border: 1px solid #FCD34D; padding: 24px; border-radius: var(--radius-md); text-align: center; margin-bottom: 30px;">
                <i class="fa-solid fa-hourglass-half" style="font-size: 2.5rem; color: #D97706; margin-bottom: 12px;"></i>
                <h3 style="color: #92400E; margin-bottom: 8px;">Menunggu Penawaran Harga Admin</h3>
                <p style="color: #78350F; font-size: 0.95rem; margin-bottom: 20px;">
                    Pesanan custom Anda sedang dalam proses peninjauan oleh tim crafter Leviosa. Anda baru dapat membayar setelah menyetujui penawaran harga resmi.
                </p>
                <a href="<?= BASE_URL ?>/custom-quote.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-primary">
                    Lihat Status Penawaran Custom
                </a>
            </div>
            <?php include __DIR__ . '/includes/footer.php'; exit; ?>
        <?php endif; ?>

        <?php if ($isPendingShippingRate): ?>
            <div style="background: #FFFBEB; border: 1px solid #FCD34D; padding: 24px; border-radius: var(--radius-md); text-align: center; margin-bottom: 30px;">
                <i class="fa-solid fa-route" style="font-size: 2.5rem; color: #D97706; margin-bottom: 12px;"></i>
                <h3 style="color: #92400E; margin-bottom: 8px;">Menunggu Penetapan Ongkir Admin (> 10 km)</h3>
                <p style="color: #78350F; font-size: 0.95rem; margin-bottom: 20px;">
                    Jarak alamat penerima Anda adalah <strong><?= e($order['distance_km']) ?> km</strong>. Admin akan menetapkan biaya pengiriman khusus sebelum pembayaran dapat dibuka.
                </p>
                <a href="<?= BASE_URL ?>/tracking.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-outline">
                    Pantau di Halaman Tracking
                </a>
            </div>
            <?php include __DIR__ . '/includes/footer.php'; exit; ?>
        <?php endif; ?>

        <!-- 2. PAYMENT STATUS ALERTS -->
        <?php if ($order['payment_status'] === 'pembayaran_berhasil'): ?>
            <div class="alert alert-success" style="margin-bottom: 24px;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.4rem;"></i>
                <div>
                    <strong>Pembayaran Telah Terverifikasi!</strong><br>
                    Pembayaran pesanan Anda telah dinyatakan sah oleh admin. Pesanan sedang dijadwalkan dalam antrean perakitan.
                </div>
            </div>
        <?php elseif ($order['payment_status'] === 'pembayaran_ditolak'): ?>
            <div class="alert alert-danger" style="margin-bottom: 24px;">
                <i class="fa-solid fa-circle-xmark" style="font-size: 1.4rem;"></i>
                <div>
                    <strong>Bukti Pembayaran Ditolak!</strong><br>
                    <?= !empty($latestPayment['admin_note']) ? 'Catatan Admin: ' . e($latestPayment['admin_note']) : 'Bukti transfer tidak terbaca atau nominal tidak sesuai.' ?><br>
                    <em>Silakan unggah kembali bukti transfer yang valid di bawah ini.</em>
                </div>
            </div>
        <?php elseif ($order['payment_status'] === 'menunggu_verifikasi'): ?>
            <div class="alert alert-info" style="margin-bottom: 24px;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 1.4rem;"></i>
                <div>
                    <strong>Menunggu Verifikasi Manual Admin</strong><br>
                    Bukti pembayaran Anda sudah diterima pada <?= formatDateIndo($latestPayment['created_at'], true) ?>. Admin sedang melakukan pengecekan rekening.
                </div>
            </div>
        <?php endif; ?>

        <!-- 3. PAYMENT METHODS ACCORDION / TABS -->
        <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); margin-bottom: 30px;">
            <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 20px; color: var(--primary-dark);">
                Pilihan Rekening Pembayaran Resmi Leviosa Crafts
            </h3>

            <div style="display: flex; flex-direction: column; gap: 20px;">
                <?php foreach ($paymentMethods as $pm): ?>
                    <div style="border: 1.5px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; background: #FFFDFD;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <strong style="font-size: 1.05rem; color: var(--text-dark);"><?= e($pm['name']) ?></strong>
                            <span class="badge badge-primary"><?= strtoupper(e($pm['code'])) ?></span>
                        </div>

                        <?php if ($pm['code'] === 'qris' && !empty($pm['qr_image'])): ?>
                            <div style="display: grid; grid-template-columns: 160px 1fr; gap: 20px; align-items: center;">
                                <div style="background: #FFFFFF; padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); text-align: center;">
                                    <img src="<?= BASE_URL . '/' . e($pm['qr_image']) ?>" alt="QRIS Leviosa Crafts" style="width: 140px; height: 140px; margin: 0 auto; object-fit: contain;">
                                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px;">Scan QRIS</div>
                                </div>
                                <div style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.6;">
                                    <?= nl2br(e($pm['instructions'])) ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="background: #FFFFFF; padding: 14px 18px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Nomor Rekening / Akun</div>
                                    <div style="font-family: monospace; font-size: 1.25rem; font-weight: 700; color: var(--primary-dark); letter-spacing: 1px;">
                                        <?= e($pm['account_number']) ?>
                                    </div>
                                    <div style="font-size: 0.82rem; color: var(--text-dark);">a.n <strong><?= e($pm['account_name']) ?></strong></div>
                                </div>
                                <button type="button" onclick="navigator.clipboard.writeText('<?= e($pm['account_number']) ?>'); showToast('Nomor rekening disalin!', 'success');" class="btn btn-outline btn-sm">
                                    <i class="fa-regular fa-copy"></i> Salin
                                </button>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">
                                <?= nl2br(e($pm['instructions'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 4. UPLOAD PROOF FORM -->
        <?php if ($order['payment_status'] !== 'pembayaran_berhasil'): ?>
            <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 16px; color: var(--primary-dark);">
                    <?= $order['payment_status'] === 'pembayaran_ditolak' ? 'Unggah Ulang Bukti Pembayaran' : 'Unggah Bukti Pembayaran' ?>
                </h3>

                <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/payment.php?order_id=<?= urlencode($order['order_code']) ?>">
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label">Metode Pembayaran yang Digunakan <span class="required">*</span></label>
                        <select name="payment_method_id" class="form-control form-select" required>
                            <?php foreach ($paymentMethods as $pm): ?>
                                <option value="<?= $pm['id'] ?>"><?= e($pm['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tangkapan Layar (Screenshot) Bukti Transfer <span class="required">*</span></label>
                        <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp" class="form-control" required>
                        <div class="form-hint">Hanya lampirkan bukti transfer resmi m-banking / e-wallet. Format JPG, PNG, atau WEBP. Maks 5MB.</div>
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" name="submit_payment_proof" class="btn btn-primary btn-lg btn-block">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Kirim Bukti Pembayaran untuk Diverifikasi
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

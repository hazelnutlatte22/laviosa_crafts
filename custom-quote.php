<?php
/**
 * Leviosa Crafts - Custom Quote & Item Preview Approval
 * Allows customer to review & accept/reject custom bouquet price quotes,
 * monitor 12-hour item arrival for custom items, and approve crafter previews.
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/capacity.php';

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
    setFlash('danger', 'Pesanan custom tidak ditemukan.');
    header("Location: " . BASE_URL . "/tracking.php");
    exit;
}

$pageTitle = "Penawaran Custom - #" . $order['order_code'];

// Fetch Custom Quote (if any)
$stmtQ = $db->prepare("SELECT * FROM custom_quotes WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$stmtQ->execute([$order['id']]);
$quote = $stmtQ->fetch();

// Fetch Order Images (Customer reference & Admin preview)
$stmtImg = $db->prepare("SELECT * FROM order_images WHERE order_id = ? ORDER BY id ASC");
$stmtImg->execute([$order['id']]);
$orderImages = $stmtImg->fetchAll();

$refImages = [];
$previewImages = [];
foreach ($orderImages as $img) {
    if ($img['image_type'] === 'customer_reference') {
        $refImages[] = $img;
    } elseif ($img['image_type'] === 'admin_preview') {
        $previewImages[] = $img;
    }
}

// -----------------------------------------------------------------------------
// POST: Customer Action on Quote (Accept / Reject)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_action'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid.');
        header("Location: " . BASE_URL . "/custom-quote.php?order_id=" . urlencode($orderCode));
        exit;
    }

    $action = $_POST['quote_action']; // 'accept' or 'reject'
    $customerNotes = trim($_POST['customer_notes'] ?? '');

    if ($action === 'accept' && $quote && $quote['status'] === 'offered') {
        $db->beginTransaction();
        try {
            $quotedPrice = (float)$quote['quoted_price'];
            $finalTotal = $quotedPrice + (float)$order['shipping_cost'];
            $deadline = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // 1. Update quote
            $db->prepare("
                UPDATE custom_quotes
                SET status = 'accepted', customer_response_at = NOW(), customer_notes = ?
                WHERE id = ?
            ")->execute([$customerNotes, $quote['id']]);

            // 2. Update order total & status to menunggu_pembayaran
            $db->prepare("
                UPDATE orders
                SET subtotal = ?,
                    total_amount = ?,
                    order_status = 'menunggu_pembayaran',
                    payment_deadline = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$quotedPrice, $finalTotal, $deadline, $order['id']]);

            // 3. Update order item snapshot
            $db->prepare("
                UPDATE order_items
                SET unit_price = ?, subtotal = ?
                WHERE order_id = ?
            ")->execute([$quotedPrice, $quotedPrice, $order['id']]);

            // 4. Log history
            logOrderStatus($order['id'], 'menunggu_pembayaran', "Pelanggan menyetujui penawaran harga (" . formatRupiah($quotedPrice) . "). Menunggu pembayaran 24 jam.", 'customer');

            $db->commit();

            setFlash('success', 'Penawaran harga disetujui! Silakan lanjutkan pembayaran dalam 24 jam.');
            header("Location: " . BASE_URL . "/payment.php?order_id=" . urlencode($orderCode));
            exit;
        } catch (\Throwable $e) {
            $db->rollBack();
            setFlash('danger', 'Gagal memproses persetujuan harga: ' . $e->getMessage());
        }
    }

    if ($action === 'reject' && $quote && $quote['status'] === 'offered') {
        $db->beginTransaction();
        try {
            // Update quote
            $db->prepare("
                UPDATE custom_quotes
                SET status = 'rejected', customer_response_at = NOW(), customer_notes = ?
                WHERE id = ?
            ")->execute([$customerNotes, $quote['id']]);

            // Cancel order & release slot
            $db->prepare("
                UPDATE orders
                SET order_status = 'dibatalkan',
                    cancellation_status = 'approved',
                    cancellation_reason = 'Pelanggan menolak penawaran harga custom.',
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$order['id']]);

            ProductionCapacity::releaseSlot($db, $order['delivery_date'], $order['order_type'], 1);

            logOrderStatus($order['id'], 'dibatalkan', "Pelanggan menolak penawaran harga custom. Alasan/Catatan: $customerNotes", 'customer');

            $db->commit();

            setFlash('info', 'Penawaran harga ditolak. Pesanan custom telah dibatalkan.');
            header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($orderCode));
            exit;
        } catch (\Throwable $e) {
            $db->rollBack();
            setFlash('danger', 'Gagal memproses pembatalan: ' . $e->getMessage());
        }
    }

    if ($action === 'approve_preview') {
        // Customer approves finished preview photo
        logOrderStatus($order['id'], $order['order_status'], "Pelanggan menyetujui foto preview hasil pengerjaan buket.", 'customer');
        setFlash('success', 'Terima kasih! Anda telah menyetujui hasil pengerjaan buket. Tim kami akan menyiapkan pengantaran.');
        header("Location: " . BASE_URL . "/custom-quote.php?order_id=" . urlencode($orderCode));
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 12px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/tracking.php?order_id=<?= urlencode($orderCode) ?>';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>

        <div style="text-align: center;">
            <span class="section-subtitle">Custom Order Review</span>
            <h1 class="section-title">Status Penawaran Custom</h1>
            <p class="section-desc">Order ID: <strong><?= e($order['order_code']) ?></strong> &bull; Tipe: <strong><?= $order['order_type'] === 'custom_item' ? 'Custom Item (Barang Sendiri)' : 'Custom Bouquet' ?></strong></p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">

        <!-- 1. CUSTOM ITEM SPECIFIC: 12-Hour Deadline & Physical Item Receipt Status -->
        <?php if ($order['order_type'] === 'custom_item'): ?>
            <div style="background: #FFFFFF; border-radius: var(--radius-md); border: 1.5px solid #FCD34D; padding: 24px; box-shadow: var(--shadow-soft); margin-bottom: 24px;">
                <h3 style="font-family: var(--font-heading); font-size: 1.15rem; color: #92400E; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-box"></i> Status Barang Fisik Anda
                </h3>
                
                <?php if ($order['item_received_at']): ?>
                    <div style="background: #ECFDF5; border: 1px solid #A7F3D0; padding: 14px 18px; border-radius: var(--radius-sm); color: #065F46; font-size: 0.9rem;">
                        <i class="fa-solid fa-circle-check"></i> <strong>Barang Telah Diterima Toko</strong> pada <?= formatDateIndo($order['item_received_at'], true) ?>. Tim crafter sedang merangkai buket Anda!
                    </div>
                <?php else: ?>
                    <div style="background: #FFFBEB; border: 1px solid #FDE68A; padding: 14px 18px; border-radius: var(--radius-sm); color: #78350F; font-size: 0.9rem;">
                        <div style="font-weight: 700; margin-bottom: 4px;">
                            <i class="fa-solid fa-clock"></i> Batas Waktu 12 Jam Pengantaran Barang Fisik:
                        </div>
                        <div>Tenggat Waktu: <strong><?= formatDateIndo($order['item_submission_deadline'], true) ?></strong></div>
                        <div style="font-size: 0.8rem; color: #92400E; margin-top: 4px;">
                            *Mohon pastikan barang tiba di alamat toko Laviosa (Ujuk Kost, Lorong Siguntang, Palembang) sebelum batas waktu tersebut agar pesanan tidak dibatalkan otomatis oleh sistem.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- 2. QUOTE STATUS -->
        <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); margin-bottom: 28px;">
            <h3 style="font-family: var(--font-heading); font-size: 1.3rem; margin-bottom: 16px; color: var(--primary-dark);">
                Penawaran Harga Resmi dari Admin
            </h3>

            <?php if ($quote): ?>
                <div style="background: #FFF9F9; border: 1.5px solid var(--primary); padding: 20px; border-radius: var(--radius-md); margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px;">
                        <span style="font-size: 0.95rem; color: var(--text-dark); font-weight: 600;">Estimasi Harga Pembuatan:</span>
                        <span style="font-size: 1.8rem; font-weight: 700; color: var(--primary-dark);"><?= formatRupiah($quote['quoted_price']) ?></span>
                    </div>

                    <?php if (!empty($quote['admin_notes'])): ?>
                        <div style="font-size: 0.88rem; color: var(--text-muted); background: #FFFFFF; padding: 12px; border-radius: 6px; border: 1px solid var(--border-color); margin-top: 8px;">
                            <strong>Catatan dari Crafter:</strong><br>
                            <?= nl2br(e($quote['admin_notes'])) ?>
                        </div>
                    <?php endif; ?>

                    <div style="font-size: 0.8rem; color: var(--text-light); margin-top: 10px;">
                        Diberikan pada: <?= formatDateIndo($quote['created_at'], true) ?> &bull; Status: <strong><?= strtoupper(e($quote['status'])) ?></strong>
                    </div>
                </div>

                <!-- Action if Quote is still 'offered' -->
                <?php if ($quote['status'] === 'offered'): ?>
                    <form method="POST" action="<?= BASE_URL ?>/custom-quote.php?order_id=<?= urlencode($orderCode) ?>">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label class="form-label">Catatan Tambahan untuk Admin (Opsional)</label>
                            <input type="text" name="customer_notes" class="form-control" placeholder="Contoh: pita boleh diganti warna soft lilac?...">
                        </div>

                        <div style="display: flex; gap: 14px; margin-top: 20px;">
                            <button type="submit" name="quote_action" value="accept" class="btn btn-primary btn-lg" style="flex: 2;">
                                <i class="fa-solid fa-check"></i> Setujui Harga &amp; Lanjut Bayar
                            </button>
                            <button type="submit" name="quote_action" value="reject" onclick="return confirm('Apakah Anda yakin ingin menolak penawaran ini? Pesanan custom akan dibatalkan.')" class="btn btn-outline btn-lg" style="flex: 1; border-color: #EF4444; color: #EF4444;">
                                <i class="fa-solid fa-xmark"></i> Tolak Penawaran
                            </button>
                        </div>
                    </form>
                <?php elseif ($quote['status'] === 'accepted'): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Anda telah menyetujui penawaran harga ini. Silakan selesaikan pembayaran.</span>
                    </div>
                    <?php if ($order['payment_status'] === 'menunggu_pembayaran'): ?>
                        <a href="<?= BASE_URL ?>/payment.php?order_id=<?= urlencode($orderCode) ?>" class="btn btn-primary btn-block">
                            <i class="fa-solid fa-receipt"></i> Buka Halaman Pembayaran
                        </a>
                    <?php endif; ?>
                <?php elseif ($quote['status'] === 'rejected'): ?>
                    <div class="alert alert-danger">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span>Penawaran harga ini telah ditolak. Pesanan berstatus dibatalkan.</span>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Pending Quote -->
                <div style="text-align: center; padding: 40px 20px; background: #F8FAFC; border-radius: var(--radius-sm);">
                    <i class="fa-solid fa-hourglass-start" style="font-size: 2.5rem; color: var(--secondary); margin-bottom: 12px;"></i>
                    <h4 style="font-size: 1.15rem; color: var(--text-dark); margin-bottom: 6px;">Admin Sedang Meninjau Permintaan</h4>
                    <p style="font-size: 0.88rem; color: var(--text-muted); max-width: 460px; margin: 0 auto;">
                        Tim Leviosa Crafts sedang menghitung bahan kawat bulu dan tingkat kesulitan kreasi Anda. Segera setelah penawaran harga selesai diinput, Anda dapat menyetujui harga pada halaman ini.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. CRAFTER PREVIEW PHOTO APPROVAL (Finished Work Preview) -->
        <?php if (!empty($previewImages)): ?>
            <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1.5px solid #A7F3D0; box-shadow: var(--shadow-soft); margin-bottom: 28px;">
                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 12px; color: #065F46; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-camera"></i> Foto Preview Hasil Pengerjaan Buket
                </h3>
                <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
                    Berikut adalah foto hasil buket custom yang telah dirakit oleh crafter kami sebelum dikemas dan dikirimkan ke penerima:
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
                    <?php foreach ($previewImages as $pImg): ?>
                        <div style="border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border-color);">
                            <img src="<?= BASE_URL . '/' . e($pImg['image_path']) ?>" alt="Preview Hasil" style="width: 100%; aspect-ratio: 1/1; object-fit: cover;">
                            <?php if (!empty($pImg['notes'])): ?>
                                <div style="padding: 8px; font-size: 0.75rem; color: var(--text-muted); background: #F8FAFC;"><?= e($pImg['notes']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="POST" action="<?= BASE_URL ?>/custom-quote.php?order_id=<?= urlencode($orderCode) ?>">
                    <?= csrfField() ?>
                    <button type="submit" name="quote_action" value="approve_preview" class="btn btn-primary btn-block" style="background: #10B981; border: none;">
                        <i class="fa-solid fa-thumbs-up"></i> Setujui Hasil Buket &amp; Lanjutkan Pengiriman
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- 4. CUSTOMER REFERENCE PHOTOS LIST -->
        <?php if (!empty($refImages)): ?>
            <div style="background: #FFFFFF; padding: 24px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                <h4 style="font-size: 1rem; color: var(--text-dark); margin-bottom: 12px;">Foto Referensi yang Anda Unggah:</h4>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <?php foreach ($refImages as $rImg): ?>
                        <img src="<?= BASE_URL . '/' . e($rImg['image_path']) ?>" alt="Referensi" style="width: 80px; height: 80px; border-radius: var(--radius-sm); object-fit: cover; border: 1px solid var(--border-color);">
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Leviosa Crafts - Order Tracking & Status Timeline
 * Tracks order progress by unique Order ID, timeline steps,
 * handles cancellation requests if order is still 'pesanan_terkonfirmasi'.
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/mailer.php';

$pageTitle = "Lacak Pesanan";
$db = getDB();

$searchedCode = strtoupper(trim($_GET['order_id'] ?? ''));
$order = null;
$orderItems = [];
$history = [];
$cancellation = null;

if (!empty($searchedCode)) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$searchedCode]);
    $order = $stmt->fetch();

    if ($order) {
        // Fetch Items
        $stmtItems = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmtItems->execute([$order['id']]);
        $orderItems = $stmtItems->fetchAll();

        // Fetch History
        $stmtHist = $db->prepare("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
        $stmtHist->execute([$order['id']]);
        $history = $stmtHist->fetchAll();

        // Fetch Cancellation Request (if any)
        $stmtCancel = $db->prepare("SELECT * FROM cancellation_requests WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmtCancel->execute([$order['id']]);
        $cancellation = $stmtCancel->fetch();
    }
}

// -----------------------------------------------------------------------------
// POST: Submit Cancellation Request
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_cancellation'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($searchedCode));
        exit;
    }

    $reason = trim($_POST['cancellation_reason'] ?? '');
    if (empty($reason)) {
        setFlash('danger', 'Mohon isi alasan pengajuan pembatalan Anda.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($searchedCode));
        exit;
    }

    if (!$order || $order['order_status'] !== 'pesanan_terkonfirmasi') {
        setFlash('danger', 'Pembatalan hanya dapat diajukan ketika pesanan masih berstatus "Pesanan Terkonfirmasi" dan belum masuk tahap pengerjaan fisik.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($searchedCode));
        exit;
    }

    $db->beginTransaction();
    try {
        $stmtC = $db->prepare("
            INSERT INTO cancellation_requests (order_id, reason, status)
            VALUES (?, ?, 'pending')
        ");
        $stmtC->execute([$order['id'], $reason]);

        $db->prepare("
            UPDATE orders
            SET cancellation_status = 'requested', cancellation_requested_at = NOW(), cancellation_reason = ?
            WHERE id = ?
        ")->execute([$reason, $order['id']]);

        logOrderStatus($order['id'], $order['order_status'], "Pelanggan mengajukan pembatalan pesanan. Alasan: $reason", 'customer');

        $db->commit();

        setFlash('success', 'Permintaan pembatalan pesanan berhasil diajukan! Admin akan meninjau dan memproses permintaan Anda.');
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($searchedCode));
        exit;
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'Gagal mengajukan pembatalan: ' . $e->getMessage());
        header("Location: " . BASE_URL . "/tracking.php?order_id=" . urlencode($searchedCode));
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow" style="text-align: center;">
        <span class="section-subtitle">Real-time Progress</span>
        <h1 class="section-title">Lacak Pesanan Anda</h1>
        <p class="section-desc">Masukkan Order ID unik untuk melihat progres pembuatan buket chenille stem, jadwal pengantaran, dan status terkini.</p>

        <!-- Search Order Bar -->
        <form method="GET" action="<?= BASE_URL ?>/tracking.php" style="max-width: 500px; margin: 24px auto 0; display: flex; gap: 10px;">
            <input type="text" name="order_id" value="<?= e($searchedCode) ?>" placeholder="Contoh: LC-20261121-7A9B" class="form-control" style="font-family: monospace; font-size: 1.05rem; text-transform: uppercase; padding: 12px 18px; border-radius: var(--radius-full);" required>
            <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">
                <i class="fa-solid fa-magnifying-glass"></i> Lacak
            </button>
        </form>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 16px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/index.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>
        <?php if (!empty($searchedCode) && !$order): ?>
            <div style="background: #FFFFFF; padding: 50px 20px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); text-align: center;">
                <i class="fa-solid fa-file-circle-question" style="font-size: 3rem; color: var(--secondary); margin-bottom: 16px;"></i>
                <h3 style="font-size: 1.25rem; margin-bottom: 8px;">Order ID Tidak Ditemukan</h3>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Pastikan Anda memasukkan kode pesanan lengkap (contoh: <code>LC-20261121-xxxx</code>) yang tercatat saat checkout.</p>
            </div>
        <?php elseif ($order): ?>
            <?php 
            $statusMeta = getOrderStatusMeta($order['order_status']);
            $timelineSteps = [
                'pesanan_terkonfirmasi' => 'Pesanan Terkonfirmasi',
                'pesanan_sedang_diproses' => 'Sedang Dirakit (Diproses)',
                'pesanan_sedang_dikirim' => 'Sedang Dikirim / Siap Pickup',
                'pesanan_selesai' => 'Pesanan Selesai'
            ];

            // Determine active step index
            $stepKeys = array_keys($timelineSteps);
            $currentStepIdx = array_search($order['order_status'], $stepKeys);
            if ($currentStepIdx === false) {
                // For custom states
                if (in_array($order['order_status'], ['menunggu_persetujuan_harga', 'menunggu_pembayaran', 'menunggu_verifikasi'])) {
                    $currentStepIdx = -1;
                } elseif ($order['order_status'] === 'pesanan_selesai') {
                    $currentStepIdx = 3;
                } else {
                    $currentStepIdx = -1;
                }
            }
            ?>

            <!-- Order Card Header -->
            <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase;">Kode Pesanan</div>
                        <h2 style="font-family: monospace; font-size: 1.6rem; font-weight: 700; color: var(--primary-dark); margin: 0;">
                            <?= e($order['order_code']) ?>
                        </h2>
                    </div>
                    <div>
                        <span class="badge <?= e($statusMeta['badge']) ?>" style="font-size: 0.88rem; padding: 6px 14px;">
                            <?= e($statusMeta['label']) ?>
                        </span>
                    </div>
                </div>

                <p style="font-size: 0.92rem; color: var(--text-muted); margin-bottom: 20px;">
                    <?= e($statusMeta['desc']) ?>
                </p>

                <!-- 4 MAIN TIMELINE STEPS -->
                <?php if ($order['order_status'] !== 'dibatalkan' && $order['order_status'] !== 'kedaluwarsa'): ?>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 30px 0; text-align: center;">
                        <?php foreach ($stepKeys as $idx => $stepKey): ?>
                            <?php 
                            $isCompleted = ($currentStepIdx !== false && $idx < $currentStepIdx) || ($order['order_status'] === 'pesanan_selesai');
                            $isActive = ($idx === $currentStepIdx);
                            ?>
                            <div style="position: relative;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; transition: var(--transition);
                                    <?= $isCompleted ? 'background:#10B981; color:#FFF;' : ($isActive ? 'background:var(--primary); color:#FFF; box-shadow:0 0 0 4px var(--primary-light);' : 'background:#F3F4F6; color:#9CA3AF;') ?>">
                                    <?php if ($isCompleted): ?>
                                        <i class="fa-solid fa-check"></i>
                                    <?php else: ?>
                                        <?= $idx + 1 ?>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.78rem; font-weight: <?= $isActive || $isCompleted ? '600' : 'normal' ?>; color: <?= $isActive ? 'var(--primary-dark)' : ($isCompleted ? '#065F46' : 'var(--text-muted)') ?>;">
                                    <?= e($timelineSteps[$stepKey]) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Cancellation Notification if any -->
                <?php if ($order['cancellation_status'] === 'requested'): ?>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <strong>Permintaan Pembatalan Sedang Ditinjau Admin</strong><br>
                            Alasan yang diajukan: <em><?= e($order['cancellation_reason']) ?></em>
                        </div>
                    </div>
                <?php elseif ($order['cancellation_status'] === 'approved'): ?>
                    <div class="alert alert-danger">
                        <i class="fa-solid fa-ban"></i>
                        <div>
                            <strong>Pesanan Ini Telah Dibatalkan</strong><br>
                            Alasan: <?= e($order['cancellation_reason'] ?: 'Dibatalkan oleh sistem/admin.') ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Detail Accordion -->
                <div style="background: #F8FAFC; border-radius: var(--radius-sm); padding: 18px; margin-top: 20px; font-size: 0.88rem; line-height: 1.6;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Nama Penerima:</span>
                        <strong><?= e($order['recipient_name']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Jadwal Pengiriman:</span>
                        <strong><?= formatDateIndo($order['delivery_date']) ?>, Pukul <?= e($order['delivery_slot']) ?> WIB</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Metode Pengiriman:</span>
                        <strong><?= $order['shipping_type'] === 'pickup' ? 'Self-Pickup (' . e(getStoreSetting('store_origin_name', 'Toko Laviosa')) . ')' : 'Delivery (' . $order['distance_km'] . ' km)' ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 8px; margin-top: 8px;">
                        <span style="font-weight: 700;">Total Biaya:</span>
                        <strong style="color: var(--primary-dark); font-size: 1.1rem;"><?= formatRupiah($order['total_amount']) ?></strong>
                    </div>
                </div>

                <!-- Action Button Group -->
                <div style="display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;">
                    <?php if ($order['payment_status'] === 'menunggu_pembayaran' && $order['order_status'] !== 'kedaluwarsa' && $order['shipping_status'] !== 'pending_admin_rate'): ?>
                        <a href="<?= BASE_URL ?>/payment.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-receipt"></i> Bayar Sekarang
                        </a>
                    <?php endif; ?>

                    <?php if ($order['order_type'] !== 'ready_design'): ?>
                        <a href="<?= BASE_URL ?>/custom-quote.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-outline btn-sm">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Lihat Detail Custom
                        </a>
                    <?php endif; ?>

                    <?php if ($order['order_status'] === 'pesanan_selesai'): ?>
                        <a href="<?= BASE_URL ?>/reviews.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-primary btn-sm" style="background: #F59E0B; border-color: #F59E0B;">
                            <i class="fa-solid fa-star"></i> Tulis Ulasan &amp; Foto Buket
                        </a>
                    <?php endif; ?>

                    <!-- WhatsApp Helper Link -->
                    <a href="<?= NotificationHelper::buildWhatsAppLink($order, 'status_inquiry') ?>" target="_blank" rel="noopener" class="btn btn-light btn-sm">
                        <i class="fa-brands fa-whatsapp" style="color: #25D366;"></i> Tanya CS via WhatsApp
                    </a>
                </div>

                <!-- CANCELLATION REQUEST BUTTON (Allowed only when 'pesanan_terkonfirmasi') -->
                <?php if ($order['order_status'] === 'pesanan_terkonfirmasi' && $order['cancellation_status'] === 'none'): ?>
                    <div style="margin-top: 28px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                        <button type="button" onclick="document.getElementById('cancelFormWrap').style.display='block';" class="btn btn-outline btn-sm" style="border-color: #EF4444; color: #EF4444;">
                            <i class="fa-solid fa-xmark"></i> Ajukan Pembatalan Pesanan
                        </button>

                        <div id="cancelFormWrap" style="display: none; margin-top: 16px; background: #FEF2F2; padding: 20px; border-radius: var(--radius-sm); border: 1px solid #FECACA;">
                            <h4 style="color: #991B1B; font-size: 0.95rem; margin-bottom: 6px;">Formulir Pengajuan Pembatalan</h4>
                            <p style="font-size: 0.8rem; color: #7F1D1D; margin-bottom: 12px;">
                                Pembatalan hanya dapat disetujui jika pesanan belum masuk tahap perakitan fisik oleh crafter. Pengembalian dana (refund) akan diproses oleh admin sesuai ketentuan.
                            </p>
                            <form method="POST" action="<?= BASE_URL ?>/tracking.php?order_id=<?= urlencode($order['order_code']) ?>">
                                <?= csrfField() ?>
                                <div class="form-group">
                                    <label class="form-label" style="font-size: 0.85rem;">Alasan Pembatalan <span class="required">*</span></label>
                                    <textarea name="cancellation_reason" rows="2" class="form-control" required placeholder="Tuliskan alasan pengajuan pembatalan..."></textarea>
                                </div>
                                <button type="submit" name="request_cancellation" class="btn btn-danger btn-sm">
                                    Kirim Pengajuan Pembatalan
                                </button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($order['order_status'] === 'pesanan_sedang_diproses'): ?>
                    <div style="margin-top: 20px; font-size: 0.78rem; color: var(--text-muted); background: #F8FAFC; padding: 10px 14px; border-radius: 6px;">
                        <i class="fa-solid fa-lock"></i> <em>Pesanan sedang dirakit oleh crafter. Sesuai ketentuan, pesanan yang sedang diproses tidak dapat dibatalkan.</em>
                    </div>
                <?php endif; ?>

            </div>

            <!-- History Log Timeline -->
            <?php if (!empty($history)): ?>
                <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
                    <h3 style="font-family: var(--font-heading); font-size: 1.15rem; margin-bottom: 16px; color: var(--text-dark);">
                        Riwayat Perjalanan Pesanan
                    </h3>

                    <div class="tracking-timeline">
                        <?php foreach ($history as $hItem): ?>
                            <div class="timeline-step completed">
                                <div class="timeline-dot"></div>
                                <div class="timeline-title"><?= ucwords(str_replace('_', ' ', $hItem['status'])) ?></div>
                                <div class="timeline-time"><?= formatDateIndo($hItem['created_at'], true) ?></div>
                                <?php if (!empty($hItem['note'])): ?>
                                    <div class="timeline-desc"><?= e($hItem['note']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

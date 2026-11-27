<?php
/**
 * Leviosa Crafts - Order Confirmation & Success Page
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$orderCode = trim($_GET['order_id'] ?? '');
if (empty($orderCode)) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE order_code = ?");
$stmt->execute([$orderCode]);
$order = $stmt->fetch();

if (!$order) {
    setFlash('danger', 'Pesanan tidak ditemukan.');
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$pageTitle = "Pesanan Dibuat - #" . $order['order_code'];
$isPendingShippingRate = ($order['shipping_status'] === 'pending_admin_rate');

include __DIR__ . '/includes/header.php';
?>

<div class="section-py">
    <div class="container container-narrow">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 16px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/index.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali ke Beranda</span>
            </a>
        </div>

        <div style="background: #FFFFFF; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); padding: 40px; text-align: center;">
            
            <div style="width: 70px; height: 70px; border-radius: 50%; background: #ECFDF5; color: #10B981; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; margin: 0 auto 20px;">
                <i class="fa-solid fa-check"></i>
            </div>

            <span class="badge badge-primary" style="margin-bottom: 8px;">Pesanan Berhasil Dicatat</span>
            <h1 style="font-family: var(--font-heading); font-size: 2rem; color: var(--text-dark); margin-bottom: 8px;">
                Terima Kasih, <?= e($order['customer_name']) ?>!
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 24px;">
                Pesanan buket handmade Anda telah kami terima dan masuk ke sistem Leviosa Crafts.
            </p>

            <!-- Order Code Box -->
            <div style="background: #FFF9F9; border: 2px dashed var(--primary); padding: 18px 24px; border-radius: var(--radius-md); max-width: 420px; margin: 0 auto 28px;">
                <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">Order ID Unik Anda:</div>
                <div style="font-family: monospace; font-size: 1.6rem; font-weight: 700; color: var(--primary-dark); letter-spacing: 2px;">
                    <?= e($order['order_code']) ?>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 6px;">
                    Simpan Order ID ini untuk melacak progress buket dan melakukan konfirmasi pembayaran.
                </div>
            </div>

            <!-- Pending Admin Shipping Fee Notice (If > 10 km) -->
            <?php if ($isPendingShippingRate): ?>
                <div style="background: #FFFBEB; border: 1px solid #FCD34D; padding: 18px 20px; border-radius: var(--radius-sm); text-align: left; margin-bottom: 28px;">
                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.4rem; color: #D97706; margin-top: 2px;"></i>
                        <div>
                            <strong style="color: #92400E; font-size: 0.95rem;">Menunggu Konfirmasi Biaya Ongkir (> 10 km)</strong>
                            <p style="font-size: 0.85rem; color: #78350F; margin-top: 4px; line-height: 1.5;">
                                Jarak alamat pengiriman Anda adalah <strong><?= e($order['distance_km']) ?> km</strong> (melebihi 10 km). Sesuai ketentuan, nominal ongkir akan ditentukan oleh Admin Leviosa Crafts terlebih dahulu. Anda baru dapat melakukan pembayaran setelah nominal final dikonfirmasi oleh admin.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Order Snapshot Summary -->
            <div style="background: #F8FAFC; border-radius: var(--radius-sm); padding: 20px; text-align: left; margin-bottom: 30px; border: 1px solid var(--border-color);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem;">
                    <span style="color: var(--text-muted);">Jadwal Pengiriman / Ambil:</span>
                    <strong><?= formatDateIndo($order['delivery_date']) ?>, Pukul <?= e($order['delivery_slot']) ?> WIB</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem;">
                    <span style="color: var(--text-muted);">Penerima:</span>
                    <strong><?= e($order['recipient_name']) ?> (<?= e($order['recipient_phone']) ?>)</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem;">
                    <span style="color: var(--text-muted);">Metode Pengiriman:</span>
                    <strong><?= $order['shipping_type'] === 'pickup' ? 'Self-Pickup (Ambil Sendiri di Toko Laviosa)' : 'Delivery ke Alamat Penerima' ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 10px; margin-top: 10px; font-size: 1.05rem;">
                    <strong>Total Tagihan:</strong>
                    <strong style="color: var(--primary-dark); font-size: 1.25rem;">
                        <?= $isPendingShippingRate ? formatRupiah($order['subtotal'] - $order['discount_amount']) . ' + Ongkir Pending' : formatRupiah($order['total_amount']) ?>
                    </strong>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;">
                <?php if (!$isPendingShippingRate): ?>
                    <a href="<?= BASE_URL ?>/payment.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-primary btn-lg">
                        <i class="fa-solid fa-receipt"></i> Lanjut Bayar &amp; Upload Bukti Transfer
                    </a>
                <?php endif; ?>
                
                <a href="<?= BASE_URL ?>/tracking.php?order_id=<?= urlencode($order['order_code']) ?>" class="btn btn-outline btn-lg">
                    <i class="fa-solid fa-location-crosshairs"></i> Lacak Status Pesanan
                </a>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

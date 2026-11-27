<?php
/**
 * Leviosa Crafts - Custom Bouquet Request Page
 * Customer submits custom specifications and reference photos for admin quotation
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/upload.php';
require_once __DIR__ . '/app/helpers/capacity.php';
require_once __DIR__ . '/app/helpers/distance.php';

$pageTitle = "Pesan Custom Bouquet";
$db = getDB();

$slots = $db->query("SELECT slot_time FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_custom_bouquet'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid. Silakan coba kembali.');
        header("Location: " . BASE_URL . "/custom-bouquet.php");
        exit;
    }

    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $recipientPhone = trim($_POST['recipient_phone'] ?? '');
    $recipientAddress = trim($_POST['recipient_address'] ?? '');
    $recipientLat = !empty($_POST['recipient_lat']) ? (float)$_POST['recipient_lat'] : null;
    $recipientLng = !empty($_POST['recipient_lng']) ? (float)$_POST['recipient_lng'] : null;
    $shippingType = $_POST['shipping_type'] ?? 'delivery';
    $deliveryDate = trim($_POST['delivery_date'] ?? '');
    $deliverySlot = trim($_POST['delivery_slot'] ?? '');
    $customDescription = trim($_POST['custom_description'] ?? '');
    $greetingCard = trim($_POST['greeting_card'] ?? '');
    $orderNotes = trim($_POST['order_notes'] ?? '');

    // Validate Required Fields
    if (empty($customerName) || empty($customerEmail) || empty($customerPhone) ||
        empty($recipientName) || empty($recipientPhone) || empty($recipientAddress) ||
        empty($deliveryDate) || empty($deliverySlot) || empty($customDescription)) {
        setFlash('danger', 'Mohon lengkapi seluruh kolom formulir wajib yang bertanda bintang (*).');
        header("Location: " . BASE_URL . "/custom-bouquet.php");
        exit;
    }

    // Capacity & Lead time check (Custom Bouquet lead time min 2 days)
    $capCheck = ProductionCapacity::checkAvailability($deliveryDate, 'custom_bouquet', 1);
    if (!$capCheck['available']) {
        setFlash('danger', $capCheck['reason']);
        header("Location: " . BASE_URL . "/custom-bouquet.php");
        exit;
    }

    // Distance & Shipping Calculation
    $distanceResult = DistanceCalculator::calculate($recipientLat ?: DistanceCalculator::ORIGIN_LAT, $recipientLng ?: DistanceCalculator::ORIGIN_LNG, $shippingType);

    $db->beginTransaction();
    try {
        $orderCode = generateOrderCode();

        $stmt = $db->prepare("
            INSERT INTO orders (
                order_code, order_type, customer_name, customer_email, customer_phone,
                recipient_name, recipient_phone, recipient_address, recipient_lat, recipient_lng,
                shipping_type, delivery_date, delivery_slot, distance_km, shipping_cost, shipping_status,
                subtotal, discount_amount, total_amount, order_status, payment_status, order_notes
            ) VALUES (
                ?, 'custom_bouquet', ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                0.00, 0.00, 0.00, 'menunggu_persetujuan_harga', 'menunggu_pembayaran', ?
            )
        ");
        $stmt->execute([
            $orderCode, $customerName, $customerEmail, $customerPhone,
            $recipientName, $recipientPhone, $recipientAddress, $recipientLat, $recipientLng,
            $shippingType, $deliveryDate, $deliverySlot, $distanceResult['distance_km'], $distanceResult['shipping_cost'], $distanceResult['status'],
            $orderNotes
        ]);
        $orderId = (int)$db->lastInsertId();

        // Order Item Snapshot
        $stmtItem = $db->prepare("
            INSERT INTO order_items (order_id, product_name, product_code, quantity, unit_price, subtotal, greeting_card, item_notes)
            VALUES (?, 'Custom Bouquet Request', 'CUSTOM-BOUQUET', 1, 0.00, 0.00, ?, ?)
        ");
        $stmtItem->execute([$orderId, $greetingCard, $orderNotes]);
        $orderItemId = (int)$db->lastInsertId();

        // Customization
        $stmtCust = $db->prepare("
            INSERT INTO order_item_customizations (order_item_id, custom_description)
            VALUES (?, ?)
        ");
        $stmtCust->execute([$orderItemId, $customDescription]);

        // Upload Reference Photos if any
        if (!empty($_FILES['reference_images']['name'][0])) {
            $totalFiles = count($_FILES['reference_images']['name']);
            for ($i = 0; $i < $totalFiles; $i++) {
                if ($_FILES['reference_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $fileArray = [
                        'name' => $_FILES['reference_images']['name'][$i],
                        'type' => $_FILES['reference_images']['type'][$i],
                        'tmp_name' => $_FILES['reference_images']['tmp_name'][$i],
                        'error' => $_FILES['reference_images']['error'][$i],
                        'size' => $_FILES['reference_images']['size'][$i]
                    ];
                    $uploadRes = Uploader::upload($fileArray, 'assets/uploads/custom_refs/');
                    if ($uploadRes['success']) {
                        $stmtImg = $db->prepare("
                            INSERT INTO order_images (order_id, image_type, image_path, notes)
                            VALUES (?, 'customer_reference', ?, 'Foto referensi pelanggan')
                        ");
                        $stmtImg->execute([$orderId, $uploadRes['path']]);
                    }
                }
            }
        }

        // Book production capacity slot
        ProductionCapacity::bookSlot($db, $deliveryDate, 'custom_bouquet', 1);

        // Status history log
        logOrderStatus($orderId, 'menunggu_persetujuan_harga', 'Permintaan Custom Bouquet diajukan oleh pelanggan. Menunggu estimasi harga admin.', 'customer');

        $db->commit();

        setFlash('success', "Permintaan Custom Bouquet Anda (#$orderCode) berhasil dikirim! Tim Leviosa Crafts akan segera meninjau dan memberikan penawaran harga.");
        header("Location: " . BASE_URL . "/custom-quote.php?order_id=" . urlencode($orderCode));
        exit;
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'Terjadi kesalahan sistem saat menyimpan pesanan custom: ' . $e->getMessage());
        header("Location: " . BASE_URL . "/custom-bouquet.php");
        exit;
    }
}

// Earliest allowed delivery date for custom is +2 days
$minCustomDate = date('Y-m-d', strtotime('+2 days'));

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow">
        <!-- Minimalist Back Button -->
        <div>
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/catalog.php?kategori=custom-order';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>

        <div style="text-align: center;">
            <span class="section-subtitle">Custom Order</span>
            <h1 class="section-title">Pesan Custom Bouquet</h1>
            <p class="section-desc">
                Wujudkan buket impian Anda dengan karakter, warna, dan tema favorit. Kirimkan foto referensi dan penjelasan konsep, tim kami akan memberikan penawaran harga terbaik.
            </p>
            <div style="margin-top: 14px;">
                <a href="<?= buildWhatsAppCustomOrderUrl('Custom Bouquet', 'Foto referensi desain sendiri') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-wa" style="display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fa-brands fa-whatsapp"></i> Atau Konsultasi Langsung via WhatsApp
                </a>
            </div>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        <div style="background: #FFFFFF; padding: 36px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
            
            <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/custom-bouquet.php">
                <?= csrfField() ?>

                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> 1. Konsep &amp; Foto Referensi
                </h3>

                <div class="form-group">
                    <label class="form-label">
                        Jelaskan Konsep Buket yang Diinginkan <span class="required">*</span>
                    </label>
                    <textarea name="custom_description" rows="4" class="form-control" placeholder="Contoh: Buket karakter Stitch dengan bunga warna pastel biru dan putih, dihiasi pita satin navy dan fairy lights LED..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Unggah Foto Referensi (Bisa beberapa foto)
                    </label>
                    <input type="file" name="reference_images[]" multiple accept="image/jpeg,image/png,image/webp" class="form-control">
                    <div class="form-hint">Format file JPG, PNG, atau WEBP. Maksimal 5MB per file.</div>
                </div>

                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin: 30px 0 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px; border-top: 1px solid var(--border-color); padding-top: 24px;">
                    <i class="fa-solid fa-user"></i> 2. Informasi Pemesan
                </h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Nama Pemesan <span class="required">*</span></label>
                        <input type="text" name="customer_name" class="form-control" required placeholder="Nama lengkap Anda">
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Pemesan <span class="required">*</span></label>
                        <input type="tel" name="customer_phone" class="form-control" required placeholder="08xxxxxxxxxx">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Pemesan <span class="required">*</span></label>
                    <input type="email" name="customer_email" class="form-control" required placeholder="email@contoh.com">
                    <div class="form-hint">Konfirmasi pesanan dan update penawaran harga akan dikirimkan ke email ini.</div>
                </div>

                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin: 30px 0 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px; border-top: 1px solid var(--border-color); padding-top: 24px;">
                    <i class="fa-solid fa-location-dot"></i> 3. Penerima &amp; Pengiriman
                </h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Nama Penerima <span class="required">*</span></label>
                        <input type="text" name="recipient_name" class="form-control" required placeholder="Nama orang yang menerima">
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Penerima <span class="required">*</span></label>
                        <input type="tel" name="recipient_phone" class="form-control" required placeholder="08xxxxxxxxxx">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Metode Pengiriman <span class="required">*</span></label>
                    <select name="shipping_type" class="form-control form-select" required>
                        <option value="delivery">Delivery ke Alamat Penerima</option>
                        <option value="pickup">Self-Pickup (Ambil Sendiri di Ujuk Kost, Lorong Siguntang - Rp0)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Alamat Lengkap Penerima <span class="required">*</span></label>
                    <textarea name="recipient_address" rows="2" class="form-control" required placeholder="Nama jalan, nomor rumah, RT/RW, kelurahan, kecamatan, patokan..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Tanggal Dibutuhkan <span class="required">*</span></label>
                        <input type="date" name="delivery_date" class="form-control" min="<?= $minCustomDate ?>" value="<?= $minCustomDate ?>" required>
                        <div class="form-hint">Lead time pembuatan Custom Bouquet minimal 2-3 hari.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pilihan Slot Waktu <span class="required">*</span></label>
                        <select name="delivery_slot" class="form-control form-select" required>
                            <?php foreach ($slots as $sl): ?>
                                <option value="<?= e($sl) ?>"><?= e($sl) ?> WIB</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                        <label class="form-label" style="margin-bottom: 0;">Kartu Ucapan (Greeting Card)</label>
                        <button type="button" class="btn-ai-card-trigger btn-open-ai-card" data-target="customGreetingCardText" title="Buat ucapan otomatis dengan AI Assistant">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> AI Card Assistant
                        </button>
                    </div>
                    <textarea name="greeting_card" id="customGreetingCardText" rows="3" class="form-control" placeholder="Pesan ucapan yang ingin dicetak pada kartu buket (opsional)..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Tambahan</label>
                    <input type="text" name="order_notes" class="form-control" placeholder="Catatan jam tiba, kejutan khusus, dll (opsional)...">
                </div>

                <div style="margin-top: 30px;">
                    <button type="submit" name="submit_custom_bouquet" class="btn btn-primary btn-lg btn-block">
                        <i class="fa-solid fa-paper-plane"></i> Kirim Permintaan Custom Bouquet
                    </button>
                    <p style="text-align: center; font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">
                        *Admin akan meninjau permintaan dan Anda akan menerima notifikasi harga untuk disetujui sebelum pembayaran.
                    </p>
                </div>
            </form>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

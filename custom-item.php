<?php
/**
 * Leviosa Crafts - Custom Item Request Page
 * Customer integrates their own physical gift into a custom chenille stem bouquet
 * Enforces 12-hour physical item delivery deadline to store address
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/upload.php';
require_once __DIR__ . '/app/helpers/capacity.php';
require_once __DIR__ . '/app/helpers/distance.php';

$pageTitle = "Pesan Custom Item (Bawa Barang Sendiri)";
$db = getDB();

$storeAddress = getStoreSetting('store_address', 'Ujuk Kost, Lorong Siguntang, Bukit Lama, Kec. Ilir Barat I, Kota Palembang, Sumatera Selatan 30128, Indonesia.');
$slots = $db->query("SELECT slot_time FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_custom_item'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid. Silakan coba kembali.');
        header("Location: " . BASE_URL . "/custom-item.php");
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
    $itemDescription = trim($_POST['item_description'] ?? '');
    $itemDispatchMethod = trim($_POST['item_dispatch_method'] ?? 'Antar Langsung ke Toko');
    $greetingCard = trim($_POST['greeting_card'] ?? '');
    $orderNotes = trim($_POST['order_notes'] ?? '');

    // Validate Required Fields
    if (empty($customerName) || empty($customerEmail) || empty($customerPhone) ||
        empty($recipientName) || empty($recipientPhone) || empty($recipientAddress) ||
        empty($deliveryDate) || empty($deliverySlot) || empty($itemDescription)) {
        setFlash('danger', 'Mohon lengkapi seluruh kolom formulir wajib yang bertanda bintang (*).');
        header("Location: " . BASE_URL . "/custom-item.php");
        exit;
    }

    // Capacity & Lead time check (Custom Item lead time min 2 days)
    $capCheck = ProductionCapacity::checkAvailability($deliveryDate, 'custom_item', 1);
    if (!$capCheck['available']) {
        setFlash('danger', $capCheck['reason']);
        header("Location: " . BASE_URL . "/custom-item.php");
        exit;
    }

    // Distance & Shipping Calculation
    $distanceResult = DistanceCalculator::calculate($recipientLat ?: DistanceCalculator::ORIGIN_LAT, $recipientLng ?: DistanceCalculator::ORIGIN_LNG, $shippingType);

    // Calculate 12-hour deadline from right now
    $submissionDeadline = date('Y-m-d H:i:s', strtotime('+12 hours'));

    $db->beginTransaction();
    try {
        $orderCode = generateOrderCode();

        $stmt = $db->prepare("
            INSERT INTO orders (
                order_code, order_type, customer_name, customer_email, customer_phone,
                recipient_name, recipient_phone, recipient_address, recipient_lat, recipient_lng,
                shipping_type, delivery_date, delivery_slot, distance_km, shipping_cost, shipping_status,
                subtotal, discount_amount, total_amount, order_status, payment_status,
                item_submission_deadline, order_notes
            ) VALUES (
                ?, 'custom_item', ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                0.00, 0.00, 0.00, 'menunggu_persetujuan_harga', 'menunggu_pembayaran',
                ?, ?
            )
        ");
        $stmt->execute([
            $orderCode, $customerName, $customerEmail, $customerPhone,
            $recipientName, $recipientPhone, $recipientAddress, $recipientLat, $recipientLng,
            $shippingType, $deliveryDate, $deliverySlot, $distanceResult['distance_km'], $distanceResult['shipping_cost'], $distanceResult['status'],
            $submissionDeadline,
            "Pengiriman barang oleh customer: $itemDispatchMethod. " . $orderNotes
        ]);
        $orderId = (int)$db->lastInsertId();

        // Order Item Snapshot
        $stmtItem = $db->prepare("
            INSERT INTO order_items (order_id, product_name, product_code, quantity, unit_price, subtotal, greeting_card, item_notes)
            VALUES (?, 'Custom Item Bouquet (Barang Sendiri)', 'CUSTOM-ITEM', 1, 0.00, 0.00, ?, ?)
        ");
        $stmtItem->execute([$orderId, $greetingCard, $orderNotes]);
        $orderItemId = (int)$db->lastInsertId();

        // Customization
        $stmtCust = $db->prepare("
            INSERT INTO order_item_customizations (order_item_id, custom_description)
            VALUES (?, ?)
        ");
        $stmtCust->execute([$orderItemId, $itemDescription]);

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
                            VALUES (?, 'customer_reference', ?, 'Foto referensi barang fisik')
                        ");
                        $stmtImg->execute([$orderId, $uploadRes['path']]);
                    }
                }
            }
        }

        // Book production capacity slot
        ProductionCapacity::bookSlot($db, $deliveryDate, 'custom_item', 1);

        // Status history log
        logOrderStatus($orderId, 'menunggu_persetujuan_harga', "Permintaan Custom Item dibuat. Batas waktu penyerahan barang fisik: $submissionDeadline (12 jam).", 'customer');

        $db->commit();

        setFlash('success', "Pesanan Custom Item (#$orderCode) berhasil dibuat! Harap kirimkan barang fisik Anda sebelum batas waktu 12 jam.");
        header("Location: " . BASE_URL . "/custom-quote.php?order_id=" . urlencode($orderCode));
        exit;
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        header("Location: " . BASE_URL . "/custom-item.php");
        exit;
    }
}

$minCustomDate = date('Y-m-d', strtotime('+2 days'));

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 12px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/catalog.php?kategori=custom-order';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>

        <div style="text-align: center;">
            <span class="section-subtitle">Custom Item Order</span>
            <h1 class="section-title">Pesan Custom Item (Bawa Barang Sendiri)</h1>
            <p class="section-desc">
                Gabungkan hadiah spesial milik Anda—seperti tumbler, parfum, perhiasan, boneka pribadi, atau skincare—ke dalam rangkaian buket chenille stem eksklusif Laviosa.
            </p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        
        <!-- Important 12-Hour Deadline Notice Box -->
        <div style="background: #FFFBEB; border: 1.5px solid #FCD34D; border-radius: var(--radius-md); padding: 20px; margin-bottom: 28px; display: flex; gap: 16px; align-items: flex-start;">
            <div style="font-size: 1.8rem; color: #D97706;"><i class="fa-solid fa-clock"></i></div>
            <div>
                <h4 style="color: #92400E; margin-bottom: 4px; font-size: 1.05rem;">Ketentuan Penting Custom Item: Batas Waktu 12 Jam</h4>
                <p style="font-size: 0.88rem; color: #78350F; line-height: 1.5; margin-bottom: 8px;">
                    Pelanggan wajib mengantarkan atau mengirimkan barang fisik miliknya ke alamat toko Leviosa Crafts <strong>maksimal 12 jam</strong> sejak pesanan dibuat. Apabila barang belum diterima dan dikonfirmasi admin melewati batas waktu tersebut, sistem akan membatalkan pesanan secara otomatis demi efisiensi jadwal produksi.
                </p>
                <div style="font-size: 0.85rem; background: #FEF3C7; padding: 8px 12px; border-radius: 6px; color: #78350F;">
                    <i class="fa-solid fa-location-dot"></i> <strong>Alamat Toko / Titik Drop-Off:</strong> <?= e($storeAddress) ?>
                </div>
            </div>
        </div>

        <div style="background: #FFFFFF; padding: 36px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
            <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/custom-item.php">
                <?= csrfField() ?>

                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-gift"></i> 1. Informasi Barang Fisik Anda
                </h3>

                <div class="form-group">
                    <label class="form-label">
                        Deskripsi Barang Fisik yang Ingin Dimasukkan <span class="required">*</span>
                    </label>
                    <textarea name="item_description" rows="3" class="form-control" placeholder="Contoh: 1 buah tumbler Corkcicle warna sage green ukuran 16oz dan 1 kotak perhiasan kecil..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Unggah Foto Barang Fisik / Konsep Rangkaian
                    </label>
                    <input type="file" name="reference_images[]" multiple accept="image/jpeg,image/png,image/webp" class="form-control">
                    <div class="form-hint">Format file JPG, PNG, atau WEBP. Maksimal 5MB per file.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Rencana Pengiriman Barang ke Toko <span class="required">*</span></label>
                    <select name="item_dispatch_method" class="form-control form-select" required>
                        <option value="Antar Langsung ke Toko Laviosa (Ujuk Kost, Lorong Siguntang)">Antar Langsung ke Toko Laviosa (Ujuk Kost, Lorong Siguntang)</option>
                        <option value="Kirim via Ojek Online (GoSend / GrabExpress)">Kirim via Ojek Online (GoSend / GrabExpress)</option>
                        <option value="Kirim via Ekspedisi Instan">Kirim via Ekspedisi Instan</option>
                    </select>
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
                </div>

                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin: 30px 0 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px; border-top: 1px solid var(--border-color); padding-top: 24px;">
                    <i class="fa-solid fa-location-dot"></i> 3. Penerima Akhir &amp; Jadwal Selesai
                </h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Nama Penerima Buket Jadi <span class="required">*</span></label>
                        <input type="text" name="recipient_name" class="form-control" required placeholder="Nama penerima buket">
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Penerima <span class="required">*</span></label>
                        <input type="tel" name="recipient_phone" class="form-control" required placeholder="08xxxxxxxxxx">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Metode Pengiriman Hasil Jadi <span class="required">*</span></label>
                    <select name="shipping_type" class="form-control form-select" required>
                        <option value="delivery">Delivery ke Alamat Penerima</option>
                        <option value="pickup">Self-Pickup (Ambil Sendiri di Toko Laviosa - Rp0)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Alamat Lengkap Penerima <span class="required">*</span></label>
                    <textarea name="recipient_address" rows="2" class="form-control" required placeholder="Alamat pengiriman buket jadi..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Tanggal Pengiriman/Ambil <span class="required">*</span></label>
                        <input type="date" name="delivery_date" class="form-control" min="<?= $minCustomDate ?>" value="<?= $minCustomDate ?>" required>
                        <div class="form-hint">Lead time minimal 2-3 hari sejak barang diterima.</div>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 8px;">
                        <label class="form-label" style="margin-bottom: 0;">Kartu Ucapan (Greeting Card)</label>
                        <button type="button" class="btn-ai-card-trigger btn-open-ai-card" data-target="customItemGreetingCard" title="Buat ucapan otomatis dengan AI Assistant">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> AI Card Assistant
                        </button>
                    </div>
                    <textarea name="greeting_card" id="customItemGreetingCard" rows="2" class="form-control" placeholder="Pesan ucapan buket (opsional, atau gunakan rekomendasi AI di atas)..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Khusus</label>
                    <input type="text" name="order_notes" class="form-control" placeholder="Catatan khusus ke admin (opsional)...">
                </div>

                <div style="margin-top: 30px;">
                    <button type="submit" name="submit_custom_item" class="btn btn-primary btn-lg btn-block">
                        <i class="fa-solid fa-box-open"></i> Buat Pesanan Custom Item
                    </button>
                    <p style="text-align: center; font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">
                        *Sebelum buket dikirimkan, admin akan mengunggah foto preview hasil pengerjaan untuk Anda setujui.
                    </p>
                </div>

                <div style="margin-top: 18px; text-align: center;">
                    <a href="<?= buildWhatsAppCustomOrderUrl('Custom Item', 'Halo Admin Laviosa, saya ingin bertanya dan konsultasi mengenai pesanan Custom Item (bawa barang sendiri)...') ?>" target="_blank" rel="noopener" style="font-size: 0.84rem; color: var(--text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 4px;">
                        <i class="fa-brands fa-whatsapp" style="color: #25D366; font-size: 1rem;"></i> Ingin konsultasi barang fisik terlebih dahulu? Hubungi kami via WhatsApp
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

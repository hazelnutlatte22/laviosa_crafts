<?php
/**
 * Leviosa Crafts - Checkout Page
 * Collects Customer, Recipient, Leaflet Map Pin (Fasilkom Unsri origin),
 * Delivery Date (Capacity + Lead time checked), Time Slot, and Voucher Code.
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/distance.php';
require_once __DIR__ . '/app/helpers/capacity.php';
require_once __DIR__ . '/app/helpers/mailer.php';

$pageTitle = "Checkout Pesanan";
$db = getDB();

$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    setFlash('warning', 'Keranjang belanja Anda masih kosong.');
    header("Location: " . BASE_URL . "/catalog.php");
    exit;
}

// Calculate Cart Subtotal
$cartSubtotal = 0;
$totalBouquetCount = 0;
foreach ($cart as $item) {
    $cartSubtotal += (float)$item['subtotal'];
    $totalBouquetCount += (int)$item['quantity'];
}

// Fetch Active Delivery Slots
$slots = $db->query("SELECT slot_time FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);

// Ready Design Lead Time (Min 1 day)
$readyLeadDays = (int)getStoreSetting('ready_design_lead_time_days', 1);
$minDeliveryDate = date('Y-m-d', strtotime("+$readyLeadDays days"));

// -----------------------------------------------------------------------------
// POST: Process Checkout Order Creation
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid. Silakan coba kembali.');
        header("Location: " . BASE_URL . "/checkout.php");
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
    $voucherCode = strtoupper(trim($_POST['voucher_code'] ?? ''));
    $orderNotes = trim($_POST['order_notes'] ?? '');

    // 1. Server-side validation of mandatory fields
    if (empty($customerName) || empty($customerEmail) || empty($customerPhone) ||
        empty($recipientName) || empty($recipientPhone) || empty($deliveryDate) || empty($deliverySlot)) {
        setFlash('danger', 'Mohon lengkapi semua kolom bertanda bintang (*).');
        header("Location: " . BASE_URL . "/checkout.php");
        exit;
    }

    if ($shippingType === 'delivery') {
        if (empty($recipientAddress) || $recipientLat === null || $recipientLng === null) {
            setFlash('danger', 'Untuk pengiriman delivery, silakan isi alamat lengkap dan tentukan pin lokasi pada peta.');
            header("Location: " . BASE_URL . "/checkout.php");
            exit;
        }
    } else {
        // Self pickup
        $recipientAddress = "Self-Pickup di " . getStoreSetting('store_origin_name', 'Ujuk Kost, Lorong Siguntang, Palembang');
        $recipientLat = DistanceCalculator::ORIGIN_LAT;
        $recipientLng = DistanceCalculator::ORIGIN_LNG;
    }

    // 2. Server-side validation of production capacity & lead time
    $capCheck = ProductionCapacity::checkAvailability($deliveryDate, 'ready_design', $totalBouquetCount);
    if (!$capCheck['available']) {
        setFlash('danger', $capCheck['reason']);
        header("Location: " . BASE_URL . "/checkout.php");
        exit;
    }

    // 3. Server-side distance and shipping rate calculation
    $distanceResult = DistanceCalculator::calculate((float)$recipientLat, (float)$recipientLng, $shippingType);
    $distanceKm = $distanceResult['distance_km'];
    $shippingCost = $distanceResult['shipping_cost'];
    $shippingStatus = $distanceResult['status']; // 'calculated' or 'pending_admin_rate'

    // 4. Server-side voucher calculation
    $discountAmount = 0.0;
    $validVoucher = null;
    if (!empty($voucherCode)) {
        $stmtV = $db->prepare("SELECT * FROM vouchers WHERE code = ? AND is_active = 1");
        $stmtV->execute([$voucherCode]);
        $validVoucher = $stmtV->fetch();

        $today = date('Y-m-d');
        if ($validVoucher && $today >= $validVoucher['start_date'] && $today <= $validVoucher['end_date'] &&
            $cartSubtotal >= (float)$validVoucher['min_order_amount'] &&
            ($validVoucher['usage_limit'] == 0 || $validVoucher['used_count'] < $validVoucher['usage_limit'])) {
            
            if ($validVoucher['discount_type'] === 'percentage') {
                $discountAmount = ($cartSubtotal * (float)$validVoucher['discount_value']) / 100.0;
                if ($validVoucher['max_discount_amount'] > 0) {
                    $discountAmount = min($discountAmount, (float)$validVoucher['max_discount_amount']);
                }
            } else {
                $discountAmount = (float)$validVoucher['discount_value'];
            }
            $discountAmount = min($discountAmount, $cartSubtotal);
        }
    }

    $finalTotal = max(0, ($cartSubtotal - $discountAmount) + $shippingCost);
    $paymentDeadline = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $orderStatus = ($shippingStatus === 'pending_admin_rate') ? 'menunggu_pembayaran' : 'menunggu_pembayaran';

    // 5. Atomic Database Transaction
    $db->beginTransaction();
    try {
        $orderCode = generateOrderCode();

        $stmt = $db->prepare("
            INSERT INTO orders (
                order_code, order_type, customer_name, customer_email, customer_phone,
                recipient_name, recipient_phone, recipient_address, recipient_lat, recipient_lng,
                shipping_type, delivery_date, delivery_slot, distance_km, shipping_cost, shipping_status,
                subtotal, discount_amount, voucher_code, total_amount, order_status, payment_status,
                payment_deadline, order_notes
            ) VALUES (
                ?, 'ready_design', ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, 'menunggu_pembayaran',
                ?, ?
            )
        ");
        $stmt->execute([
            $orderCode, $customerName, $customerEmail, $customerPhone,
            $recipientName, $recipientPhone, $recipientAddress, $recipientLat, $recipientLng,
            $shippingType, $deliveryDate, $deliverySlot, $distanceKm, $shippingCost, $shippingStatus,
            $cartSubtotal, $discountAmount, $validVoucher ? $validVoucher['code'] : null, $finalTotal,
            $orderStatus, $paymentDeadline, $orderNotes
        ]);
        $orderId = (int)$db->lastInsertId();

        // Insert Order Items and Customization Snapshots
        $stmtItem = $db->prepare("
            INSERT INTO order_items (
                order_id, product_id, product_name, product_code, size_name,
                quantity, unit_price, subtotal, greeting_card, item_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtCustom = $db->prepare("
            INSERT INTO order_item_customizations (
                order_item_id, primary_color, secondary_color, wrapping_option,
                doll_name, flower_type, chocolate_qty, selected_addons_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($cart as $item) {
            $stmtItem->execute([
                $orderId,
                $item['product_id'] ?? null,
                $item['product_name'],
                $item['product_code'] ?? null,
                $item['size_name'] ?? 'Medium',
                $item['quantity'],
                $item['unit_price'],
                $item['subtotal'],
                $item['greeting_card'] ?? null,
                $item['item_notes'] ?? null
            ]);
            $orderItemId = (int)$db->lastInsertId();

            $addonsJson = !empty($item['customization']['addons']) ? json_encode($item['customization']['addons']) : null;
            $stmtCustom->execute([
                $orderItemId,
                $item['customization']['primary_color'] ?? null,
                $item['customization']['secondary_color'] ?? null,
                $item['customization']['wrapping_option'] ?? null,
                $item['customization']['doll_name'] ?? null,
                $item['customization']['flower_type'] ?? null,
                $item['customization']['chocolate_qty'] ?? 0,
                $addonsJson
            ]);
        }

        // Voucher usage record
        if ($validVoucher && $discountAmount > 0) {
            $stmtVU = $db->prepare("INSERT INTO voucher_usages (voucher_id, order_id, discount_applied) VALUES (?, ?, ?)");
            $stmtVU->execute([$validVoucher['id'], $orderId, $discountAmount]);
            $db->prepare("UPDATE vouchers SET used_count = used_count + 1 WHERE id = ?")->execute([$validVoucher['id']]);
        }

        // Book production capacity slot
        ProductionCapacity::bookSlot($db, $deliveryDate, 'ready_design', $totalBouquetCount);

        // History log
        $initialNote = ($shippingStatus === 'pending_admin_rate')
            ? "Pesanan dibuat. Jarak pengiriman > 10 km ({$distanceKm} km), menunggu konfirmasi ongkir admin."
            : "Pesanan dibuat. Menunggu pembayaran dari pelanggan dalam 24 jam.";
        logOrderStatus($orderId, 'menunggu_pembayaran', $initialNote, 'customer');

        // Send Email Confirmation
        $orderData = [
            'id' => $orderId,
            'order_code' => $orderCode,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'delivery_date' => $deliveryDate,
            'delivery_slot' => $deliverySlot,
            'shipping_type' => $shippingType,
            'total_amount' => $finalTotal
        ];
        NotificationHelper::sendOrderConfirmation($orderData);

        $db->commit();

        // Clear Cart
        unset($_SESSION['cart']);

        setFlash('success', "Pesanan #$orderCode berhasil dibuat! Silakan selesaikan pembayaran sebelum batas waktu.");
        header("Location: " . BASE_URL . "/order-success.php?order_id=" . urlencode($orderCode));
        exit;
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'Gagal memproses pesanan: ' . $e->getMessage());
        header("Location: " . BASE_URL . "/checkout.php");
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<!-- Leaflet CSS & JS for Interactive Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow" style="text-align: center;">
        <span class="section-subtitle">Penyelesaian Pesanan</span>
        <h1 class="section-title">Checkout Pengiriman</h1>
        <p class="section-desc">Lengkapi data pemesan, alamat penerima, titik pin lokasi peta, dan jadwal pengiriman buket Anda.</p>
    </div>
</div>

<div class="section-py">
    <div class="container">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 16px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/cart.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali ke Keranjang</span>
            </a>
        </div>

        <form method="POST" id="checkoutForm" action="<?= BASE_URL ?>/checkout.php">
            <?= csrfField() ?>

            <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 36px; align-items: flex-start;">
                
                <!-- Left: Form Steps -->
                <div>
                    <!-- Step 1: Customer Info -->
                    <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); margin-bottom: 24px;">
                        <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-user"></i> 1. Informasi Pemesan
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
                            <div class="form-hint">Bukti konfirmasi dan status pesanan akan dikirimkan ke email ini.</div>
                        </div>
                    </div>

                    <!-- Step 2: Recipient & Shipping Method -->
                    <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); margin-bottom: 24px;">
                        <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-truck-fast"></i> 2. Penerima &amp; Pengiriman
                        </h3>

                        <div class="form-group">
                            <label class="form-label">Pilihan Metode Pengiriman <span class="required">*</span></label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <label style="cursor: pointer;">
                                    <input type="radio" name="shipping_type" value="delivery" checked class="shipping-type-radio" style="margin-right: 6px;">
                                    <strong>Delivery ke Alamat</strong>
                                    <div style="font-size: 0.78rem; color: var(--text-muted);">Diantar kurir dengan tarif rute jalan riil</div>
                                </label>
                                <label style="cursor: pointer;">
                                    <input type="radio" name="shipping_type" value="pickup" class="shipping-type-radio" style="margin-right: 6px;">
                                    <strong>Self-Pickup (Rp0)</strong>
                                    <div style="font-size: 0.78rem; color: #10B981; font-weight: 600;">Gratis Ongkir di Toko Laviosa</div>
                                </label>
                            </div>
                        </div>

                        <!-- Recipient Fields -->
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

                        <!-- Delivery Address & Map Pin Container -->
                        <div id="deliveryAddressSection">
                            <div class="form-group">
                                <label class="form-label">Alamat Lengkap Penerima <span class="required">*</span></label>
                                <textarea name="recipient_address" id="recipientAddressInput" rows="2" class="form-control" placeholder="Nama jalan, nomor rumah, RT/RW, kelurahan, kecamatan, patokan..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>Tandai Pin Lokasi pada Peta <span class="required">*</span></span>
                                    <span style="font-size: 0.75rem; color: var(--primary); font-weight: normal;">
                                        <i class="fa-solid fa-crosshairs"></i> Geser pin ke titik penerima
                                    </span>
                                </label>
                                
                                <div id="map" style="height: 280px; border-radius: var(--radius-sm); border: 1.5px solid var(--border-color); z-index: 1;"></div>
                                
                                <input type="hidden" name="recipient_lat" id="recipientLat" value="-2.9856">
                                <input type="hidden" name="recipient_lng" id="recipientLng" value="104.7323">

                                <!-- Real-time Distance & Shipping Rate Alert -->
                                <div id="shippingCalculationBox" style="margin-top: 12px; background: #F8FAFC; border: 1px solid var(--border-color); padding: 12px 16px; border-radius: var(--radius-sm); font-size: 0.88rem; display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <span id="distanceText"><i class="fa-solid fa-route"></i> Titik asal: Toko Laviosa (Ujuk Kost Lorong Siguntang)</span>
                                    </div>
                                    <div>
                                        <strong id="shippingCostText" style="color: var(--primary-dark); font-size: 1rem;">Memuat...</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pickup Notice Box -->
                        <div id="pickupNoticeSection" style="display: none; background: #ECFDF5; border: 1px solid #A7F3D0; padding: 16px; border-radius: var(--radius-sm); margin-top: 10px;">
                            <h4 style="color: #065F46; font-size: 0.95rem; margin-bottom: 4px;">
                                <i class="fa-solid fa-circle-check"></i> Lokasi Pengambilan (Self-Pickup)
                            </h4>
                            <p style="font-size: 0.85rem; color: #047857; line-height: 1.5; margin: 0;">
                                <?= e(getStoreSetting('store_address')) ?><br>
                                <strong>Petunjuk:</strong> <?= e(getStoreSetting('pickup_instructions')) ?>
                            </p>
                        </div>
                    </div>

                    <!-- Step 3: Schedule & Slot -->
                    <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
                        <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 16px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-calendar-check"></i> 3. Jadwal &amp; Slot Waktu Pengiriman
                        </h3>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label class="form-label">Tanggal Pengiriman <span class="required">*</span></label>
                                <input type="date" name="delivery_date" id="deliveryDateInput" class="form-control" min="<?= $minDeliveryDate ?>" value="<?= $minDeliveryDate ?>" required>
                                <div class="form-hint" id="capacityFeedback">Lead time minimal 1 hari kerja (H+1).</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Slot Waktu Pengantaran <span class="required">*</span></label>
                                <select name="delivery_slot" id="deliverySlotSelect" class="form-control form-select" required>
                                    <?php foreach ($slots as $sl): ?>
                                        <option value="<?= e($sl) ?>"><?= e($sl) ?> WIB</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Catatan Tambahan untuk Pesanan (Opsional)</label>
                            <input type="text" name="order_notes" class="form-control" placeholder="Contoh: mohon hubungi nomor penerima sebelum tiba di lokasi...">
                        </div>
                    </div>
                </div>

                <!-- Right: Order Summary, Voucher & Place Order -->
                <div>
                    <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); position: sticky; top: 100px;">
                        <h3 style="font-family: var(--font-heading); font-size: 1.3rem; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                            Ringkasan Checkout
                        </h3>

                        <!-- Items Review list -->
                        <div style="max-height: 220px; overflow-y: auto; margin-bottom: 16px; padding-right: 4px;">
                            <?php foreach ($cart as $item): ?>
                                <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 8px; border-bottom: 1px dashed var(--border-color); padding-bottom: 6px;">
                                    <div>
                                        <strong><?= e($item['product_name']) ?></strong> (<?= (int)$item['quantity'] ?>x)
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($item['size_name']) ?></div>
                                    </div>
                                    <div style="font-weight: 600; color: var(--text-dark);">
                                        <?= formatRupiah($item['subtotal']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Voucher Code Form -->
                        <div style="margin-bottom: 20px;">
                            <label class="form-label" style="font-size: 0.85rem;">Punya Kode Promo / Voucher?</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" name="voucher_code" id="voucherCodeInput" placeholder="KODE VOUCHER" class="form-control" style="text-transform: uppercase; font-size: 0.85rem; padding: 8px 12px;">
                                <button type="button" id="applyVoucherBtn" class="btn btn-outline btn-sm">Terapkan</button>
                            </div>
                            <div id="voucherFeedback" style="font-size: 0.78rem; margin-top: 4px;"></div>
                        </div>

                        <!-- Calculations -->
                        <div style="display: flex; justify-content: space-between; font-size: 0.95rem; margin-bottom: 8px;">
                            <span style="color: var(--text-muted);">Subtotal Produk:</span>
                            <span style="font-weight: 600;" id="summarySubtotal" data-subtotal="<?= $cartSubtotal ?>"><?= formatRupiah($cartSubtotal) ?></span>
                        </div>

                        <div style="display: flex; justify-content: space-between; font-size: 0.95rem; margin-bottom: 8px;">
                            <span style="color: var(--text-muted);">Diskon Voucher:</span>
                            <span style="font-weight: 600; color: #10B981;" id="summaryDiscount">-Rp0</span>
                        </div>

                        <div style="display: flex; justify-content: space-between; font-size: 0.95rem; margin-bottom: 16px;">
                            <span style="color: var(--text-muted);">Ongkos Kirim:</span>
                            <span style="font-weight: 600;" id="summaryShipping">Menghitung...</span>
                        </div>

                        <div style="border-top: 1.5px dashed var(--border-color); padding-top: 16px; margin-top: 14px; display: flex; justify-content: space-between; align-items: baseline;">
                            <span style="font-weight: 700; font-size: 1.1rem;">Total Pembayaran:</span>
                            <span style="font-size: 1.45rem; font-weight: 700; color: var(--primary-dark);" id="summaryGrandTotal">
                                <?= formatRupiah($cartSubtotal) ?>
                            </span>
                        </div>

                        <div style="margin-top: 24px;">
                            <button type="submit" name="place_order" id="placeOrderBtn" class="btn btn-primary btn-lg btn-block">
                                <i class="fa-solid fa-lock"></i> Buat Pesanan Sekarang
                            </button>
                        </div>

                        <div style="margin-top: 16px; font-size: 0.78rem; color: var(--text-muted); text-align: center; line-height: 1.4;">
                            *Pesanan akan otomatis kedaluwarsa jika belum dibayar dalam 24 jam.
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>

<script>
    window.LEVIOSA_CONFIG = {
        baseUrl: '<?= BASE_URL ?>',
        originLat: <?= DistanceCalculator::ORIGIN_LAT ?>,
        originLng: <?= DistanceCalculator::ORIGIN_LNG ?>,
        cartSubtotal: <?= $cartSubtotal ?>,
        bouquetCount: <?= $totalBouquetCount ?>
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/checkout.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>

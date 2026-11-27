<?php
/**
 * Leviosa Crafts - Shopping Cart Page
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "Keranjang Belanja";

// Handle Actions: Update Qty or Remove
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid.');
        header("Location: " . BASE_URL . "/cart.php");
        exit;
    }

    $action = $_POST['action'] ?? '';
    $cartItemId = $_POST['cart_item_id'] ?? '';

    if ($action === 'remove' && !empty($cartItemId)) {
        if (!empty($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $k => $item) {
                if (($item['cart_item_id'] ?? '') === $cartItemId) {
                    unset($_SESSION['cart'][$k]);
                    $_SESSION['cart'] = array_values($_SESSION['cart']);
                    setFlash('success', 'Item berhasil dihapus dari keranjang.');
                    break;
                }
            }
        }
        header("Location: " . BASE_URL . "/cart.php");
        exit;
    }

    if ($action === 'update_qty' && !empty($cartItemId)) {
        $newQty = max(1, (int)($_POST['quantity'] ?? 1));
        if (!empty($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as &$item) {
                if (($item['cart_item_id'] ?? '') === $cartItemId) {
                    $item['quantity'] = $newQty;
                    $item['subtotal'] = $item['unit_price'] * $newQty;
                    setFlash('success', 'Jumlah pesanan berhasil diperbarui.');
                    break;
                }
            }
        }
        header("Location: " . BASE_URL . "/cart.php");
        exit;
    }
}

$cart = $_SESSION['cart'] ?? [];
$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += (float)($item['subtotal'] ?? 0);
}

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow" style="text-align: center;">
        <span class="section-subtitle">Keranjang Anda</span>
        <h1 class="section-title">Keranjang Belanja</h1>
        <p class="section-desc">Periksa kembali detail buket, kustomisasi warna, karakter boneka, dan kartu ucapan Anda sebelum checkout.</p>
    </div>
</div>

<div class="section-py">
    <div class="container">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 16px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/catalog.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali Belanja</span>
            </a>
        </div>

        <?php if (!empty($cart)): ?>
            <div style="display: grid; grid-template-columns: 1.8fr 1fr; gap: 32px; align-items: flex-start;">
                
                <!-- Left: List of Items in Cart -->
                <div>
                    <div style="background: #FFFFFF; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); overflow: hidden;">
                        <?php foreach ($cart as $index => $item): ?>
                            <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: grid; grid-template-columns: 90px 1fr auto; gap: 20px; align-items: flex-start;">
                                <!-- Image -->
                                <img src="<?= BASE_URL . '/' . e($item['main_image']) ?>" alt="<?= e($item['product_name']) ?>" style="width: 90px; height: 90px; border-radius: var(--radius-sm); object-fit: cover; border: 1px solid var(--border-color);">

                                <!-- Details -->
                                <div>
                                    <h3 style="font-family: var(--font-heading); font-size: 1.15rem; color: var(--text-dark); margin-bottom: 4px;">
                                        <?= e($item['product_name']) ?>
                                    </h3>
                                    <div style="font-size: 0.85rem; color: var(--primary-dark); font-weight: 600; margin-bottom: 8px;">
                                        Ukuran: <?= e($item['size_name']) ?> &bull; Satuan: <?= formatRupiah($item['unit_price']) ?>
                                    </div>

                                    <!-- Customization snapshot tags -->
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                                        <?php if (!empty($item['customization']['primary_color'])): ?>
                                            <span class="badge badge-info">Warna: <?= e($item['customization']['primary_color']) ?><?= !empty($item['customization']['secondary_color']) ? ' + ' . e($item['customization']['secondary_color']) : '' ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['customization']['doll_name'])): ?>
                                            <span class="badge badge-warning">Boneka: <?= e($item['customization']['doll_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['customization']['wrapping_option'])): ?>
                                            <span class="badge badge-secondary">Wrapping: <?= e($item['customization']['wrapping_option']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['customization']['flower_type'])): ?>
                                            <span class="badge badge-primary">Jenis Bunga: <?= e($item['customization']['flower_type']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['customization']['chocolate_qty'])): ?>
                                            <span class="badge badge-warning">Cokelat: <?= e($item['customization']['chocolate_qty']) ?> batang</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Selected Add-ons -->
                                    <?php if (!empty($item['customization']['addons'])): ?>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 6px;">
                                            <strong>Add-on:</strong>
                                            <?php 
                                            $addonNames = array_map(function($a) {
                                                return e($a['name']) . ' (+' . formatRupiah($a['price']) . ')';
                                            }, $item['customization']['addons']);
                                            echo implode(', ', $addonNames);
                                            ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Greeting card -->
                                    <?php if (!empty($item['greeting_card'])): ?>
                                        <div style="font-size: 0.8rem; background: #FFF9F9; padding: 6px 10px; border-radius: 6px; border-left: 3px solid var(--primary); margin-top: 6px;">
                                            <i class="fa-solid fa-envelope-open-text" style="color: var(--primary);"></i> <em>"<?= e($item['greeting_card']) ?>"</em>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Notes -->
                                    <?php if (!empty($item['item_notes'])): ?>
                                        <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">
                                            <strong>Catatan:</strong> <?= e($item['item_notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Qty & Subtotal & Remove -->
                                <div style="text-align: right;">
                                    <div style="font-size: 1.15rem; font-weight: 700; color: var(--primary-dark); margin-bottom: 10px;">
                                        <?= formatRupiah($item['subtotal']) ?>
                                    </div>

                                    <form method="POST" action="<?= BASE_URL ?>/cart.php" style="display: inline-flex; align-items: center; gap: 8px;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="update_qty">
                                        <input type="hidden" name="cart_item_id" value="<?= e($item['cart_item_id']) ?>">
                                        <input type="number" name="quantity" value="<?= (int)$item['quantity'] ?>" min="1" max="50" onchange="this.form.submit()" class="form-control" style="width: 60px; padding: 4px 6px; text-align: center; font-size: 0.85rem;">
                                    </form>

                                    <form method="POST" action="<?= BASE_URL ?>/cart.php" style="margin-top: 10px;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="cart_item_id" value="<?= e($item['cart_item_id']) ?>">
                                        <button type="submit" onclick="return confirm('Hapus item ini dari keranjang?')" style="background: none; border: none; color: #EF4444; font-size: 0.8rem; cursor: pointer;">
                                            <i class="fa-solid fa-trash-can"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top: 20px;">
                        <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-light btn-sm">
                            <i class="fa-solid fa-arrow-left"></i> Tambah Bouquet Lainnya
                        </a>
                    </div>
                </div>

                <!-- Right: Summary & Checkout CTA -->
                <div>
                    <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
                        <h3 style="font-family: var(--font-heading); font-size: 1.3rem; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                            Ringkasan Belanja
                        </h3>

                        <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem;">
                            <span style="color: var(--text-muted);">Total Item (<?= count($cart) ?> item):</span>
                            <span style="font-weight: 600;"><?= formatRupiah($subtotal) ?></span>
                        </div>

                        <div style="display: flex; justify-content: space-between; margin-bottom: 16px; font-size: 0.95rem;">
                            <span style="color: var(--text-muted);">Ongkos Kirim:</span>
                            <span style="font-size: 0.85rem; color: var(--text-light);">(Dihitung saat checkout)</span>
                        </div>

                        <div style="border-top: 1.5px dashed var(--border-color); padding-top: 14px; margin-top: 14px; display: flex; justify-content: space-between; align-items: baseline;">
                            <span style="font-weight: 700; font-size: 1.1rem;">Estimasi Subtotal:</span>
                            <span style="font-size: 1.4rem; font-weight: 700; color: var(--primary-dark);"><?= formatRupiah($subtotal) ?></span>
                        </div>

                        <div style="margin-top: 24px;">
                            <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-primary btn-lg btn-block">
                                <i class="fa-solid fa-credit-card"></i> Lanjut ke Checkout
                            </a>
                        </div>

                        <div style="margin-top: 16px; font-size: 0.78rem; color: var(--text-muted); text-align: center; line-height: 1.4;">
                            <i class="fa-solid fa-shield-halved" style="color: #10B981;"></i> Tanpa perlu registrasi akun. Pemesanan instan &amp; aman.
                        </div>
                    </div>
                </div>

            </div>
        <?php else: ?>
            <!-- Empty Cart State -->
            <div style="text-align: center; padding: 70px 20px; background: #FFFFFF; border-radius: var(--radius-lg); border: 1px solid var(--border-color); max-width: 600px; margin: 0 auto;">
                <div style="font-size: 4rem; color: var(--secondary); margin-bottom: 16px;">
                    <i class="fa-solid fa-basket-shopping"></i>
                </div>
                <h2 style="font-family: var(--font-heading); font-size: 1.6rem; margin-bottom: 8px;">Keranjang Belanja Masih Kosong</h2>
                <p style="color: var(--text-muted); margin-bottom: 24px; font-size: 0.95rem;">
                    Anda belum memasukkan bouquet kawat bulu ke keranjang. Jelajahi 17 koleksi resmi kami sekarang.
                </p>
                <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-primary btn-lg">
                    <i class="fa-solid fa-magnifying-glass"></i> Jelajahi Katalog Resmi
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

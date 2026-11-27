<?php
/**
 * Leviosa Crafts - Product Customizer & Detail Page
 * Dynamic configuration strictly adhering to the 17 product rules
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$db = getDB();
$productId = (int)($_GET['id'] ?? 0);

// Fetch Product Details
$stmt = $db->prepare("
    SELECT p.*, c.name as category_name, c.slug as category_slug
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ? AND p.is_active = 1
");
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    setFlash('danger', 'Produk tidak ditemukan atau sedang tidak aktif.');
    header("Location: " . BASE_URL . "/catalog.php");
    exit;
}

$pageTitle = $product['name'];

// Fetch Sizes for this product
$stmt = $db->prepare("SELECT * FROM product_sizes WHERE product_id = ? AND is_active = 1 ORDER BY price ASC");
$stmt->execute([$productId]);
$sizes = $stmt->fetchAll();

// Fetch Colors
$colors = $db->query("SELECT * FROM product_colors WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch Dolls Catalog (if product allows doll choice)
$dolls = [];
if ($product['allow_doll_choice']) {
    $dolls = $db->query("SELECT * FROM dolls WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
}

// Fetch Wrapping Options (if product allows wrapping choice or is balloon)
$wrappings = [];
if ($product['allow_wrapping_choice'] || $product['wrapping_type'] === 'balloon') {
    $typeFilter = ($product['wrapping_type'] === 'balloon') ? "WHERE type = 'balloon' AND is_active = 1" : "WHERE is_active = 1";
    $wrappings = $db->query("SELECT * FROM wrapping_options $typeFilter ORDER BY id ASC")->fetchAll();
}

// Fetch Flower Types (for Single Stem Bouquet - #15)
$flowerTypes = [];
if ($product['has_flower_type_choice']) {
    $flowerTypes = $db->query("SELECT * FROM flower_types WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
}

// Fetch Allowed Add-ons (Accessories) for this product
$allowedAddons = [];
if ($product['allow_addons']) {
    $stmt = $db->prepare("
        SELECT a.*
        FROM accessories a
        JOIN product_accessories pa ON a.id = pa.accessory_id
        WHERE pa.product_id = ? AND pa.is_allowed = 1 AND a.is_active = 1
        ORDER BY a.category ASC, a.price ASC
    ");
    $stmt->execute([$productId]);
    $allowedAddons = $stmt->fetchAll();
}

// -----------------------------------------------------------------------------
// POST: Add to Cart & Buy Now Handling
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) || isset($_POST['submit_action']))) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token keamanan CSRF tidak valid. Silakan coba kembali.');
        header("Location: " . BASE_URL . "/product-detail.php?id=" . $productId);
        exit;
    }

    $submitAction = $_POST['submit_action'] ?? $_POST['action'] ?? 'add_to_cart';

    $sizeId = (int)($_POST['size_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $greetingCard = trim($_POST['greeting_card'] ?? '');
    $itemNotes = trim($_POST['item_notes'] ?? '');

    // Validate Size
    $selectedSize = null;
    foreach ($sizes as $s) {
        if ($s['id'] === $sizeId) {
            $selectedSize = $s;
            break;
        }
    }
    if (!$selectedSize && !empty($sizes)) {
        // Fallback to first size
        $selectedSize = $sizes[0];
    }

    $unitBasePrice = $selectedSize ? (float)$selectedSize['price'] : (float)$product['base_price'];

    // 1. Color validation
    $primaryColor = null;
    $secondaryColor = null;
    if ($product['allow_color_choice']) {
        $primaryColor = trim($_POST['primary_color'] ?? '');
        if ($product['color_mode'] === 'dual' || ($product['color_mode'] === 'single_or_dual' && !empty($_POST['secondary_color']))) {
            $secondaryColor = trim($_POST['secondary_color'] ?? '');
        }
    }

    // 2. Doll validation
    $dollName = null;
    if ($product['allow_doll_choice']) {
        $dollId = (int)($_POST['doll_id'] ?? 0);
        foreach ($dolls as $d) {
            if ($d['id'] === $dollId) {
                $dollName = $d['name'];
                break;
            }
        }
    }

    // 3. Wrapping validation
    $wrappingOption = null;
    if ($product['allow_wrapping_choice'] || $product['wrapping_type'] === 'balloon') {
        $wrappingId = (int)($_POST['wrapping_id'] ?? 0);
        foreach ($wrappings as $w) {
            if ($w['id'] === $wrappingId) {
                $wrappingOption = $w['name'];
                $unitBasePrice += (float)$w['price'];
                break;
            }
        }
    }

    // 4. Flower Type validation (for Single Stem #15)
    $flowerTypeName = null;
    if ($product['has_flower_type_choice']) {
        $flowerTypeId = (int)($_POST['flower_type_id'] ?? 0);
        foreach ($flowerTypes as $ft) {
            if ($ft['id'] === $flowerTypeId) {
                $flowerTypeName = $ft['name'];
                $unitBasePrice = (float)$ft['price']; // Overrides base price
                break;
            }
        }
    }

    // 5. Chocolate validation (for Chocolate Bouquet #10)
    $chocolateQty = (int)$product['default_chocolate_qty'];
    if ($product['allow_extra_chocolate']) {
        $extraChoc = max(0, (int)($_POST['extra_chocolate'] ?? 0));
        $chocolateQty += $extraChoc;
        $unitBasePrice += ($extraChoc * (float)$product['chocolate_price_per_item']);

        // Check if chocolate > threshold, force Large size!
        if ($chocolateQty > (int)$product['chocolate_threshold_large']) {
            foreach ($sizes as $s) {
                if ($s['name'] === 'Large') {
                    $selectedSize = $s;
                    // Recalculate base
                    $unitBasePrice = (float)$s['price'] + ($extraChoc * (float)$product['chocolate_price_per_item']);
                    break;
                }
            }
        }
    }

    // 6. Add-ons validation
    $selectedAddons = [];
    $addonsTotal = 0.0;
    if ($product['allow_addons'] && !empty($_POST['addons']) && is_array($_POST['addons'])) {
        $postedAddonIds = array_map('intval', $_POST['addons']);
        foreach ($allowedAddons as $addon) {
            if (in_array($addon['id'], $postedAddonIds)) {
                $selectedAddons[] = [
                    'id' => $addon['id'],
                    'name' => $addon['name'],
                    'price' => (float)$addon['price']
                ];
                $addonsTotal += (float)$addon['price'];
            }
        }
    }

    $finalUnitPrice = $unitBasePrice + $addonsTotal;
    $itemSubtotal = $finalUnitPrice * $quantity;

    // Cart Item Object
    $cartItem = [
        'cart_item_id' => bin2hex(random_bytes(8)),
        'product_id' => $product['id'],
        'product_name' => $product['name'],
        'product_code' => $product['code'],
        'main_image' => $product['main_image'],
        'size_id' => $selectedSize ? $selectedSize['id'] : null,
        'size_name' => $selectedSize ? $selectedSize['name'] : 'Standard',
        'unit_price' => $finalUnitPrice,
        'quantity' => $quantity,
        'subtotal' => $itemSubtotal,
        'greeting_card' => $greetingCard,
        'item_notes' => $itemNotes,
        'customization' => [
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'doll_name' => $dollName,
            'wrapping_option' => $wrappingOption,
            'flower_type' => $flowerTypeName,
            'chocolate_qty' => $chocolateQty,
            'addons' => $selectedAddons
        ]
    ];

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    $_SESSION['cart'][] = $cartItem;

    if ($submitAction === 'buy_now') {
        header("Location: " . BASE_URL . "/checkout.php");
        exit;
    } else {
        setFlash('success', "{$product['name']} berhasil ditambahkan ke keranjang belanja!");
        header("Location: " . BASE_URL . "/cart.php");
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="section-py">
    <div class="container">
        <!-- Minimalist Back Button -->
        <div>
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/catalog.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali ke Katalog</span>
            </a>
        </div>

        <!-- Breadcrumb -->
        <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px;">
            <a href="<?= BASE_URL ?>/index.php">Beranda</a> &rsaquo; 
            <a href="<?= BASE_URL ?>/catalog.php">Katalog</a> &rsaquo; 
            <a href="<?= BASE_URL ?>/catalog.php?kategori=<?= urlencode($product['category_slug']) ?>"><?= e($product['category_name']) ?></a> &rsaquo; 
            <span style="color: var(--primary-dark); font-weight: 600;"><?= e($product['name']) ?></span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1.25fr; gap: 40px; align-items: flex-start;">
            
            <!-- Left: Product Image & Badges -->
            <div>
                <div style="position: sticky; top: 100px;">
                    <div style="border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-soft); border: 1px solid var(--border-color); background: #FFF9F9;">
                        <img src="<?= BASE_URL . '/' . e($product['main_image']) ?>" alt="<?= e($product['name']) ?>" id="mainProductImg" style="width: 100%; aspect-ratio: 1/1; object-fit: cover;">
                    </div>
                    
                    <div style="margin-top: 16px; background: #FFFFFF; padding: 16px 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-around; text-align: center;">
                        <div>
                            <div style="color: var(--primary); font-size: 1.2rem;"><i class="fa-solid fa-hand-holding-heart"></i></div>
                            <div style="font-size: 0.75rem; font-weight: 600; margin-top: 4px;">100% Handmade</div>
                        </div>
                        <div style="border-right: 1px solid var(--border-color); height: 30px;"></div>
                        <div>
                            <div style="color: var(--primary); font-size: 1.2rem;"><i class="fa-solid fa-sparkles"></i></div>
                            <div style="font-size: 0.75rem; font-weight: 600; margin-top: 4px;">Awet Selamanya</div>
                        </div>
                        <div style="border-right: 1px solid var(--border-color); height: 30px;"></div>
                        <div>
                            <div style="color: var(--primary); font-size: 1.2rem;"><i class="fa-solid fa-truck-fast"></i></div>
                            <div style="font-size: 0.75rem; font-weight: 600; margin-top: 4px;">Palembang</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Interactive Product Customizer Form -->
            <div>
                <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span class="badge badge-primary">Produk #<?= e($product['sort_order']) ?> • <?= e($product['category_name']) ?></span>
                        <span style="font-size: 0.8rem; color: var(--text-light); font-weight: 500;">Kode: <?= e($product['code']) ?></span>
                    </div>

                    <h1 style="font-family: var(--font-heading); font-size: 2rem; color: var(--text-dark); margin-bottom: 12px; line-height: 1.25;">
                        <?= e($product['name']) ?>
                    </h1>

                    <div style="margin-bottom: 20px; display: flex; align-items: baseline; gap: 10px;">
                        <span style="font-size: 1.8rem; font-weight: 700; color: var(--primary-dark);" id="displayTotalPrice">
                            <?= formatRupiah($product['base_price']) ?>
                        </span>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">(Estimasi harga konfigurasi)</span>
                    </div>

                    <p style="font-size: 0.95rem; color: var(--text-muted); line-height: 1.6; margin-bottom: 24px; border-bottom: 1px solid var(--border-color); padding-bottom: 20px;">
                        <?= nl2br(e($product['description'])) ?>
                    </p>

                    <!-- CUSTOMIZER FORM -->
                    <form method="POST" id="customizerForm" action="<?= BASE_URL ?>/product-detail.php?id=<?= $product['id'] ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_to_cart">

                        <!-- 1. SIZE SELECTION -->
                        <?php if (!empty($sizes)): ?>
                            <div class="form-group">
                                <label class="form-label">
                                    Pilih Ukuran Bouquet <span class="required">*</span>
                                </label>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                                    <?php foreach ($sizes as $idx => $s): ?>
                                        <label class="size-pill-label" style="display: block; cursor: pointer;">
                                            <input type="radio" name="size_id" value="<?= $s['id'] ?>" data-price="<?= $s['price'] ?>" data-name="<?= $s['name'] ?>" <?= $idx === 0 ? 'checked' : '' ?> style="display: none;" class="size-radio">
                                            <div class="size-box" style="border: 2px solid var(--border-color); border-radius: var(--radius-sm); padding: 10px; text-align: center; transition: var(--transition);">
                                                <div style="font-weight: 700; font-size: 0.95rem;"><?= e($s['name']) ?></div>
                                                <div style="font-size: 0.82rem; color: var(--primary-dark); font-weight: 600;"><?= formatRupiah($s['price']) ?></div>
                                                <?php if (!empty($s['stem_count_note'])): ?>
                                                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px;"><?= e($s['stem_count_note']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 2. FLOWER TYPE SELECTION (Single Stem #15) -->
                        <?php if ($product['has_flower_type_choice'] && !empty($flowerTypes)): ?>
                            <div class="form-group">
                                <label class="form-label">
                                    Pilih Jenis Bunga <span class="required">*</span>
                                </label>
                                <select name="flower_type_id" id="flowerTypeSelect" class="form-control form-select" required>
                                    <?php foreach ($flowerTypes as $ft): ?>
                                        <option value="<?= $ft['id'] ?>" data-price="<?= $ft['price'] ?>">
                                            <?= e($ft['name']) ?> — <?= formatRupiah($ft['price']) ?> (Warna: <?= e($ft['available_colors']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- 3. COLOR SELECTION -->
                        <?php if ($product['allow_color_choice']): ?>
                            <div class="form-group">
                                <label class="form-label">
                                    Pilihan Warna Bunga Utama <span class="required">*</span>
                                </label>
                                <select name="primary_color" class="form-control form-select" required>
                                    <option value="">-- Pilih Warna Utama --</option>
                                    <?php foreach ($colors as $col): ?>
                                        <option value="<?= e($col['name']) ?>"><?= e($col['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($product['color_mode'] === 'dual' || $product['color_mode'] === 'single_or_dual'): ?>
                                <div class="form-group">
                                    <label class="form-label">
                                        Kombinasi Warna Kedua <?= $product['color_mode'] === 'single_or_dual' ? '<span style="font-size:0.8rem; font-weight:normal; color:var(--text-muted);">(Opsional: kosongkan jika 1 warna)</span>' : '<span class="required">*</span>' ?>
                                    </label>
                                    <select name="secondary_color" class="form-control form-select" <?= $product['color_mode'] === 'dual' ? 'required' : '' ?>>
                                        <option value="">-- Pilih Warna Kedua --</option>
                                        <?php foreach ($colors as $col): ?>
                                            <option value="<?= e($col['name']) ?>"><?= e($col['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($productId === 1): ?>
                            <!-- Sunflower fixed design note -->
                            <div style="background: #FFFBEB; border: 1px solid #FDE68A; padding: 10px 14px; border-radius: var(--radius-sm); font-size: 0.85rem; color: #92400E; margin-bottom: 20px;">
                                <i class="fa-solid fa-circle-info"></i> Desain warna Sunflower Bouquet adalah paten orisinal khas bunga matahari cerah Leviosa.
                            </div>
                        <?php endif; ?>

                        <!-- 4. DOLL SELECTION (Doll Bouquet #5, #6, #7, #12) -->
                        <?php if ($product['allow_doll_choice'] && !empty($dolls)): ?>
                            <div class="form-group">
                                <label class="form-label">
                                    Pilih Karakter Boneka <span class="required">*</span>
                                    <?php if ($product['flower_color_adapts_doll']): ?>
                                        <span style="font-size: 0.8rem; color: var(--primary); font-weight: normal;">(Warna bunga akan diselaraskan otomatis)</span>
                                    <?php endif; ?>
                                </label>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px;">
                                    <?php foreach ($dolls as $idx => $doll): ?>
                                        <label style="cursor: pointer; display: block;">
                                            <input type="radio" name="doll_id" value="<?= $doll['id'] ?>" <?= $idx === 0 ? 'checked' : '' ?> style="display: none;" class="doll-radio">
                                            <div class="doll-box" style="border: 2px solid var(--border-color); border-radius: var(--radius-sm); padding: 8px; text-align: center; transition: var(--transition);">
                                                <img src="<?= BASE_URL . '/' . e($doll['image_path']) ?>" alt="<?= e($doll['name']) ?>" style="width: 50px; height: 50px; margin: 0 auto 6px; object-fit: contain;">
                                                <div style="font-size: 0.78rem; font-weight: 600; line-height: 1.2;"><?= e($doll['name']) ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 5. WRAPPING PAPER SELECTION -->
                        <?php if (($product['allow_wrapping_choice'] || $product['wrapping_type'] === 'balloon') && !empty($wrappings)): ?>
                            <div class="form-group">
                                <label class="form-label">
                                    Pilih Model / Warna Kertas Wrapping <span class="required">*</span>
                                </label>
                                <select name="wrapping_id" class="form-control form-select" required>
                                    <?php foreach ($wrappings as $w): ?>
                                        <option value="<?= $w['id'] ?>" data-price="<?= $w['price'] ?>">
                                            <?= e($w['name']) ?> <?= $w['price'] > 0 ? '(+' . formatRupiah($w['price']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- 6. CHOCOLATE QUANTITY (Chocolate Bouquet #10) -->
                        <?php if ($product['allow_extra_chocolate']): ?>
                            <div class="form-group" style="background: #FDF4E3; padding: 14px 18px; border-radius: var(--radius-sm); border: 1px solid #F6D799;">
                                <label class="form-label" style="color: #78350F;">
                                    <i class="fa-solid fa-cookie"></i> Tambahan Cokelat (+<?= formatRupiah($product['chocolate_price_per_item']) ?> / batang)
                                </label>
                                <div style="display: flex; align-items: center; gap: 14px;">
                                    <span style="font-size: 0.88rem; color: #92400E;">Cokelat bawaan: <strong><?= e($product['default_chocolate_qty']) ?> batang</strong></span>
                                    <span style="font-size: 0.88rem;">+ Tambah:</span>
                                    <input type="number" name="extra_chocolate" id="extraChocolateInput" value="0" min="0" max="7" class="form-control" style="width: 80px;" data-unitprice="<?= $product['chocolate_price_per_item'] ?>" data-threshold="<?= $product['chocolate_threshold_large'] ?>">
                                    <span style="font-size: 0.88rem;">batang</span>
                                </div>
                                <div id="chocolateNotice" style="font-size: 0.78rem; color: #B45309; margin-top: 6px;">
                                    *Total cokelat lebih dari 3 batang akan otomatis beralih ke ukuran <strong>Large</strong>.
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 7. ADD-ONS / ACCESSORIES (Strictly hidden if product has no addons) -->
                        <?php if ($product['allow_addons'] && !empty($allowedAddons)): ?>
                            <div class="form-group" style="margin-top: 24px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
                                <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-gift" style="color: var(--primary);"></i> Tambahan Produk &amp; Aksesoris (Opsional)
                                </label>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                    <?php foreach ($allowedAddons as $addon): ?>
                                        <label class="form-check" style="background: #FFFDF9; padding: 12px 14px; border-radius: 12px; border: 1.5px solid #F3E5E7; cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 12px;">
                                            <input type="checkbox" name="addons[]" value="<?= $addon['id'] ?>" data-price="<?= $addon['price'] ?>" class="addon-checkbox" style="accent-color: var(--primary); width: 18px; height: 18px; cursor: pointer;">
                                            <div style="flex-grow: 1;">
                                                <div style="font-size: 0.88rem; font-weight: 600; color: var(--text-dark);"><?= e($addon['name']) ?></div>
                                                <div style="font-size: 0.8rem; color: var(--primary-dark); font-weight: 600;">+<?= formatRupiah($addon['price']) ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 8. GREETING CARD & NOTES -->
                        <div class="form-group" style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                <label class="form-label" style="margin-bottom: 0;">
                                    <i class="fa-solid fa-envelope-open-text" style="color: var(--primary);"></i> Pesan Greeting Card (Kartu Ucapan)
                                </label>
                                <button type="button" class="btn-ai-card-trigger btn-open-ai-card" data-target="greetingCardText" title="Buat ucapan otomatis dengan AI Assistant">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> AI Card Assistant
                                </button>
                            </div>
                            <textarea name="greeting_card" id="greetingCardText" rows="3" class="form-control" placeholder="Tuliskan ucapan selamat / pesan spesial untuk penerima, atau klik 'AI Card Assistant' untuk rekomendasi otomatis..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Catatan Khusus untuk Crafter
                            </label>
                            <input type="text" name="item_notes" class="form-control" placeholder="Contoh: nuansa warna khusus, permintaan tanggal kirim, dll (opsional)...">
                        </div>

                        <!-- 9. QUANTITY & DUAL ORDER ACTION (MASUKKAN KERANJANG ATAU PESAN SEKARANG) -->
                        <div style="margin-top: 28px; padding-top: 22px; border-top: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label style="font-size: 0.9rem; font-weight: 600; color: var(--text-dark); margin: 0;">Jumlah:</label>
                                    <div style="display: inline-flex; align-items: center; border: 1.5px solid #F3C9D0; border-radius: var(--radius-full); padding: 3px 10px; background: #FFFDF9;">
                                        <button type="button" id="qtyMinus" style="background: none; border: none; font-size: 1.1rem; color: var(--text-dark); cursor: pointer; padding: 2px 8px; line-height: 1;" aria-label="Kurangi jumlah">-</button>
                                        <input type="number" name="quantity" id="quantityInput" value="1" min="1" max="50" style="width: 44px; text-align: center; border: none; font-weight: 700; font-size: 1rem; outline: none; background: transparent;">
                                        <button type="button" id="qtyPlus" style="background: none; border: none; font-size: 1.1rem; color: var(--text-dark); cursor: pointer; padding: 2px 8px; line-height: 1;" aria-label="Tambah jumlah">+</button>
                                    </div>
                                </div>
                                <div style="font-size: 0.88rem; color: var(--text-muted);">
                                    Estimasi Subtotal: <strong style="color: var(--primary-dark); font-size: 1.05rem;" id="displayActionSubtotal"><?= formatRupiah($product['base_price']) ?></strong>
                                </div>
                            </div>

                            <!-- 2 Pilihan Utama Pemesanan -->
                            <div class="product-action-grid">
                                <button type="submit" name="submit_action" value="add_to_cart" class="btn btn-outline-primary btn-lg" style="font-size: 0.98rem; font-weight: 600;">
                                    <i class="fa-solid fa-cart-plus"></i> Masukkan Keranjang
                                </button>
                                <button type="submit" name="submit_action" value="buy_now" class="btn btn-primary btn-lg" style="font-size: 0.98rem; font-weight: 700;">
                                    <i class="fa-solid fa-bag-shopping"></i> Pesan Sekarang
                                </button>
                            </div>

                            <div style="text-align: center; margin-top: 14px;">
                                <button type="button" id="btnOrderWhatsapp" style="background: none; border: none; font-size: 0.82rem; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 4px; transition: color 0.2s;">
                                    <i class="fa-brands fa-whatsapp" style="color: #25D366; font-size: 0.95rem;"></i> Ingin konsultasi atau pesan custom via WhatsApp? Klik di sini
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.STORE_WHATSAPP = '<?= getStoreWhatsapp() ?>';
</script>
<script src="<?= BASE_URL ?>/assets/js/product-detail.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>

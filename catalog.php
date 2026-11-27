<?php
/**
 * Laviosa Crafts - Official Product Catalog (17 Products) & Custom Order Hub
 * Revised: Added "Custom Bouquet & Custom Item" Category with WhatsApp Buttons,
 * Updated Pricing, Back Button, and Quick WhatsApp Order Integration.
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "Katalog Produk";
$db = getDB();

// Filters
$categorySlug = $_GET['kategori'] ?? '';
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'official'; // 'official', 'price_asc', 'price_desc'

// Fetch Categories for Filter Tabs
$categories = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll();

$isCustomCategory = ($categorySlug === 'custom-order');

// Build Query for Catalog Products
$products = [];
if (!$isCustomCategory) {
    $sql = "
        SELECT p.*, c.name as category_name, c.slug as category_slug,
               (SELECT price FROM product_sizes WHERE product_id = p.id AND is_active = 1 ORDER BY price ASC LIMIT 1) as min_price,
               (SELECT price FROM product_sizes WHERE product_id = p.id AND is_active = 1 ORDER BY price DESC LIMIT 1) as max_price
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = 1
    ";
    $params = [];

    if (!empty($categorySlug)) {
        $sql .= " AND c.slug = ?";
        $params[] = $categorySlug;
    }

    if (!empty($search)) {
        $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Sorting
    switch ($sort) {
        case 'price_asc':
            $sql .= " ORDER BY min_price ASC, p.sort_order ASC";
            break;
        case 'price_desc':
            $sql .= " ORDER BY min_price DESC, p.sort_order ASC";
            break;
        case 'official':
        default:
            $sql .= " ORDER BY p.sort_order ASC"; // Keeps exact official 1..17 order
            break;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container">
        <!-- Minimalist Back Button -->
        <div>
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/index.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>

        <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
            <span class="section-subtitle">Catalog &amp; Custom Hub</span>
            <h1 class="section-title">Katalog Koleksi Laviosa</h1>
            <p class="section-desc">
                17 kreasi buket chenille stem berkualitas tinggi serta layanan Custom Bouquet &amp; Custom Item. Seluruh warna dapat disesuaikan sesuai keinginan dan ketersediaan.
            </p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container">
        <!-- Filter and Search Bar -->
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 30px;">
            <!-- Category Pills -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-sm <?= empty($categorySlug) ? 'btn-primary' : 'btn-light' ?>">
                    Semua (17 Produk)
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="<?= BASE_URL ?>/catalog.php?kategori=<?= urlencode($cat['slug']) ?>" class="btn btn-sm <?= $categorySlug === $cat['slug'] ? 'btn-primary' : 'btn-light' ?>">
                        <?php if ($cat['slug'] === 'custom-order'): ?>
                            <i class="fa-solid fa-wand-magic-sparkles" style="margin-right: 4px;"></i>
                        <?php endif; ?>
                        <?= e($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Search & Sort Form (Only for standard products view) -->
            <?php if (!$isCustomCategory): ?>
                <form method="GET" action="<?= BASE_URL ?>/catalog.php" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if (!empty($categorySlug)): ?>
                        <input type="hidden" name="kategori" value="<?= e($categorySlug) ?>">
                    <?php endif; ?>
                    <div style="position: relative;">
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nama buket..." class="form-control" style="padding: 8px 14px 8px 36px; border-radius: var(--radius-full); font-size: 0.88rem; width: 200px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 12px; color: var(--text-light); font-size: 0.85rem;"></i>
                    </div>
                    <select name="sort" onchange="this.form.submit()" class="form-control form-select" style="padding: 8px 34px 8px 14px; border-radius: var(--radius-full); font-size: 0.88rem; width: auto;">
                        <option value="official" <?= $sort === 'official' ? 'selected' : '' ?>>Urutan Resmi (1-17)</option>
                        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Harga: Rendah ke Tinggi</option>
                        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Harga: Tinggi ke Rendah</option>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <!-- SPECIAL VIEW: CUSTOM BOUQUET & CUSTOM ITEM CATEGORY -->
        <?php if ($isCustomCategory): ?>
            <div style="margin-bottom: 40px;">
                <div class="custom-cta-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 28px;">
                    <!-- 1. Custom Item Card -->
                    <div class="custom-box" style="background: #FFFFFF; border: 1.5px solid var(--border-color); border-radius: var(--radius-lg); padding: 36px 30px; box-shadow: var(--shadow-soft); display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; background: #FDE8E9; color: var(--primary); font-size: 1.8rem; margin-bottom: 20px;">
                                <i class="fa-solid fa-gift"></i>
                            </div>
                            <span class="badge badge-primary" style="margin-bottom: 10px; display: inline-block;">Pilihan 1</span>
                            <h2 style="font-family: var(--font-heading); font-size: 1.6rem; color: var(--text-dark); margin-bottom: 12px;">Custom Item</h2>
                            <p style="color: var(--text-muted); font-size: 0.95rem; line-height: 1.7; margin-bottom: 20px;">
                                Untuk pelanggan yang ingin memesan barang atau item khusus sesuai keinginan. Anda dapat menggabungkan barang fisik milik Anda (seperti tumbler, parfum, perhiasan, kosmetik, atau boneka pribadi) ke dalam rangkaian buket estetik kawat bulu kami.
                            </p>
                            <ul style="margin-bottom: 24px; padding-left: 20px; color: var(--text-dark); font-size: 0.88rem; line-height: 1.8;">
                                <li>Bawa barang sendiri (diantar ke toko maks. 12 jam).</li>
                                <li>Pilihan konsep wrapping, warna, dan tema bunga bebas.</li>
                                <li>Foto preview hasil pengerjaan sebelum pesanan dikirim.</li>
                            </ul>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="<?= BASE_URL ?>/custom-item.php" class="btn btn-primary" style="font-size: 0.95rem; padding: 12px;">
                                <i class="fa-solid fa-gift"></i> Mulai Pesan Custom Item
                            </a>
                            <a href="<?= buildWhatsAppCustomOrderUrl('Custom Item', 'Membawa barang fisik sendiri / request item khusus') ?>" target="_blank" rel="noopener" style="font-size: 0.82rem; color: var(--text-muted); text-align: center; text-decoration: none; padding: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                <i class="fa-brands fa-whatsapp" style="color: #25D366;"></i> Butuh bantuan? Konsultasi via WhatsApp
                            </a>
                        </div>
                    </div>

                    <!-- 2. Custom Bouquet Card -->
                    <div class="custom-box" style="background: #FFFFFF; border: 1.5px solid var(--border-color); border-radius: var(--radius-lg); padding: 36px 30px; box-shadow: var(--shadow-soft); display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; background: #FAF0F8; color: #9C27B0; font-size: 1.8rem; margin-bottom: 20px;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                            </div>
                            <span class="badge badge-accent" style="margin-bottom: 10px; display: inline-block;">Pilihan 2</span>
                            <h2 style="font-family: var(--font-heading); font-size: 1.6rem; color: var(--text-dark); margin-bottom: 12px;">Custom Bouquet</h2>
                            <p style="color: var(--text-muted); font-size: 0.95rem; line-height: 1.7; margin-bottom: 20px;">
                                Untuk pelanggan yang ingin membuat buket dengan desain, jenis bunga, warna, dan konsep sesuai permintaan. Ceritakan ide atau kirimkan foto referensi buket impian Anda, dan tim crafter Laviosa akan mewujudkannya.
                            </p>
                            <ul style="margin-bottom: 24px; padding-left: 20px; color: var(--text-dark); font-size: 0.88rem; line-height: 1.8;">
                                <li>Bebas tentukan kombinasi jenis dan jumlah tangkai bunga.</li>
                                <li>Konsultasi warna pastel, wrapping, pita, &amp; aksesoris.</li>
                                <li>Estimasi harga transparan disepakati sebelum pengerjaan.</li>
                            </ul>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="<?= BASE_URL ?>/custom-bouquet.php" class="btn btn-primary" style="font-size: 0.95rem; padding: 12px;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Mulai Pesan Custom Bouquet
                            </a>
                            <a href="<?= buildWhatsAppCustomOrderUrl('Custom Bouquet', 'Foto referensi desain sendiri') ?>" target="_blank" rel="noopener" style="font-size: 0.82rem; color: var(--text-muted); text-align: center; text-decoration: none; padding: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                <i class="fa-brands fa-whatsapp" style="color: #25D366;"></i> Butuh bantuan? Konsultasi via WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Products Grid -->
            <?php if (!empty($products)): ?>
                <div class="products-grid">
                    <?php foreach ($products as $p): ?>
                        <?php
                        $waProdUrl = buildWhatsAppProductOrderUrl(
                            $p['name'],
                            'Standard / Sesuai Pilihan',
                            1,
                            'Sesuai Foto / Custom Pastel',
                            '-',
                            '-'
                        );
                        ?>
                        <div class="product-card">
                            <div class="product-img-wrap">
                                <span class="product-order-number">Produk #<?= e($p['sort_order']) ?></span>
                                <img src="<?= BASE_URL . '/' . e($p['main_image']) ?>" alt="<?= e($p['name']) ?>" class="product-img" loading="lazy">
                            </div>
                            <div class="product-body">
                                <span class="product-category"><?= e($p['category_name']) ?></span>
                                <h2 class="product-name" style="font-size: 1.2rem;"><?= e($p['name']) ?></h2>
                                <p class="product-desc"><?= e($p['description']) ?></p>
                                
                                <div class="product-price-block">
                                    <span class="product-price-label">Mulai dari</span>
                                    <span class="product-price"><?= formatRupiah($p['min_price'] ?: $p['base_price']) ?></span>
                                </div>

                                <div class="product-card-actions">
                                    <a href="<?= BASE_URL ?>/product-detail.php?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm btn-block">
                                        <i class="fa-solid fa-sliders"></i> Kustomisasi &amp; Pesan
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <div style="font-size: 3rem; color: var(--text-light); margin-bottom: 16px;"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <h3>Buket Tidak Ditemukan</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px;">Tidak ada produk yang cocok dengan pencarian "<strong><?= e($search) ?></strong>".</p>
                    <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-primary">Lihat Semua Katalog</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Laviosa Crafts - Homepage
 * Revised: 5 Representative Featured Products, Dedicated CTA to Full Catalog,
 * WhatsApp Order Integration, and AI Card Assistant Integration.
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "Beranda";
$db = getDB();

// 1. Fetch exactly 5 representative featured products:
// 1: Sunflower, 2: Tulip, 8: Butterfly, 10: Chocolate, 13: Buket Balon
$stmt = $db->query("
    SELECT p.*, c.name as category_name,
           (SELECT price FROM product_sizes WHERE product_id = p.id AND is_active = 1 ORDER BY price ASC LIMIT 1) as min_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id IN (1, 2, 8, 10, 13) AND p.is_active = 1
    ORDER BY FIELD(p.id, 1, 2, 8, 10, 13)
");
$featuredProducts = $stmt->fetchAll();

// 2. Fetch Featured Customer Reviews
$stmt = $db->query("
    SELECT r.*, o.order_code
    FROM reviews r
    JOIN orders o ON r.order_id = o.id
    WHERE r.status = 'approved' AND r.is_featured = 1
    ORDER BY r.id DESC
    LIMIT 3
");
$featuredReviews = $stmt->fetchAll();
if (empty($featuredReviews)) {
    $stmt = $db->query("
        SELECT r.*, o.order_code
        FROM reviews r
        JOIN orders o ON r.order_id = o.id
        WHERE r.status = 'approved'
        ORDER BY r.id DESC
        LIMIT 3
    ");
    $featuredReviews = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<!-- 1. HERO SECTION -->
<section class="hero-section">
    <div class="container">
        <div class="hero-grid">
            <div class="hero-content">
                <div class="hero-tag">
                    <i class="fa-solid fa-sparkles"></i> 100% Handmade Chenille Stem Florist
                </div>
                <h1 class="hero-title">
                    Buket Kawat Bulu yang <span>Mekar Abadi</span> &amp; Penuh Makna
                </h1>
                <p class="hero-desc">
                    Sentuhan mewah boutique florist buket kawat bulu (pipe cleaner) di Palembang. Kreasi lembut, estetik, dan awet selamanya untuk merayakan wisuda, ulang tahun, anniversary, dan momen paling berharga.
                </p>
                <div class="hero-cta-group">
                    <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-primary btn-lg">
                        <i class="fa-solid fa-basket-shopping"></i> Jelajahi Katalog
                    </a>
                    <a href="<?= BASE_URL ?>/catalog.php?kategori=custom-order" class="btn btn-outline btn-lg">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Custom Bouquet &amp; Item
                    </a>
                </div>
            </div>

            <div class="hero-image-wrap">
                <img src="<?= BASE_URL ?>/assets/uploads/products/tulip-bouquet.jpg" alt="Tulip Bouquet Laviosa" class="hero-main-img">
                <div class="hero-badge-float">
                    <div class="badge-icon">🌷</div>
                    <div>
                        <div class="badge-text-title">Tulip Bouquet</div>
                        <div class="badge-text-sub">Bestseller Pilihan Pelanggan</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 2. VALUE PROPOSITION -->
<section class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; text-align: center;">
            <div style="padding: 16px;">
                <div style="font-size: 2.2rem; color: var(--primary); margin-bottom: 12px;"><i class="fa-solid fa-heart"></i></div>
                <h3 style="font-size: 1.1rem; margin-bottom: 6px; font-weight: 600;">100% Buatan Tangan</h3>
                <p style="font-size: 0.88rem; color: var(--text-muted);">Setiap helai kelopak chenille stem dirangkai rapi dengan sentuhan cinta dan ketelitian tinggi.</p>
            </div>
            <div style="padding: 16px;">
                <div style="font-size: 2.2rem; color: var(--primary); margin-bottom: 12px;"><i class="fa-solid fa-infinity"></i></div>
                <h3 style="font-size: 1.1rem; margin-bottom: 6px; font-weight: 600;">Awet Selamanya</h3>
                <p style="font-size: 0.88rem; color: var(--text-muted);">Tidak akan pernah layu dan tidak butuh disiram. Kenangan indah tersimpan abadi sebagai pajangan estetik.</p>
            </div>
            <div style="padding: 16px;">
                <div style="font-size: 2.2rem; color: var(--primary); margin-bottom: 12px;"><i class="fa-solid fa-palette"></i></div>
                <h3 style="font-size: 1.1rem; margin-bottom: 6px; font-weight: 600;">Warna &amp; Karakter Custom</h3>
                <p style="font-size: 0.88rem; color: var(--text-muted);">Bebas sesuaikan warna buket favorit, karakter boneka, cokelat, aksesoris, hingga kartu ucapan ber-AI.</p>
            </div>
            <div style="padding: 16px;">
                <div style="font-size: 2.2rem; color: var(--primary); margin-bottom: 12px;"><i class="fa-solid fa-map-location-dot"></i></div>
                <h3 style="font-size: 1.1rem; margin-bottom: 6px; font-weight: 600;">Lokasi Strategis Palembang</h3>
                <p style="font-size: 0.88rem; color: var(--text-muted);">Pickup gratis di Ujuk Kost Lorong Siguntang Palembang, atau delivery aman ke seluruh Palembang.</p>
            </div>
        </div>
    </div>
</section>

<!-- 3. PRODUK UNGGULAN (HANYA 5 PRODUK PILIHAN) -->
<section class="section-py">
    <div class="container">
        <div class="section-title-wrap">
            <span class="section-subtitle">Koleksi Pilihan</span>
            <h2 class="section-title">Produk Unggulan Laviosa</h2>
            <p class="section-desc">5 rangkaian bouquet favorit yang paling banyak dipesan untuk merayakan kelulusan, ulang tahun, dan momen berharga Anda.</p>
        </div>

        <div class="products-grid products-grid-5">
            <?php foreach ($featuredProducts as $product): ?>
                <?php
                $waMsg = "Halo Laviosa! Saya ingin memesan produk berikut:\n\n"
                       . "Nama Produk: " . $product['name'] . "\n"
                       . "Ukuran: Standard\n"
                       . "Jumlah: 1\n"
                       . "Warna yang diinginkan: Sesuai Foto / Custom\n"
                       . "Kartu Ucapan: -\n"
                       . "Catatan Tambahan: -\n\n"
                       . "Mohon informasi lebih lanjut mengenai pemesanan ini. Terima kasih!";
                $waUrl = "https://wa.me/" . getStoreWhatsapp() . "?text=" . rawurlencode($waMsg);
                ?>
                <div class="product-card">
                    <div class="product-img-wrap">
                        <span class="product-order-number">Pilihan #<?= e($product['sort_order']) ?></span>
                        <img src="<?= BASE_URL . '/' . e($product['main_image']) ?>" alt="<?= e($product['name']) ?>" class="product-img" loading="lazy">
                    </div>
                    <div class="product-body">
                        <span class="product-category"><?= e($product['category_name']) ?></span>
                        <h3 class="product-name"><?= e($product['name']) ?></h3>
                        <p class="product-desc"><?= e($product['description']) ?></p>
                        
                        <div class="product-price-block">
                            <span class="product-price-label">Mulai dari</span>
                            <span class="product-price"><?= formatRupiah($product['min_price'] ?: $product['base_price']) ?></span>
                        </div>

                        <div class="product-card-actions">
                            <a href="<?= BASE_URL ?>/product-detail.php?id=<?= $product['id'] ?>" class="btn btn-primary btn-sm btn-block">
                                <i class="fa-solid fa-basket-shopping"></i> Lihat Pilihan &amp; Pesan
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- 4. CTA BANNER: LIHAT SEMUA KATALOG (PENGGANTI GRID 17 PRODUK) -->
<section class="section-py" style="background: linear-gradient(135deg, #FFF6F7 0%, #FDF0F3 50%, #FAF2FA 100%); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
    <div class="container" style="text-align: center; max-width: 820px;">
        <span class="section-subtitle">Katalog Lengkap Laviosa</span>
        <h2 class="section-title" style="margin-bottom: 16px;">
            Temukan 17+ Koleksi Buket Bunga Lengkap
        </h2>
        <p class="section-desc" style="font-size: 1.05rem; margin-bottom: 32px;">
            Mulai dari buket bunga klasik (Sunflower &amp; Tulip), boneka karakter kartun, buket cokelat manis, konsep balon kubah transparan (Buket Balon), hingga tangkai satuan Single Stem.
        </p>
        
        <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-primary btn-lg" style="padding: 14px 34px; font-size: 1.05rem; box-shadow: var(--shadow-hover);">
                <i class="fa-solid fa-list-check"></i> Lihat Semua Katalog
            </a>
            <a href="<?= BASE_URL ?>/catalog.php?kategori=custom-order" class="btn btn-outline btn-lg" style="padding: 14px 28px;">
                <i class="fa-solid fa-palette"></i> Custom Bouquet &amp; Item
            </a>
        </div>
    </div>
</section>

<!-- 5. CUSTOM BOUQUET & CUSTOM ITEM PROMO -->
<section class="section-py">
    <div class="container">
        <div class="custom-cta-section">
            <div class="custom-cta-grid">
                <!-- Custom Bouquet Box -->
                <div class="custom-box">
                    <div class="custom-box-icon">🎨</div>
                    <h3 class="custom-box-title">Custom Bouquet Sesuai Impian</h3>
                    <p class="custom-box-desc">
                        Ingin desain buket dengan bunga, warna pastel impian, atau susunan khusus? Kirimkan foto referensi Anda atau konsultasikan langsung dengan tim crafter kami.
                    </p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px;">
                        <a href="<?= BASE_URL ?>/custom-bouquet.php" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Form Custom Web
                        </a>
                        <a href="<?= buildWhatsAppCustomOrderUrl('Custom Bouquet', 'Foto referensi desain sendiri') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-wa">
                            <i class="fa-brands fa-whatsapp"></i> Chat WA Custom
                        </a>
                    </div>
                </div>

                <!-- Custom Item Box -->
                <div class="custom-box">
                    <div class="custom-box-icon">🎁</div>
                    <h3 class="custom-box-title">Custom Item (Bawa Barang Sendiri)</h3>
                    <p class="custom-box-desc">
                        Ingin menyertakan barang Anda sendiri (seperti boneka pribadi, tumbler, kosmetik, atau perhiasan) ke dalam buket? Antarkan barang ke toko kami dan kami rangkai secara estetik.
                    </p>
                    <div style="margin-top: 20px;">
                        <a href="<?= BASE_URL ?>/custom-item.php" class="btn btn-outline btn-sm btn-block">
                            <i class="fa-solid fa-gift"></i> Alur Pemesanan Custom Item
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 6. AI GREETING CARD ASSISTANT TEASER -->
<section class="section-py" style="background: #FFFFFF; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow" style="text-align: center;">
        <span class="section-subtitle">Fitur Eksklusif</span>
        <h2 class="section-title">AI Greeting Card Assistant</h2>
        <p class="section-desc">
            Bingung merangkai kata-kata kartu ucapan? Asisten AI pintar Laviosa siap membuatkan 3 ucapan menyentuh, romantis, manis, atau puitis dalam hitungan detik.
        </p>
        <div style="margin-top: 24px;">
            <button type="button" class="btn btn-primary btn-open-ai-card" data-target="greetingCardText" style="padding: 12px 28px;">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Coba AI Greeting Card Sekarang
            </button>
        </div>
    </div>
</section>

<!-- 7. CUSTOMER REVIEWS SECTION -->
<section class="section-py">
    <div class="container">
        <div class="section-title-wrap">
            <span class="section-subtitle">Testimoni Pelanggan</span>
            <h2 class="section-title">Cerita Bahagia Bersama Laviosa</h2>
            <p class="section-desc">Ratusan penerima tersenyum manis dengan buket kawat bulu yang awet dan rapi dari Laviosa.</p>
        </div>

        <?php if (!empty($featuredReviews)): ?>
            <div class="reviews-grid">
                <?php foreach ($featuredReviews as $rev): ?>
                    <div class="review-card">
                        <div class="review-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fa-solid fa-star <?= $i <= $rev['rating'] ? '' : 'star-muted' ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="review-comment">"<?= e($rev['comment']) ?>"</p>
                        <div class="review-author">
                            <strong><?= e($rev['customer_name']) ?></strong>
                            <span class="badge badge-success" style="font-size: 0.7rem; padding: 2px 8px;">Terverifikasi</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-muted);">Belum ada ulasan yang ditampilkan.</p>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 32px;">
            <a href="<?= BASE_URL ?>/reviews.php" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-comments"></i> Lihat Semua Ulasan Pelanggan
            </a>
        </div>
    </div>
</section>

<!-- 8. FAQ ACCORDION -->
<section class="section-py" style="background: #FFFFFF; border-top: 1px solid var(--border-color);">
    <div class="container container-narrow">
        <div class="section-title-wrap">
            <span class="section-subtitle">Pusat Informasi</span>
            <h2 class="section-title">Pertanyaan yang Sering Diajukan</h2>
            <p class="section-desc">Jawaban ringkas seputar material chenille stem, estimasi pengerjaan buket, dan pengambilan.</p>
        </div>

        <div class="faq-accordion">
            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Apakah bunganya asli atau berbahan kawat bulu?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Semua buket bunga Laviosa dibuat 100% menggunakan <em>chenille stem</em> (kawat bulu / pipe cleaner) berkualitas premium yang lembut dan padat, dipadu kertas wrapping impor dan pita satin. Bunga ini mekar abadi, tidak membutuhkan air, bebas debu berlebih, dan awet disimpan bertahun-tahun.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Berapa lama waktu pembuatan (lead time) buket?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Untuk buket Ready Design dari katalog resmi, minimal pemesanan H-1 (1 hari kerja). Untuk pesanan Custom Bouquet dan Custom Item (bawa barang sendiri), lead time adalah 2–3 hari kerja demi memastikan hasil pengerjaan rapi dan detail.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Apakah saya bisa memilih atau mengubah kombinasi warna bunga?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Tentu bisa! Hampir semua produk buket mendukung pilihan satu warna utama atau kombinasi dua warna pastel, serta pilihan warna kertas wrapping. Seluruh warna dapat disesuaikan sesuai keinginan dan ketersediaan stok bahan.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Di mana lokasi titik toko untuk Self-Pickup?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Anda dapat mengambil pesanan secara mandiri (Gratis / Rp0) di titik toko Laviosa: <strong>Ujuk Kost, Lorong Siguntang, Bukit Lama, Palembang</strong> pada tanggal dan slot waktu yang telah ditentukan saat checkout.</p>
                    </div>
                </div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 32px;">
            <a href="<?= BASE_URL ?>/faq.php" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-circle-question"></i> Lihat Seluruh Tanya Jawab (FAQ Lengkap)
            </a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

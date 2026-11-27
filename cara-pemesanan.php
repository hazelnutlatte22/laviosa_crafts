<?php
/**
 * Leviosa Crafts - Cara Pemesanan Page
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "Cara Pemesanan";
$db = getDB();
$page = $db->query("SELECT * FROM content_pages WHERE slug = 'cara-pemesanan'")->fetch();

include __DIR__ . '/includes/header.php';
?>

<div class="section-py" style="background: #FFFFFF; border-bottom: 1px solid var(--border-color);">
    <div class="container container-narrow">
        <!-- Minimalist Back Button -->
        <div style="margin-bottom: 12px;">
            <a href="javascript:void(0)" onclick="if(window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1){window.history.back();}else{window.location.href='<?= BASE_URL ?>/index.php';}" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>

        <div style="text-align: center;">
            <span class="section-subtitle">Panduan Belanja</span>
            <h1 class="section-title">Cara Pemesanan Bouquet</h1>
            <p class="section-desc">Langkah mudah dan praktis memesan buket handmade chenille stem di Laviosa Palembang.</p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        <div style="background: #FFFFFF; padding: 40px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); line-height: 1.8;">
            <?= $page ? $page['content'] : 'Informasi cara pemesanan sedang diperbarui.' ?>

            <div style="margin-top: 36px; text-align: center; border-top: 1px solid var(--border-color); padding-top: 24px;">
                <a href="<?= BASE_URL ?>/catalog.php" class="btn btn-primary btn-lg">
                    <i class="fa-solid fa-basket-shopping"></i> Mulai Pilih Buket di Katalog
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Leviosa Crafts - Tentang Kami Page
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "Tentang Kami";
$db = getDB();
$page = $db->query("SELECT * FROM content_pages WHERE slug = 'tentang-kami'")->fetch();

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
            <span class="section-subtitle">Our Story</span>
            <h1 class="section-title">Tentang Laviosa</h1>
            <p class="section-desc">Boutique buket chenille stem berkualitas tinggi yang menghadirkan keindahan abadi di Kota Palembang.</p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        <div style="background: #FFFFFF; padding: 40px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); line-height: 1.8;">
            <?= $page ? $page['content'] : 'Konten tentang kami sedang diperbarui.' ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * Leviosa Crafts - Kontak Kami Page
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "Hubungi Kami";
$storeAddress = getStoreSetting('store_address', 'Ujuk Kost, Lorong Siguntang, Bukit Lama, Kec. Ilir Barat I, Kota Palembang, Sumatera Selatan 30128, Indonesia.');
$storeWhatsapp = getStoreWhatsapp();
$storeEmail = getStoreSetting('store_email', 'halo@leviosacrafts.com');
$storeHours = getStoreSetting('store_hours', 'Senin - Sabtu: 08.00 - 17.00 WIB');

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
            <span class="section-subtitle">Customer Care</span>
            <h1 class="section-title">Hubungi Laviosa</h1>
            <p class="section-desc">Kami senang mendengar dari Anda! Silakan hubungi kami untuk konsultasi buket atau pertanyaan lainnya.</p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
            <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); text-align: center;">
                <div style="font-size: 2.5rem; color: #25D366; margin-bottom: 12px;"><i class="fa-brands fa-whatsapp"></i></div>
                <h3 style="font-size: 1.15rem; margin-bottom: 6px;">WhatsApp Store</h3>
                <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">Respon cepat untuk pemesanan &amp; konsultasi custom.</p>
                <a href="https://wa.me/<?= e($storeWhatsapp) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
                    Chat WhatsApp
                </a>
            </div>

            <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); text-align: center;">
                <div style="font-size: 2.5rem; color: var(--primary); margin-bottom: 12px;"><i class="fa-solid fa-location-dot"></i></div>
                <h3 style="font-size: 1.15rem; margin-bottom: 6px;">Titik Pickup</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;"><?= e($storeAddress) ?></p>
                <span class="badge badge-success">Jam Layanan: <?= e($storeHours) ?></span>
            </div>
        </div>

        <div style="background: #FFFFFF; padding: 32px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft);">
            <h3 style="font-family: var(--font-heading); font-size: 1.3rem; margin-bottom: 16px;">Kirim Pesan Cepat</h3>
            <div style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 20px;">
                Anda juga dapat mengirimkan pesan email resmi ke: <a href="mailto:<?= e($storeEmail) ?>" style="color: var(--primary-dark); font-weight: 600;"><?= e($storeEmail) ?></a>
            </div>
            <a href="https://wa.me/<?= e($storeWhatsapp) ?>?text=<?= urlencode('Halo Admin Leviosa Crafts, saya ingin bertanya tentang buket...') ?>" target="_blank" rel="noopener" class="btn btn-primary btn-block">
                <i class="fa-brands fa-whatsapp"></i> Buka Percakapan WhatsApp Langsung
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

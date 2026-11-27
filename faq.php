<?php
/**
 * Leviosa Crafts - FAQ Page
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';

$pageTitle = "FAQ & Informasi";
$db = getDB();
$page = $db->query("SELECT * FROM content_pages WHERE slug = 'faq'")->fetch();

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
            <span class="section-subtitle">Pusat Informasi</span>
            <h1 class="section-title">Pertanyaan yang Sering Diajukan (FAQ)</h1>
            <p class="section-desc">Temukan jawaban cepat seputar bahan kawat bulu, estimasi pengerjaan, ongkir, dan sistem pembayaran.</p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container container-narrow">
        
        <!-- Modern Accordion FAQ Component -->
        <div class="faq-accordion">
            <div class="faq-item active">
                <button type="button" class="faq-question-btn" aria-expanded="true">
                    <span class="faq-question-text">Apakah bunganya asli atau berbahan kawat bulu (chenille stem)?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Semua buket bunga Laviosa dibuat 100% menggunakan <em>chenille stem</em> (kawat bulu / pipe cleaner) impor berkualitas premium yang padat dan halus, dipadukan dengan kertas wrapping impor pilihan dan pita satin. Bunga ini mekar abadi, tidak membutuhkan air, bebas debu berlebih, tidak akan membusuk, dan awet disimpan bertahun-tahun sebagai kenang-kenangan terindah.</p>
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
                        <p>Untuk buket Ready Design dari katalog resmi, minimal pemesanan H-1 (1 hari kerja sebelum tanggal pengantaran). Untuk pesanan Custom Bouquet dan Custom Item (bawa barang sendiri), lead time adalah 2–3 hari kerja demi memastikan hasil pengerjaan rapi, detail, dan sesuai ekspektasi Anda.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Apakah warna bunga dan kertas wrapping bisa di-custom?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Tentu saja! Hampir seluruh produk kami menyediakan pilihan warna utama (single color) atau perpaduan dua warna pastel (dual colors), serta pilihan model kertas pembungkus (wrapping paper). Seluruh warna dapat disesuaikan sesuai keinginan dan ketersediaan stok bahan baku.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Bagaimana cara kerja pesanan Custom Item (bawa barang sendiri)?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Pada pesanan Custom Item, Anda dapat menggabungkan hadiah fisik milik Anda sendiri (seperti tumbler Corkcicle, parfum, boneka pribadi, perhiasan, atau skincare) ke dalam rangkaian buket kawat bulu kami. Pelanggan wajib mengantarkan atau mengirimkan barang fisik ke alamat toko kami <strong>maksimal 12 jam</strong> setelah pesanan dibuat di website. Sebelum buket dikirimkan, admin akan mengunggah foto preview buket jadi untuk Anda setujui.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Bagaimana penentuan ongkos kirim (delivery) dan lokasi Self-Pickup?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Ongkir delivery dihitung otomatis berdasarkan rute jarak jalan riil dari toko kami (Ujuk Kost, Lorong Siguntang, Bukit Lama Palembang) ke pin peta lokasi penerima:<br>
                        • <strong>0 – 3 km:</strong> Rp5.000<br>
                        • <strong>>3 – 6 km:</strong> Rp10.000<br>
                        • <strong>>6 – 10 km:</strong> Rp15.000<br>
                        • <strong>Di atas 10 km:</strong> Menunggu konfirmasi ongkir manual dari admin.<br>
                        • <strong>Self-Pickup:</strong> Gratis (Rp0) dengan mengambil langsung di titik toko kami sesuai jadwal slot waktu yang dipilih.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Metode pembayaran apa saja yang tersedia?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Kami menerima pembayaran digital non-tunai melalui QRIS (dapat dipindai dari seluruh bank dan e-wallet seperti GoPay, OVO, Dana, ShopeePay, BCA, Mandiri, BRI), Transfer Rekening SeaBank, dan Transfer DANA. Batas waktu pembayaran dan upload bukti transfer adalah 24 jam sejak pesanan dibuat.</p>
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <button type="button" class="faq-question-btn" aria-expanded="false">
                    <span class="faq-question-text">Apakah pesanan yang sudah dibayar dapat dibatalkan?</span>
                    <span class="faq-icon-pill">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </button>
                <div class="faq-answer-collapse">
                    <div class="faq-answer-inner">
                        <p>Pembatalan pesanan hanya dapat diajukan saat status pesanan masih <em>Pesanan Terkonfirmasi</em> (belum masuk tahap perakitan fisik oleh pengrajin). Jika status sudah berubah menjadi <em>Pesanan Sedang Diproses</em>, buket sudah mulai dirangkai dan bahan kawat bulu telah dipotong khusus untuk Anda, sehingga pesanan tidak dapat dibatalkan.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help Notice Box -->
        <div style="margin-top: 40px; background: linear-gradient(135deg, #FFF9F9 0%, #FAF2F5 100%); padding: 28px; border-radius: var(--radius-lg); border: 1px solid #F3E5E7; text-align: center;">
            <h3 style="font-family: var(--font-heading); font-size: 1.2rem; color: var(--text-dark); margin-bottom: 6px;">
                Punya Pertanyaan Lain yang Belum Terjawab?
            </h3>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 18px;">
                Tim ramah Laviosa siap membantu menjawab pertanyaan Anda dan mendiskusikan buket terbaik untuk momen berharga Anda.
            </p>
            <a href="https://wa.me/<?= e(getStoreWhatsapp()) ?>" target="_blank" rel="noopener" class="btn btn-outline-wa" style="font-size: 0.9rem;">
                <i class="fa-brands fa-whatsapp"></i> Chat WhatsApp Kami
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

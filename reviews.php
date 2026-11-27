<?php
/**
 * Leviosa Crafts - Customer Reviews Page
 * Lists verified approved reviews and accepts submissions only from completed orders
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/upload.php';

$pageTitle = "Ulasan Pelanggan";
$db = getDB();

$prefilledOrderCode = trim($_GET['order_id'] ?? '');

// Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!verifyCsrf()) {
        setFlash('danger', 'Token CSRF tidak valid.');
        header("Location: " . BASE_URL . "/reviews.php");
        exit;
    }

    $orderCode = strtoupper(trim($_POST['order_code'] ?? ''));
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $comment = trim($_POST['comment'] ?? '');

    if (empty($orderCode) || empty($comment)) {
        setFlash('danger', 'Silakan masukkan Order ID dan komentar ulasan Anda.');
        header("Location: " . BASE_URL . "/reviews.php?order_id=" . urlencode($orderCode));
        exit;
    }

    // 1. Verify Order exists and is 'pesanan_selesai'
    $stmtO = $db->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmtO->execute([$orderCode]);
    $order = $stmtO->fetch();

    if (!$order) {
        setFlash('danger', 'Order ID tidak ditemukan dalam database.');
        header("Location: " . BASE_URL . "/reviews.php?order_id=" . urlencode($orderCode));
        exit;
    }

    if ($order['order_status'] !== 'pesanan_selesai') {
        setFlash('danger', 'Hanya pesanan yang telah berstatus "Pesanan Selesai" yang dapat memberikan ulasan produk.');
        header("Location: " . BASE_URL . "/reviews.php?order_id=" . urlencode($orderCode));
        exit;
    }

    // 2. Check if this order has already submitted a review
    $stmtCheck = $db->prepare("SELECT id FROM reviews WHERE order_id = ?");
    $stmtCheck->execute([$order['id']]);
    if ($stmtCheck->fetch()) {
        setFlash('warning', 'Pesanan ini sudah pernah memberikan ulasan. Terima kasih atas partisipasi Anda!');
        header("Location: " . BASE_URL . "/reviews.php");
        exit;
    }

    // 3. Upload Photo if provided
    $photoPath = null;
    if (!empty($_FILES['review_photo']['name'])) {
        $uploadRes = Uploader::upload($_FILES['review_photo'], 'assets/uploads/reviews/');
        if ($uploadRes['success']) {
            $photoPath = $uploadRes['path'];
        }
    }

    // 4. Insert Review (defaults to 'pending' for admin moderation)
    $stmtIns = $db->prepare("
        INSERT INTO reviews (order_id, customer_name, rating, comment, photo_path, status, is_featured)
        VALUES (?, ?, ?, ?, ?, 'pending', 0)
    ");
    $stmtIns->execute([
        $order['id'],
        $order['customer_name'],
        $rating,
        $comment,
        $photoPath
    ]);

    setFlash('success', 'Terima kasih atas ulasan Anda! Ulasan Anda telah kami terima dan akan segera ditampilkan setelah diverifikasi admin.');
    header("Location: " . BASE_URL . "/reviews.php");
    exit;
}

// Fetch Approved Reviews
$stmtR = $db->query("
    SELECT r.*, o.order_code, o.order_type
    FROM reviews r
    JOIN orders o ON r.order_id = o.id
    WHERE r.status = 'approved'
    ORDER BY r.is_featured DESC, r.id DESC
");
$reviews = $stmtR->fetchAll();

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
            <span class="section-subtitle">Testimoni Pembeli</span>
            <h1 class="section-title">Ulasan Pelanggan Laviosa</h1>
            <p class="section-desc">
                Kepuasan Anda adalah kebanggaan kami. Seluruh ulasan di bawah ini berasal dari pelanggan terverifikasi yang telah menerima pesanan buket chenille stem.
            </p>
        </div>
    </div>
</div>

<div class="section-py">
    <div class="container">
        
        <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: 36px; align-items: flex-start;">
            
            <!-- Left: Submit Review Form (Completed Orders Only) -->
            <div style="background: #FFFFFF; padding: 28px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-soft); position: sticky; top: 100px;">
                <h3 style="font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 12px; color: var(--primary-dark);">
                    <i class="fa-solid fa-pen-nib"></i> Tulis Ulasan Buket Anda
                </h3>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 20px;">
                    Hanya pelanggan dengan status <strong>Pesanan Selesai</strong> yang dapat memberikan testimoni asli.
                </p>

                <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/reviews.php">
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label">Order ID Anda <span class="required">*</span></label>
                        <input type="text" name="order_code" value="<?= e($prefilledOrderCode) ?>" placeholder="Contoh: LC-20261121-7A9B" class="form-control" style="font-family: monospace; text-transform: uppercase;" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Beri Rating Bintang <span class="required">*</span></label>
                        <select name="rating" class="form-control form-select" required>
                            <option value="5">⭐⭐⭐⭐⭐ (5 Bintang - Sangat Puas)</option>
                            <option value="4">⭐⭐⭐⭐ (4 Bintang - Bagus)</option>
                            <option value="3">⭐⭐⭐ (3 Bintang - Cukup)</option>
                            <option value="2">⭐⭐ (2 Bintang - Kurang)</option>
                            <option value="1">⭐ (1 Bintang - Sangat Kurang)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Komentar Ulasan Anda <span class="required">*</span></label>
                        <textarea name="comment" rows="3" class="form-control" placeholder="Ceritakan pengalaman Anda mengenai kualitas buket kawat bulu, kerapian wrapping, dan pelayanan Leviosa Crafts..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Foto Buket Asli (Opsional)</label>
                        <input type="file" name="review_photo" accept="image/jpeg,image/png,image/webp" class="form-control">
                        <div class="form-hint">Format JPG, PNG, atau WEBP. Maks 5MB.</div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" name="submit_review" class="btn btn-primary btn-block">
                            <i class="fa-solid fa-paper-plane"></i> Kirimkan Ulasan
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right: Approved Reviews List -->
            <div>
                <?php if (!empty($reviews)): ?>
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <?php foreach ($reviews as $rev): ?>
                            <div class="review-card" style="margin-bottom: 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <div class="review-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fa-<?= $i <= $rev['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="badge badge-success" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-shield-check"></i> Terverifikasi
                                    </span>
                                </div>

                                <p class="review-comment" style="font-size: 0.98rem; line-height: 1.6;">
                                    "<?= e($rev['comment']) ?>"
                                </p>

                                <?php if (!empty($rev['photo_path'])): ?>
                                    <div style="margin-top: 12px;">
                                        <img src="<?= BASE_URL . '/' . e($rev['photo_path']) ?>" alt="Foto Buket Pelanggan" style="max-height: 160px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); object-fit: cover;">
                                    </div>
                                <?php endif; ?>

                                <div class="review-author" style="margin-top: 16px; border-top: 1px solid var(--border-color); padding-top: 12px;">
                                    <div class="review-avatar"><?= strtoupper(substr($rev['customer_name'], 0, 1)) ?></div>
                                    <div>
                                        <div class="review-name"><?= e($rev['customer_name']) ?></div>
                                        <div class="review-date">Order #<?= e($rev['order_code']) ?> &bull; <?= formatDateIndo($rev['created_at']) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="background: #FFFFFF; padding: 60px 20px; text-align: center; border-radius: var(--radius-lg); border: 1px solid var(--border-color);">
                        <i class="fa-regular fa-comments" style="font-size: 3rem; color: var(--secondary); margin-bottom: 16px;"></i>
                        <h3 style="font-size: 1.25rem; margin-bottom: 8px;">Belum Ada Ulasan yang Ditampilkan</h3>
                        <p style="color: var(--text-muted); font-size: 0.95rem;">Jadilah pelanggan pertama yang memberikan ulasan dan foto buket manis Anda!</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>


<?php
// api/mark_notifications_read.php - Bildirimleri okundu olarak işaretleme API

session_start();
require_once '../config/database.php';

// JSON yanıtı ayarla
header('Content-Type: application/json');

// Kullanıcı giriş yapmamışsa hata döndür
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Oturum bulunamadı']);
    exit;
}

// POST isteği değilse hata döndür
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Yalnızca POST istekleri kabul edilir']);
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

// Belirli bir bildirim ID'si varsa o bildirimi, yoksa tüm bildirimleri okundu olarak işaretle
if (isset($_POST['notification_id'])) {
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE user_id = :user_id AND id = :notification_id
    ");
    $stmt->execute([
        'user_id' => $_SESSION['user_id'],
        'notification_id' => $_POST['notification_id']
    ]);
} else {
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE user_id = :user_id AND is_read = 0
    ");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
}

echo json_encode(['success' => true]);
?>


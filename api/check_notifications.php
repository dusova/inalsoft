<?php
// api/check_notifications.php - Bildirim kontrolü API

session_start();
require_once '../config/database.php';

// JSON yanıtı ayarla
header('Content-Type: application/json');

// Kullanıcı giriş yapmamışsa hata döndür
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Oturum bulunamadı']);
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

// Okunmamış bildirimleri al
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = :user_id AND is_read = 0 
    ORDER BY created_at DESC
");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Bildirim sayısını ve son 5 bildirimi döndür
$count = count($notifications);
$recent_notifications = array_slice($notifications, 0, 5);

echo json_encode([
    'count' => $count,
    'notifications' => $recent_notifications
]);
?>

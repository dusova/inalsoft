<?php
// calendar/delete_event.php - Etkinlik silme işlemi

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Sadece POST isteklerini işle
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Geçersiz istek yöntemi.";
    header("Location: index.php");
    exit;
}

// CSRF token kontrolü
if (!verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
    header("Location: index.php");
    exit;
}

// Etkinlik ID'sini al
$event_id = intval($_POST['event_id']);

if ($event_id <= 0) {
    $_SESSION['error'] = "Geçersiz etkinlik ID'si.";
    header("Location: index.php");
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

try {
    // Etkinliği getir
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE id = :id");
    $stmt->execute(['id' => $event_id]);
    $event = $stmt->fetch();
    
    if (!$event) {
        $_SESSION['error'] = "Etkinlik bulunamadı.";
        header("Location: index.php");
        exit;
    }
    
    // Yetki kontrolü - sadece etkinliği oluşturan veya admin silebilir
    if ($event['created_by'] != $_SESSION['user_id'] && $_SESSION['role'] != 'admin') {
        $_SESSION['error'] = "Bu etkinliği silme yetkiniz yok.";
        header("Location: index.php");
        exit;
    }
    
    // Etkinliği sil
    $stmt = $db->prepare("DELETE FROM calendar_events WHERE id = :id");
    $result = $stmt->execute(['id' => $event_id]);
    
    if ($result) {
        // Aktiviteyi logla
        log_activity($_SESSION['user_id'], 'delete', 'calendar_event', $event_id, "Takvim etkinliği silindi: " . $event['title']);
        
        $_SESSION['success'] = "Etkinlik başarıyla silindi.";
    } else {
        $_SESSION['error'] = "Etkinlik silinirken bir hata oluştu.";
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
}

// Ay görünümüne geri dön
$month = date('m');
$year = date('Y');
header("Location: index.php?month=$month&year=$year");
exit;
?>


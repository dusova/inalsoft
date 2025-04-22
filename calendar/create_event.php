<?php
// calendar/create_event.php - Yeni etkinlik oluşturma işlemi

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

// Form verilerini al ve temizle
$title = sanitize_input($_POST['title']);
$description = sanitize_input($_POST['description'] ?? '');
$start_datetime = sanitize_input($_POST['start_datetime']);
$end_datetime = sanitize_input($_POST['end_datetime']);
$all_day = isset($_POST['all_day']) ? 1 : 0;
$location = sanitize_input($_POST['location'] ?? '');
$event_type = sanitize_input($_POST['event_type']);
$related_type = sanitize_input($_POST['related_type'] ?? '');
$related_id = !empty($_POST['related_id']) ? intval($_POST['related_id']) : null;

// Zorunlu alanları kontrol et
if (empty($title) || empty($start_datetime) || empty($end_datetime) || empty($event_type)) {
    $_SESSION['error'] = "Lütfen tüm zorunlu alanları doldurun.";
    header("Location: index.php");
    exit;
}

// Datetime formatını kontrol et
if (!preg_match("/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/", $start_datetime) || !preg_match("/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/", $end_datetime)) {
    $_SESSION['error'] = "Geçersiz tarih veya saat formatı.";
    header("Location: index.php");
    exit;
}

// Datetime formatını veritabanı formatına dönüştür
$start_datetime = str_replace('T', ' ', $start_datetime) . ':00';
$end_datetime = str_replace('T', ' ', $end_datetime) . ':00';

// Bitiş zamanı başlangıç zamanından sonra olmalı
if (strtotime($end_datetime) <= strtotime($start_datetime)) {
    $_SESSION['error'] = "Bitiş zamanı başlangıç zamanından sonra olmalıdır.";
    header("Location: index.php");
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

try {
    // Etkinliği ekle
    $stmt = $db->prepare("
        INSERT INTO calendar_events (title, description, start_datetime, end_datetime, all_day, location, event_type, related_id, related_type, created_by, created_at) 
        VALUES (:title, :description, :start_datetime, :end_datetime, :all_day, :location, :event_type, :related_id, :related_type, :created_by, NOW())
    ");
    
    $result = $stmt->execute([
        'title' => $title,
        'description' => $description,
        'start_datetime' => $start_datetime,
        'end_datetime' => $end_datetime,
        'all_day' => $all_day,
        'location' => $location,
        'event_type' => $event_type,
        'related_id' => $related_id,
        'related_type' => $related_type,
        'created_by' => $_SESSION['user_id']
    ]);
    
    if ($result) {
        $event_id = $db->lastInsertId();
        
        // Aktiviteyi logla
        log_activity(
            $_SESSION['user_id'], 
            'create', 
            'calendar_event', 
            $event_id, 
            "Yeni takvim etkinliği oluşturuldu: $title"
        );
        
        // İlgili öğeye bağlı ise orada göster
        if (!empty($related_type) && !empty($related_id)) {
            // İlgili öğe işlemleri (örneğin toplantı veya proje ile ilişkilendirme)
            // Bu kısım isteğe bağlı olarak genişletilebilir
        }
        
        // Başarılı mesajı
        $_SESSION['success'] = "Etkinlik başarıyla oluşturuldu.";
        
        // Ay görünümüne geri dön
        $month = date('m', strtotime($start_datetime));
        $year = date('Y', strtotime($start_datetime));
        header("Location: index.php?month=$month&year=$year");
        exit;
    } else {
        throw new Exception("Etkinlik oluşturulurken bir hata oluştu.");
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Etkinlik oluşturulurken bir hata oluştu: " . $e->getMessage();
    header("Location: index.php");
    exit;
}
?>


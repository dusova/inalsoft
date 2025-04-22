<?php
// calendar/get_event.php - Etkinlik bilgilerini JSON formatında döndüren API

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit;
}

// JSON yanıtı için header ayarla
header('Content-Type: application/json');

// Etkinlik ID'sini kontrol et
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz etkinlik ID\'si']);
    exit;
}

$event_id = intval($_GET['id']);

// Veritabanı bağlantısı
$db = connect_db();

try {
    // Etkinliği getir
    $stmt = $db->prepare("
        SELECT e.*, u.full_name as created_by_name
        FROM calendar_events e
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.id = :id
    ");
    $stmt->execute(['id' => $event_id]);
    $event = $stmt->fetch();
    
    if ($event) {
        // İlişkili öğe bilgilerini ekle
        if (!empty($event['related_type']) && !empty($event['related_id'])) {
            switch ($event['related_type']) {
                case 'project':
                    $stmt = $db->prepare("SELECT name FROM projects WHERE id = :id");
                    $stmt->execute(['id' => $event['related_id']]);
                    $related_object = $stmt->fetch();
                    if ($related_object) {
                        $event['related_name'] = $related_object['name'];
                    }
                    break;
                case 'meeting':
                    $stmt = $db->prepare("SELECT title FROM meetings WHERE id = :id");
                    $stmt->execute(['id' => $event['related_id']]);
                    $related_object = $stmt->fetch();
                    if ($related_object) {
                        $event['related_name'] = $related_object['title'];
                    }
                    break;
                case 'task':
                    $stmt = $db->prepare("SELECT title FROM tasks WHERE id = :id");
                    $stmt->execute(['id' => $event['related_id']]);
                    $related_object = $stmt->fetch();
                    if ($related_object) {
                        $event['related_name'] = $related_object['title'];
                    }
                    break;
            }
        }
        
        // Tarihleri formatla
        $event['formatted_start'] = format_date($event['start_datetime']);
        $event['formatted_end'] = format_date($event['end_datetime']);
        
        echo json_encode(['success' => true, 'event' => $event]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Etkinlik bulunamadı']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
}
?>


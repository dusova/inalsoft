<?php
// api/get_related_items.php - İlişkili öğeleri getiren API

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit;
}

// JSON yanıtı için header ayarla
header('Content-Type: application/json');

// Tür parametresini kontrol et
if (!isset($_GET['type']) || empty($_GET['type'])) {
    echo json_encode(['success' => false, 'message' => 'Öğe türü belirtilmedi']);
    exit;
}

$type = sanitize_input($_GET['type']);

// Geçerli türleri kontrol et
if (!in_array($type, ['project', 'task', 'meeting'])) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz öğe türü']);
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

try {
    $items = [];
    
    // Türe göre öğeleri getir
    switch ($type) {
        case 'project':
            $stmt = $db->prepare("
                SELECT id, name 
                FROM projects 
                WHERE status != 'completed' 
                ORDER BY name ASC
            ");
            $stmt->execute();
            $items = $stmt->fetchAll();
            break;
        
        case 'task':
            $stmt = $db->prepare("
                SELECT t.id, t.title as name, p.name as project_name
                FROM tasks t
                JOIN projects p ON t.project_id = p.id
                WHERE t.status != 'done'
                ORDER BY t.due_date ASC
            ");
            $stmt->execute();
            $tasks = $stmt->fetchAll();
            
            // Proje adını ekleyerek görevleri düzenle
            foreach ($tasks as $task) {
                $items[] = [
                    'id' => $task['id'],
                    'name' => $task['name'] . ' (' . $task['project_name'] . ')'
                ];
            }
            break;
        
        case 'meeting':
            $stmt = $db->prepare("
                SELECT id, title as name
                FROM meetings
                WHERE meeting_date >= NOW()
                ORDER BY meeting_date ASC
            ");
            $stmt->execute();
            $items = $stmt->fetchAll();
            break;
    }
    
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
}
?>
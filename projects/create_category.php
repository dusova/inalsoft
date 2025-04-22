<?php
// projects/create_category.php - Yeni kategori oluşturma API

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit;
}

// Sadece POST isteklerini işle
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek yöntemi']);
    exit;
}

// AJAX isteği ise JSON formatında yanıt döndür
header('Content-Type: application/json');

// Form verilerini al
$name = isset($_POST['name']) ? sanitize_input($_POST['name']) : '';
$parent_id = isset($_POST['parent_id']) && !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;

// Debug bilgisi
error_log("Kategori Oluşturma: İsim = " . $name . ", Parent ID = " . $parent_id);

// Alan kontrolü
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Kategori adı zorunludur']);
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

try {
    // Veritabanı tablo yapısını kontrol et
    $tableInfo = $db->query("DESCRIBE project_categories");
    $columns = $tableInfo->fetchAll(PDO::FETCH_COLUMN);
    
    error_log("Tablo sütunları: " . implode(", ", $columns));
    
    // Kategoriyi ekle
    $stmt = $db->prepare("INSERT INTO project_categories (name, parent_id) VALUES (:name, :parent_id)");
    $result = $stmt->execute([
        'name' => $name,
        'parent_id' => $parent_id
    ]);
    
    if ($result) {
        $category_id = $db->lastInsertId();
        error_log("Kategori başarıyla oluşturuldu. ID: " . $category_id);
        
        // Eklenen kategoriyi doğrula
        $checkStmt = $db->prepare("SELECT * FROM project_categories WHERE id = :id");
        $checkStmt->execute(['id' => $category_id]);
        $categoryData = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Doğrulama: " . json_encode($categoryData));
        
        // Aktiviteyi logla
        log_activity($_SESSION['user_id'], 'create', 'category', $category_id, "Yeni kategori oluşturuldu: $name");
        
        // Kategori bilgisini döndür
        $response = [
            'success' => true,
            'message' => 'Kategori başarıyla oluşturuldu',
            'category' => [
                'id' => $category_id,
                'name' => $name,
                'parent_id' => $parent_id
            ]
        ];
        
        echo json_encode($response);
    } else {
        error_log("Kategori oluşturma hatası");
        echo json_encode(['success' => false, 'message' => 'Kategori oluşturulurken bir hata oluştu']);
    }
} catch (PDOException $e) {
    error_log("PDO Hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
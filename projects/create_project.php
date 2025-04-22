<?php
// projects/create_project.php - Yeni proje oluşturma işlemi

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
$name = sanitize_input($_POST['name']);
$description = sanitize_input($_POST['description'] ?? '');
$status = sanitize_input($_POST['status']);
$priority = sanitize_input($_POST['priority']);
$start_date = sanitize_input($_POST['start_date']);
$due_date = sanitize_input($_POST['due_date']);

// Kategori seçimini kontrol et
if (!isset($_POST['category']) || empty($_POST['category'])) {
    $_SESSION['error'] = "Lütfen bir kategori seçin.";
    header("Location: index.php");
    exit;
}

// Kategori ID'sini al
$category_id = intval($_POST['category']);

// Veritabanı bağlantısı
$db = connect_db();

// Kategori adına göre ENUM değerini belirle
$stmt = $db->prepare("SELECT name FROM project_categories WHERE id = :id");
$stmt->execute(['id' => $category_id]);
$categoryData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$categoryData) {
    $_SESSION['error'] = "Seçilen kategori bulunamadı.";
    header("Location: index.php");
    exit;
}

// Kategori adını ENUM değerine dönüştür
$categoryEnumValue = 'other'; // Varsayılan değer
$categoryName = strtolower($categoryData['name']);

// Basit bir eşleştirme algoritması
if (strpos($categoryName, 'web') !== false || strpos($categoryName, 'site') !== false || strpos($categoryName, 'tasarım') !== false) {
    $categoryEnumValue = 'website';
} else if (strpos($categoryName, 'sosyal') !== false || strpos($categoryName, 'social') !== false || strpos($categoryName, 'medya') !== false) {
    $categoryEnumValue = 'social_media';
} else if (strpos($categoryName, 'bionluk') !== false || strpos($categoryName, 'Bionluk') !== false || strpos($categoryName, 'BiOnluk') !== false) {
    $categoryEnumValue = 'bionluk';
} else if (strpos($categoryName, 'Marka Yönetimi') !== false || 
strpos($categoryName, 'marka yönetimi') !== false || 
strpos($categoryName, 'MARKA YÖNETİMİ') !== false) {
    $categoryEnumValue = 'marka';
}

// Zorunlu alanları kontrol et
if (empty($name) || empty($status) || empty($priority) || empty($start_date) || empty($due_date)) {
    $_SESSION['error'] = "Lütfen tüm zorunlu alanları doldurun.";
    header("Location: index.php");
    exit;
}

// Tarih formatını kontrol et
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $due_date)) {
    $_SESSION['error'] = "Geçersiz tarih formatı.";
    header("Location: index.php");
    exit;
}

// Bitiş tarihi başlangıç tarihinden önce olamaz
if (strtotime($due_date) < strtotime($start_date)) {
    $_SESSION['error'] = "Bitiş tarihi başlangıç tarihinden önce olamaz.";
    header("Location: index.php");
    exit;
}

try {
    // Yeni projeyi ekle
    $stmt = $db->prepare("
        INSERT INTO projects (name, description, category, status, priority, start_date, due_date, created_by, created_at) 
        VALUES (:name, :description, :category, :status, :priority, :start_date, :due_date, :created_by, NOW())
    ");
    
    $result = $stmt->execute([
        'name' => $name,
        'description' => $description,
        'category' => $categoryEnumValue,  // ENUM değeri ('website', 'social_media', 'other')
        'status' => $status,
        'priority' => $priority,
        'start_date' => $start_date,
        'due_date' => $due_date,
        'created_by' => $_SESSION['user_id']
    ]);
    
    if ($result) {
        $project_id = $db->lastInsertId();
        
        // Projeyi kategori ile ilişkilendir (meta tablo ile)
        $stmt = $db->prepare("
            INSERT INTO project_category_relation (project_id, category_id) 
            VALUES (:project_id, :category_id)
        ");
        
        // Bu sorgu hata verirse, project_category_relation tablosunu oluşturmanız gerekecek
        try {
            $stmt->execute([
                'project_id' => $project_id,
                'category_id' => $category_id
            ]);
        } catch (Exception $e) {
            // İlişki tablosu hatası kritik değil, devam et
        }
        
        // Aktiviteyi logla
        log_activity($_SESSION['user_id'], 'create', 'project', $project_id, "Yeni proje oluşturuldu: $name");
        
        // Başarılı mesajı
        $_SESSION['success'] = "Proje başarıyla oluşturuldu.";
        header("Location: view.php?id=$project_id");
        exit;
    } else {
        throw new Exception("Proje oluşturulurken bir hata oluştu.");
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Proje oluşturulurken bir hata oluştu: " . $e->getMessage();
    header("Location: index.php");
    exit;
}
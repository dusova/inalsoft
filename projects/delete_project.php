<?php
// projects/delete_project.php - Proje silme işlemi

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

// Proje ID'sini al
$project_id = intval($_POST['project_id']);

if ($project_id <= 0) {
    $_SESSION['error'] = "Geçersiz proje ID'si.";
    header("Location: index.php");
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

try {
    // İşlem başlat
    $db->beginTransaction();
    
    // Proje var mı ve kullanıcının silme yetkisi var mı kontrol et
    $stmt = $db->prepare("SELECT created_by FROM projects WHERE id = :id");
    $stmt->execute(['id' => $project_id]);
    $project = $stmt->fetch();
    
    if (!$project) {
        throw new Exception("Proje bulunamadı.");
    }
    
    // Sadece proje sahibi veya admin silebilir
    if ($project['created_by'] != $_SESSION['user_id'] && $_SESSION['role'] != 'admin') {
        throw new Exception("Bu projeyi silme yetkiniz yok.");
    }
    
    // Projeye ait görevleri sil
    $stmt = $db->prepare("DELETE FROM tasks WHERE project_id = :project_id");
    $stmt->execute(['project_id' => $project_id]);
    
    // Projeye ait dosyaları sil
    $stmt = $db->prepare("DELETE FROM files WHERE entity_type = 'project' AND entity_id = :project_id");
    $stmt->execute(['project_id' => $project_id]);
    
    // Projeye ait yorumları sil
    $stmt = $db->prepare("DELETE FROM comments WHERE entity_type = 'project' AND entity_id = :project_id");
    $stmt->execute(['project_id' => $project_id]);
    
    // Projeye ait bildirimleri sil
    $stmt = $db->prepare("DELETE FROM notifications WHERE type = 'project' AND related_id = :project_id");
    $stmt->execute(['project_id' => $project_id]);
    
    // Projeyi sil
    $stmt = $db->prepare("DELETE FROM projects WHERE id = :id");
    $stmt->execute(['id' => $project_id]);
    
    // İşlemi tamamla
    $db->commit();
    
    // Aktiviteyi logla
    log_activity($_SESSION['user_id'], 'delete', 'project', $project_id, "Proje silindi");
    
    $_SESSION['success'] = "Proje başarıyla silindi.";
} catch (Exception $e) {
    // Hata durumunda işlemi geri al
    $db->rollBack();
    $_SESSION['error'] = "Proje silinirken bir hata oluştu: " . $e->getMessage();
}

header("Location: index.php");
exit;
?>

<?php
// api/update_theme_preference.php - Tema tercihini güncelleme API

session_start();
require_once '../config/database.php';

// JSON yanıtı ayarla
header('Content-Type: application/json');

// Kullanıcı giriş yapmamışsa hata döndür
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum bulunamadı']);
    exit;
}

// POST isteği değilse hata döndür
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Yalnızca POST istekleri kabul edilir']);
    exit;
}

// Tema tercihini al
$theme = isset($_POST['theme']) ? sanitize_input($_POST['theme']) : 'light';

// Sadece geçerli temaları kabul et
if (!in_array($theme, ['light', 'dark'])) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz tema tercihi']);
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

// Kullanıcının tema tercihini güncelle
$stmt = $db->prepare("UPDATE users SET theme_preference = :theme WHERE id = :id");
$stmt->execute([
    'theme' => $theme,
    'id' => $_SESSION['user_id']
]);

echo json_encode(['success' => true, 'theme' => $theme]);
?>
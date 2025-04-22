<?php
// meetings/add_notes.php - Toplantıya not ekleme

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// CSRF token kontrolü
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['error'] = "Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
    header("Location: index.php");
    exit;
}

// Post verilerini kontrol et
if (!isset($_POST['meeting_id']) || !is_numeric($_POST['meeting_id'])) {
    $_SESSION['error'] = "Geçersiz toplantı bilgisi.";
    header("Location: index.php");
    exit;
}

$meeting_id = intval($_POST['meeting_id']);
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "index.php";
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

// Not boş mu kontrol et
if (empty($notes)) {
    $_SESSION['error'] = "Not alanı boş bırakılamaz.";
    header("Location: $redirect");
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

// Toplantıyı kontrol et
$stmt = $db->prepare("SELECT * FROM meetings WHERE id = :id");
$stmt->execute(['id' => $meeting_id]);
$meeting = $stmt->fetch();

if (!$meeting) {
    $_SESSION['error'] = "Toplantı bulunamadı.";
    header("Location: index.php");
    exit;
}

// Toplantının geçmiş olduğunu kontrol et (notlar sadece geçmiş toplantılara eklenebilir)
if (strtotime($meeting['meeting_date']) >= time()) {
    $_SESSION['error'] = "Notlar sadece geçmiş toplantılara eklenebilir.";
    header("Location: $redirect");
    exit;
}

// Kullanıcı toplantı katılımcısı mı kontrol et
$stmt = $db->prepare("SELECT user_id FROM meeting_participants WHERE meeting_id = :meeting_id AND user_id = :user_id");
$stmt->execute([
    'meeting_id' => $meeting_id,
    'user_id' => $_SESSION['user_id']
]);

$is_participant = $stmt->fetch() ? true : false;

// Eğer kullanıcı toplantıyı oluşturan veya katılımcı değilse
if ($meeting['created_by'] != $_SESSION['user_id'] && !$is_participant) {
    $_SESSION['error'] = "Bu toplantıya not ekleme yetkiniz yok.";
    header("Location: index.php");
    exit;
}

// Tablonun varlığını kontrol et
try {
    $stmt = $db->query("SHOW TABLES LIKE 'meeting_notes'");
    $table_exists = $stmt->rowCount() > 0;

    if (!$table_exists) {
        // Tablo yoksa oluştur
        $db->exec("
            CREATE TABLE `meeting_notes` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `meeting_id` int(11) NOT NULL,
              `created_by` int(11) NOT NULL,
              `content` text NOT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `meeting_id` (`meeting_id`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        $_SESSION['success'] = "meeting_notes tablosu oluşturuldu ve ";
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Veritabanı hatası: " . $e->getMessage();
    header("Location: $redirect");
    exit;
}

// Not ekle
try {
    $stmt = $db->prepare("
        INSERT INTO meeting_notes (meeting_id, created_by, content, created_at) 
        VALUES (:meeting_id, :created_by, :content, NOW())
    ");
    
    $stmt->execute([
        'meeting_id' => $meeting_id,
        'created_by' => $_SESSION['user_id'],
        'content' => $notes
    ]);
    
    $_SESSION['success'] = isset($_SESSION['success']) ? 
        $_SESSION['success'] . "not başarıyla eklendi." : 
        "Not başarıyla eklendi.";
} catch (PDOException $e) {
    $_SESSION['error'] = "Not eklenirken bir hata oluştu: " . $e->getMessage();
}

// Yönlendirme
header("Location: $redirect");
exit;
?>
<?php
// meetings/delete_meeting.php - Toplantı silme

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

// Kullanıcının toplantıyı silme yetkisi var mı? (sadece oluşturan silebilir)
if ($meeting['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = "Bu toplantıyı silme yetkiniz yok.";
    header("Location: view.php?id=$meeting_id");
    exit;
}

// İşleme başla (transaction kullan)
try {
    $db->beginTransaction();
    
    // Katılımcıları sil
    $stmt = $db->prepare("DELETE FROM meeting_participants WHERE meeting_id = :meeting_id");
    $stmt->execute(['meeting_id' => $meeting_id]);
    
    // Toplantı notlarını sil (tablo varsa)
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'meeting_notes'");
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("DELETE FROM meeting_notes WHERE meeting_id = :meeting_id");
            $stmt->execute(['meeting_id' => $meeting_id]);
        }
    } catch (Exception $e) {
        // Tablo yoksa hata oluşabilir, görmezden gel
    }
    
    // Toplantıyı sil
    $stmt = $db->prepare("DELETE FROM meetings WHERE id = :id");
    $stmt->execute(['id' => $meeting_id]);
    
    // İşlemi tamamla
    $db->commit();
    
    $_SESSION['success'] = "Toplantı ve ilgili tüm veriler başarıyla silindi.";
} catch (Exception $e) {
    // Hata durumunda geri al
    $db->rollBack();
    $_SESSION['error'] = "Toplantı silinirken bir hata oluştu: " . $e->getMessage();
}

// Toplantı listesine yönlendir
header("Location: index.php");
exit;
?>
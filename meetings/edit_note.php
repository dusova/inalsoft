<?php
// meetings/edit_note.php - Toplantı notu düzenleme

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
if (!isset($_POST['note_id']) || !is_numeric($_POST['note_id'])) {
    $_SESSION['error'] = "Geçersiz not ID'si.";
    header("Location: index.php");
    exit;
}

$note_id = intval($_POST['note_id']);
$note_content = isset($_POST['note_content']) ? trim($_POST['note_content']) : '';
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "index.php";

// Not içeriği boş mu kontrol et
if (empty($note_content)) {
    $_SESSION['error'] = "Not içeriği boş olamaz.";
    header("Location: $redirect");
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

// Notu al ve kullanıcının yetkisini kontrol et
try {
    $stmt = $db->prepare("
        SELECT n.*, m.id as meeting_id
        FROM meeting_notes n
        JOIN meetings m ON n.meeting_id = m.id
        WHERE n.id = :note_id
    ");
    
    $stmt->execute(['note_id' => $note_id]);
    $note = $stmt->fetch();
    
    if (!$note) {
        $_SESSION['error'] = "Not bulunamadı.";
        header("Location: $redirect");
        exit;
    }
    
    // Kullanıcı bu notu düzenleme yetkisine sahip mi?
    // (Notu oluşturan veya toplantı sahibi)
    if ($note['created_by'] != $_SESSION['user_id']) {
        // Toplantı sahibi mi kontrol et
        $stmt = $db->prepare("SELECT created_by FROM meetings WHERE id = :meeting_id");
        $stmt->execute(['meeting_id' => $note['meeting_id']]);
        $meeting = $stmt->fetch();
        
        if (!$meeting || $meeting['created_by'] != $_SESSION['user_id']) {
            $_SESSION['error'] = "Bu notu düzenleme yetkiniz bulunmamaktadır.";
            header("Location: $redirect");
            exit;
        }
    }
    
    // Notu güncelle
    $stmt = $db->prepare("
        UPDATE meeting_notes 
        SET content = :content, updated_at = NOW() 
        WHERE id = :note_id
    ");
    
    $stmt->execute([
        'content' => $note_content,
        'note_id' => $note_id
    ]);
    
    $_SESSION['success'] = "Not başarıyla güncellendi.";
    
} catch (Exception $e) {
    $_SESSION['error'] = "Not güncellenirken bir hata oluştu: " . $e->getMessage();
}

// Yönlendirme
header("Location: $redirect");
exit;
?>
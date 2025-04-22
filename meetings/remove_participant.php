<?php
// meetings/remove_participant.php - Toplantıdan katılımcı çıkarma

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
if (!isset($_POST['participant_id']) || !is_numeric($_POST['participant_id'])) {
    $_SESSION['error'] = "Geçersiz katılımcı bilgisi.";
    header("Location: index.php");
    exit;
}

if (!isset($_POST['meeting_id']) || !is_numeric($_POST['meeting_id'])) {
    $_SESSION['error'] = "Geçersiz toplantı bilgisi.";
    header("Location: index.php");
    exit;
}

$participant_id = intval($_POST['participant_id']);
$meeting_id = intval($_POST['meeting_id']);
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "view.php?id=$meeting_id";

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

// Toplantı geçmiş mi kontrol et
if (strtotime($meeting['meeting_date']) < time()) {
    $_SESSION['error'] = "Geçmiş toplantılardan katılımcı çıkaramazsınız.";
    header("Location: $redirect");
    exit;
}

// Kullanıcının toplantıyı düzenleme yetkisi var mı?
if ($meeting['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = "Bu toplantıdan katılımcı çıkarma yetkiniz yok.";
    header("Location: $redirect");
    exit;
}

// Katılımcının toplantı sahibi olmadığından emin ol
$stmt = $db->prepare("SELECT user_id FROM meeting_participants WHERE user_id = :user_id AND meeting_id = :meeting_id");
$stmt->execute([
    'user_id' => $meeting['created_by'],
    'meeting_id' => $meeting_id
]);

$is_owner = false;
$participant_row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($participant_row && $participant_row['user_id'] == $meeting['created_by']) {
    $is_owner = true;
}

// Katılımcıyı al
$stmt = $db->prepare("SELECT mp.*, u.full_name 
                       FROM meeting_participants mp
                       JOIN users u ON mp.user_id = u.id
                       WHERE mp.user_id = :user_id AND mp.meeting_id = :meeting_id");
$stmt->execute([
    'user_id' => $participant_id,
    'meeting_id' => $meeting_id
]);

$participant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    $_SESSION['error'] = "Belirtilen katılımcı bulunamadı.";
    header("Location: $redirect");
    exit;
}

// Toplantı sahibi kendi kendini çıkaramaz
if ($participant['user_id'] == $meeting['created_by']) {
    $_SESSION['error'] = "Toplantı sahibi toplantıdan çıkarılamaz.";
    header("Location: $redirect");
    exit;
}

// Katılımcıyı sil
try {
    $stmt = $db->prepare("DELETE FROM meeting_participants 
                           WHERE user_id = :user_id AND meeting_id = :meeting_id");
    $stmt->execute([
        'user_id' => $participant_id,
        'meeting_id' => $meeting_id
    ]);
    
    if ($stmt->rowCount() > 0) {
        $_SESSION['success'] = $participant['full_name'] . " toplantıdan başarıyla çıkarıldı.";
    } else {
        $_SESSION['error'] = "Katılımcı çıkarılırken bir hata oluştu.";
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Katılımcı çıkarılırken bir hata oluştu: " . $e->getMessage();
}

// Yönlendirme
header("Location: $redirect");
exit;
?>
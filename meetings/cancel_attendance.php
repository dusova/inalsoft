<?php
// meetings/cancel_attendance.php - Katılımcının kendi katılımını iptal etmesi

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
$user_id = $_SESSION['user_id']; // Kendi katılımını iptal eden kullanıcı
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "index.php";

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
    $_SESSION['error'] = "Geçmiş toplantılar için katılım iptal edilemez.";
    header("Location: $redirect");
    exit;
}

// Kullanıcı toplantının sahibi mi? (Sahibi katılımı iptal edemez)
if ($meeting['created_by'] == $user_id) {
    $_SESSION['error'] = "Toplantı sahibi olarak katılımınızı iptal edemezsiniz.";
    header("Location: $redirect");
    exit;
}

// Kullanıcı gerçekten katılımcı mı?
$stmt = $db->prepare("SELECT * FROM meeting_participants WHERE meeting_id = :meeting_id AND user_id = :user_id");
$stmt->execute([
    'meeting_id' => $meeting_id,
    'user_id' => $user_id
]);

if (!$stmt->fetch()) {
    $_SESSION['error'] = "Bu toplantıya zaten kayıtlı değilsiniz.";
    header("Location: $redirect");
    exit;
}

// Katılımı iptal et
try {
    $stmt = $db->prepare("DELETE FROM meeting_participants WHERE meeting_id = :meeting_id AND user_id = :user_id");
    $stmt->execute([
        'meeting_id' => $meeting_id,
        'user_id' => $user_id
    ]);
    
    if ($stmt->rowCount() > 0) {
        $_SESSION['success'] = "Toplantı katılımınız başarıyla iptal edildi.";
    } else {
        $_SESSION['error'] = "Katılım iptal edilirken bir hata oluştu.";
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Katılım iptal edilirken bir hata oluştu: " . $e->getMessage();
}

// Yönlendirme
header("Location: $redirect");
exit;
?>
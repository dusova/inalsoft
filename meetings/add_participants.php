<?php
// meetings/add_participants.php - Toplantıya katılımcı ekleme

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
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "view.php?id=$meeting_id";

// Katılımcı seçilmiş mi kontrol et
if (!isset($_POST['participants']) || !is_array($_POST['participants']) || empty($_POST['participants'])) {
    $_SESSION['error'] = "Lütfen en az bir katılımcı seçin.";
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

// Toplantı geçmiş mi kontrol et
if (strtotime($meeting['meeting_date']) < time()) {
    $_SESSION['error'] = "Geçmiş toplantılara katılımcı eklenemez.";
    header("Location: $redirect");
    exit;
}

// Kullanıcının toplantıyı düzenleme yetkisi var mı?
if ($meeting['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = "Bu toplantıya katılımcı ekleme yetkiniz yok.";
    header("Location: view.php?id=$meeting_id");
    exit;
}

// Mevcut katılımcıları al
$existing_participants = [];
try {
    $stmt = $db->prepare("SELECT user_id FROM meeting_participants WHERE meeting_id = :meeting_id");
    $stmt->execute(['meeting_id' => $meeting_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_participants[] = $row['user_id'];
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Mevcut katılımcılar alınırken hata: " . $e->getMessage();
    header("Location: $redirect");
    exit;
}

// Katılımcıları ekle
$success_count = 0;
$error_count = 0;

foreach ($_POST['participants'] as $user_id) {
    // Kullanıcı ID'si geçerli mi kontrol et
    if (!is_numeric($user_id)) {
        $error_count++;
        continue;
    }
    
    $user_id = intval($user_id);
    
    // Kullanıcı zaten katılımcı mı kontrol et
    if (in_array($user_id, $existing_participants)) {
        // Zaten eklenmiş, atlayalım
        continue;
    }
    
    // Kullanıcı gerçekten var mı kontrol et
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        if (!$stmt->fetch()) {
            $error_count++;
            continue;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Kullanıcı kontrolü sırasında hata: " . $e->getMessage();
        header("Location: $redirect");
        exit;
    }
    
    // Katılımcıyı ekle
    try {
        // meeting_participants tablosunun yapısını kontrol edelim
        $table_info = $db->query("DESCRIBE meeting_participants");
        $columns = [];
        while ($row = $table_info->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        
        // Temel alanlar için sorgu oluşturalım
        $sql = "INSERT INTO meeting_participants (meeting_id, user_id";
        $params = [
            'meeting_id' => $meeting_id,
            'user_id' => $user_id
        ];
        
        // Eğer created_by alanı varsa ekleyelim
        if (in_array('created_by', $columns)) {
            $sql .= ", created_by";
            $params['created_by'] = $_SESSION['user_id'];
        }
        
        // Eğer created_at alanı varsa ekleyelim
        if (in_array('created_at', $columns)) {
            $sql .= ", created_at";
            $params['created_at'] = date('Y-m-d H:i:s');
        }
        
        $sql .= ") VALUES (:meeting_id, :user_id";
        
        // created_by parametresi var mı?
        if (in_array('created_by', $columns)) {
            $sql .= ", :created_by";
        }
        
        // created_at parametresi var mı?
        if (in_array('created_at', $columns)) {
            $sql .= ", :created_at";
        }
        
        $sql .= ")";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $success_count++;
    } catch (Exception $e) {
        $error_count++;
        $_SESSION['error'] = "Katılımcı eklenirken hata: " . $e->getMessage();
        header("Location: $redirect");
        exit;
    }
}

// Sonuç mesajı
if ($success_count > 0) {
    $_SESSION['success'] = "$success_count katılımcı toplantıya başarıyla eklendi.";
} elseif ($error_count > 0) {
    $_SESSION['error'] = "Katılımcı eklenirken bir hata oluştu.";
} else {
    $_SESSION['info'] = "Eklenecek yeni katılımcı bulunamadı.";
}

// Yönlendirme
header("Location: $redirect");
exit;
?>
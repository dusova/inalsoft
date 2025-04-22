<?php
// meetings/create_meeting.php - Yeni toplantı oluşturma işlemi

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
$title = sanitize_input($_POST['title']);
$description = sanitize_input($_POST['description'] ?? '');
$meeting_date = sanitize_input($_POST['meeting_date']);
$meeting_time = sanitize_input($_POST['meeting_time']);
$duration = intval($_POST['duration']);
$location = sanitize_input($_POST['location'] ?? '');
$meeting_link = sanitize_input($_POST['meeting_link'] ?? '');
$agenda = sanitize_input($_POST['agenda'] ?? '');
$participants = isset($_POST['participants']) ? $_POST['participants'] : [];

// Zorunlu alanları kontrol et
if (empty($title) || empty($meeting_date) || empty($meeting_time) || empty($duration)) {
    $_SESSION['error'] = "Lütfen tüm zorunlu alanları doldurun.";
    header("Location: index.php");
    exit;
}

// Tarih formatını kontrol et
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $meeting_date) || !preg_match("/^\d{2}:\d{2}$/", $meeting_time)) {
    $_SESSION['error'] = "Geçersiz tarih veya saat formatı.";
    header("Location: index.php");
    exit;
}

// Tarih ve saati birleştir
$meeting_datetime = $meeting_date . ' ' . $meeting_time . ':00';

// Geçmiş tarihleri kabul etme
if (strtotime($meeting_datetime) < time()) {
    $_SESSION['error'] = "Geçmiş bir tarih için toplantı oluşturamazsınız.";
    header("Location: index.php");
    exit;
}

// Süre kontrolü
if ($duration < 15 || $duration > 480) {
    $_SESSION['error'] = "Toplantı süresi 15 dakika ile 8 saat arasında olmalıdır.";
    header("Location: index.php");
    exit;
}

// Veritabanı bağlantısı
$db = connect_db();

try {
    // İşlem başlat
    $db->beginTransaction();
    
    // Toplantıyı ekle
    $stmt = $db->prepare("
        INSERT INTO meetings (title, description, meeting_date, duration, location, meeting_link, agenda, created_by, created_at) 
        VALUES (:title, :description, :meeting_date, :duration, :location, :meeting_link, :agenda, :created_by, NOW())
    ");
    
    $result = $stmt->execute([
        'title' => $title,
        'description' => $description,
        'meeting_date' => $meeting_datetime,
        'duration' => $duration,
        'location' => $location,
        'meeting_link' => $meeting_link,
        'agenda' => $agenda,
        'created_by' => $_SESSION['user_id']
    ]);
    
    if ($result) {
        $meeting_id = $db->lastInsertId();
        
        // Katılımcıları ekle
        if (!empty($participants)) {
            $values = [];
            $params = [];
            
            foreach ($participants as $participant_id) {
                if (is_numeric($participant_id)) {
                    $values[] = "(:meeting_id, :participant_" . $participant_id . ")";
                    $params['meeting_id'] = $meeting_id;
                    $params['participant_' . $participant_id] = $participant_id;
                }
            }
            
            if (!empty($values)) {
                $sql = "INSERT INTO meeting_participants (meeting_id, user_id) VALUES " . implode(', ', $values);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
        }
        
        // Toplantı oluşturucusunu otomatik olarak katılımcı olarak ekle (eğer listede yoksa)
        if (!in_array($_SESSION['user_id'], $participants)) {
            $stmt = $db->prepare("INSERT INTO meeting_participants (meeting_id, user_id, status) VALUES (:meeting_id, :user_id, 'accepted')");
            $stmt->execute([
                'meeting_id' => $meeting_id,
                'user_id' => $_SESSION['user_id']
            ]);
        }
        
        // Takvim olayı oluştur
        $end_datetime = date('Y-m-d H:i:s', strtotime($meeting_datetime) + ($duration * 60));
        
        $stmt = $db->prepare("
            INSERT INTO calendar_events (title, description, start_datetime, end_datetime, all_day, location, event_type, related_id, related_type, created_by, created_at)
            VALUES (:title, :description, :start_datetime, :end_datetime, :all_day, :location, :event_type, :related_id, :related_type, :created_by, NOW())
        ");
        
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'start_datetime' => $meeting_datetime,
            'end_datetime' => $end_datetime,
            'all_day' => 0,
            'location' => $location,
            'event_type' => 'meeting',
            'related_id' => $meeting_id,
            'related_type' => 'meeting',
            'created_by' => $_SESSION['user_id']
        ]);
        
        // Aktiviteyi logla
        log_activity($_SESSION['user_id'], 'create', 'meeting', $meeting_id, "Yeni toplantı oluşturuldu: $title");
        
        // Katılımcılara bildirim gönder
        if (!empty($participants)) {
            foreach ($participants as $participant_id) {
                if ($participant_id != $_SESSION['user_id']) { // Kendine bildirim gönderme
                    create_notification(
                        $participant_id,
                        "Yeni Toplantı Daveti",
                        "{$_SESSION['full_name']} sizi '$title' başlıklı toplantıya davet etti.",
                        'meeting',
                        $meeting_id
                    );
                }
            }
        }
        
        // İşlemi tamamla
        $db->commit();
        
        // Başarılı mesajı
        $_SESSION['success'] = "Toplantı başarıyla oluşturuldu.";
        header("Location: view.php?id=$meeting_id");
        exit;
    } else {
        throw new Exception("Toplantı oluşturulurken bir hata oluştu.");
    }
} catch (Exception $e) {
    // Hata durumunda işlemi geri al
    $db->rollBack();
    
    $_SESSION['error'] = "Toplantı oluşturulurken bir hata oluştu: " . $e->getMessage();
    header("Location: index.php");
    exit;
}
?>

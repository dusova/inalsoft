<?php
// api/notification_stream.php - SSE (Server-Sent Events) stream

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Oturumu başlat
session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmamışsa çık
if (!isset($_SESSION['user_id'])) {
    echo "event: error\n";
    echo "data: {\"message\": \"Oturum bulunamadı\"}\n\n";
    exit;
}

// Kullanıcı ID'sini al
$user_id = $_SESSION['user_id'];

// Kullanıcının bildirim tercihlerini al
$db = connect_db();
$stmt = $db->prepare("SELECT notification_preference FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$notification_pref = $stmt->fetchColumn();
$notification_settings = $notification_pref ? json_decode($notification_pref, true) : [
    'browser_notifications' => true,
];

// Son gönderilen bildirim ID'sini kontrol et
$last_notification_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

// Her 5 saniyede bir yeni bildirimleri kontrol et
while (true) {
    // Oturumu tazele (uzun süreli bağlantılarda oturumun kapanmasını önler)
    session_write_close();
    session_start();
    
    // Kullanıcı hala giriş yapmış mı kontrol et
    if (!isset($_SESSION['user_id'])) {
        echo "event: error\n";
        echo "data: {\"message\": \"Oturum sonlandırıldı\"}\n\n";
        exit;
    }
    
    // Yeni bildirimleri kontrol et
    $stmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = :user_id AND is_read = 0
        ORDER BY created_at DESC
    ");
    $stmt->execute(['user_id' => $user_id]);
    $notifications = $stmt->fetchAll();
    
    // Bildirim sayısı
    $count = count($notifications);
    
    // En son 5 bildirimi al
    $recent_notifications = array_slice($notifications, 0, 5);
    
    // En yeni bildirim ID'sini bul
    $newest_id = 0;
    if (!empty($notifications)) {
        $newest_id = $notifications[0]['id'];
    }
    
    // Yeni bildirim varsa bilgilerini gönder
    if ($newest_id > $last_notification_id && $newest_id > 0) {
        $browser_enabled = isset($notification_settings['browser_notifications']) ? 
                          $notification_settings['browser_notifications'] : true;
        
        // Bildirim verilerini gönder
        $data = [
            'count' => $count,
            'notifications' => $recent_notifications,
            'browser_enabled' => $browser_enabled,
            'title' => $notifications[0]['title'],
            'message' => $notifications[0]['message']
        ];
        
        echo "event: notification\n";
        echo "data: " . json_encode($data) . "\n\n";
        
        // Son bildirim ID'sini güncelle
        $last_notification_id = $newest_id;
        
        // Çıktı tamponunu temizle ve hemen gönder
        ob_flush();
        flush();
    } else {
        // Yeni bildirim yoksa sadece kalp atışı gönder
        echo ": heartbeat " . time() . "\n\n";
        ob_flush();
        flush();
    }
    
    // Bağlantıyı kopar
    if (connection_aborted()) {
        exit;
    }
    
    // 5 saniye bekle
    sleep(5);
}
?>
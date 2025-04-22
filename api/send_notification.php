<?php
// api/send_notification.php - Bildirim gönderme API fonksiyonları

/**
 * Tek bir kullanıcıya bildirim gönder
 * 
 * @param int $user_id Kullanıcı ID
 * @param string $title Bildirim başlığı
 * @param string $message Bildirim metni
 * @param string $type Bildirim türü (project, task, meeting, system)
 * @param int $related_id İlişkili öğe ID
 * @return int|bool Bildirim ID veya başarısızsa false
 */
function send_notification($user_id, $title, $message, $type = 'system', $related_id = null) {
    return create_notification($user_id, $title, $message, $type, $related_id);
}

/**
 * Birden fazla kullanıcıya bildirim gönder
 * 
 * @param array $user_ids Kullanıcı ID'leri dizisi
 * @param string $title Bildirim başlığı
 * @param string $message Bildirim metni
 * @param string $type Bildirim türü (project, task, meeting, system)
 * @param int $related_id İlişkili öğe ID
 * @return array Bildirim ID'leri dizisi
 */
function send_notification_to_multiple_users($user_ids, $title, $message, $type = 'system', $related_id = null) {
    $notification_ids = [];
    
    foreach ($user_ids as $user_id) {
        $notification_id = create_notification($user_id, $title, $message, $type, $related_id);
        if ($notification_id) {
            $notification_ids[] = $notification_id;
        }
    }
    
    return $notification_ids;
}

/**
 * Belirli bir projeye katılan tüm kullanıcılara bildirim gönder
 * 
 * @param int $project_id Proje ID
 * @param string $title Bildirim başlığı
 * @param string $message Bildirim metni
 * @param string $type Bildirim türü (project, task, meeting, system)
 * @param int $related_id İlişkili öğe ID
 * @return array Bildirim ID'leri dizisi
 */
function send_notification_to_project_members($project_id, $title, $message, $type = 'project', $related_id = null) {
    $db = connect_db();
    
    // Projede görev alan kullanıcıları al
    $stmt = $db->prepare("
        SELECT DISTINCT u.id
        FROM users u
        JOIN tasks t ON u.id = t.assigned_to
        WHERE t.project_id = :project_id
        UNION
        SELECT u.id
        FROM users u
        JOIN projects p ON u.id = p.created_by
        WHERE p.id = :project_id
    ");
    $stmt->execute(['project_id' => $project_id]);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    return send_notification_to_multiple_users($users, $title, $message, $type, $related_id ?: $project_id);
}

/**
 * Belirli bir toplantıdaki tüm katılımcılara bildirim gönder
 * 
 * @param int $meeting_id Toplantı ID
 * @param string $title Bildirim başlığı
 * @param string $message Bildirim metni
 * @param string $type Bildirim türü (project, task, meeting, system)
 * @return array Bildirim ID'leri dizisi
 */
function send_notification_to_meeting_participants($meeting_id, $title, $message, $type = 'meeting') {
    $db = connect_db();
    
    // Toplantı katılımcılarını al
    $stmt = $db->prepare("
        SELECT user_id
        FROM meeting_participants
        WHERE meeting_id = :meeting_id
        UNION
        SELECT created_by
        FROM meetings
        WHERE id = :meeting_id
    ");
    $stmt->execute(['meeting_id' => $meeting_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    return send_notification_to_multiple_users($participants, $title, $message, $type, $meeting_id);
}

/**
 * Tüm kullanıcılara sistem bildirimi gönder
 * 
 * @param string $title Bildirim başlığı
 * @param string $message Bildirim metni
 * @return array Bildirim ID'leri dizisi
 */
function send_notification_to_all_users($title, $message) {
    $db = connect_db();
    
    // Tüm kullanıcı ID'lerini al
    $stmt = $db->prepare("SELECT id FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    return send_notification_to_multiple_users($users, $title, $message, 'system');
}
?>


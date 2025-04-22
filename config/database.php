<?php
// config/database.php - Veritabanı Bağlantı Ayarları

define('DB_HOST', 'localhost');
define('DB_USER', 'ina1a7ftcom_mdusova');
define('DB_PASS', 'Mstarda1337$#£'); // Güvenli bir parola ile değiştirilmeli
define('DB_NAME', 'ina1a7ftcom_inalsoft_pm');

// PDO bağlantısı oluşturma
function connect_db() {
    try {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Hata durumunda
        die("Bağlantı hatası: " . $e->getMessage());
    }
}

// Bildirim oluşturma fonksiyonu
function create_notification($user_id, $title, $message, $type, $related_id = null) {
    $db = connect_db();
    
    $sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
            VALUES (:user_id, :title, :message, :type, :related_id)";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'user_id' => $user_id,
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'related_id' => $related_id
    ]);
    
    return $db->lastInsertId();
}

// Aktivite log fonksiyonu
function log_activity($user_id, $action, $entity_type, $entity_id, $description = null) {
    $db = connect_db();
    
    $sql = "INSERT INTO activities (user_id, action, entity_type, entity_id, description) 
            VALUES (:user_id, :action, :entity_type, :entity_id, :description)";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'user_id' => $user_id,
        'action' => $action,
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'description' => $description
    ]);
    
    return $db->lastInsertId();
}

// Güvenli form girişleri için doğrulama
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Doğrulanmış kullanıcı kimliğini al
function get_authenticated_user() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $db = connect_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Hassas bilgileri kaldır
        unset($user['password']);
        return $user;
    }
    
    return false;
}

// Tarih formatını ayarlama
function format_date($date, $format = 'd.m.Y H:i') {
    $datetime = new DateTime($date);
    return $datetime->format($format);
}

// Kullanıcı temasını al
function get_user_theme() {
    $user = get_authenticated_user();
    return $user && isset($user['theme_preference']) ? $user['theme_preference'] : 'light';
}

// CSRF token oluşturma ve doğrulama
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

// API yanıtlarını JSON formatında döndürme
function json_response($data, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}
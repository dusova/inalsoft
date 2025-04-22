<?php
// dashboard_filters.php - Dashboard filtreleme API

session_start();
require_once 'config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

// AJAX isteği mi kontrol et
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    header("Location: dashboard.php");
    exit;
}

// JSON yanıtı için header ayarla
header('Content-Type: application/json');

// Filtre parametrelerini al
$filter_type = isset($_GET['filter_type']) ? sanitize_input($_GET['filter_type']) : 'all';
$date_range = isset($_GET['date_range']) ? sanitize_input($_GET['date_range']) : 'this_week';

// Veritabanı bağlantısı
$db = connect_db();

// Tarih aralığını belirle
$date_from = '';
$date_to = '';

switch ($date_range) {
    case 'today':
        $date_from = date('Y-m-d 00:00:00');
        $date_to = date('Y-m-d 23:59:59');
        break;
    case 'yesterday':
        $date_from = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $date_to = date('Y-m-d 23:59:59', strtotime('-1 day'));
        break;
    case 'this_week':
        $date_from = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $date_to = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        break;
    case 'last_week':
        $date_from = date('Y-m-d 00:00:00', strtotime('monday last week'));
        $date_to = date('Y-m-d 23:59:59', strtotime('sunday last week'));
        break;
    case 'this_month':
        $date_from = date('Y-m-01 00:00:00');
        $date_to = date('Y-m-t 23:59:59');
        break;
    case 'last_month':
        $date_from = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $date_to = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        break;
    case 'custom':
        if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
            $date_from = sanitize_input($_GET['date_from']) . ' 00:00:00';
            $date_to = sanitize_input($_GET['date_to']) . ' 23:59:59';
        } else {
            echo json_encode(['success' => false, 'message' => 'Özel tarih aralığı için başlangıç ve bitiş tarihleri gereklidir.']);
            exit;
        }
        break;
    default:
        $date_from = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $date_to = date('Y-m-d 23:59:59', strtotime('sunday this week'));
}

// Veri türüne göre sorguları hazırla
try {
    $data = [];
    
    // Aktiviteler
    if ($filter_type == 'all' || $filter_type == 'activities') {
        $stmt = $db->prepare("
            SELECT a.*, u.full_name 
            FROM activities a 
            JOIN users u ON a.user_id = u.id 
            WHERE a.created_at BETWEEN :date_from AND :date_to
            ORDER BY a.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
        $data['activities'] = $stmt->fetchAll();
    }
    
    // Aktif projeler
    if ($filter_type == 'all' || $filter_type == 'projects') {
        $stmt = $db->prepare("
            SELECT * FROM projects 
            WHERE status = 'in_progress' 
            AND (created_at BETWEEN :date_from AND :date_to OR updated_at BETWEEN :date_from AND :date_to)
            ORDER BY due_date ASC
        ");
        $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
        $data['active_projects'] = $stmt->fetchAll();
    }
    
    // Yaklaşan toplantılar
    if ($filter_type == 'all' || $filter_type == 'meetings') {
        $stmt = $db->prepare("
            SELECT * FROM meetings 
            WHERE meeting_date >= NOW() 
            AND meeting_date <= :date_to
            ORDER BY meeting_date ASC 
            LIMIT 5
        ");
        $stmt->execute(['date_to' => $date_to]);
        $data['upcoming_meetings'] = $stmt->fetchAll();
    }
    
    // Bugünkü takvim etkinlikleri
    if ($filter_type == 'all' || $filter_type == 'events') {
        $stmt = $db->prepare("
            SELECT * FROM calendar_events 
            WHERE (DATE(start_datetime) BETWEEN DATE(:date_from) AND DATE(:date_to))
            ORDER BY start_datetime ASC
        ");
        $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
        $data['events'] = $stmt->fetchAll();
    }
    
    // Özet istatistikler
    $data['summary'] = [
        'date_range' => [
            'from' => date('d.m.Y', strtotime($date_from)),
            'to' => date('d.m.Y', strtotime($date_to))
        ]
    ];
    
    // Proje sayısı
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM projects 
        WHERE created_at BETWEEN :date_from AND :date_to
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['summary']['new_projects'] = $stmt->fetchColumn();
    
    // Tamamlanan proje sayısı
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM projects 
        WHERE status = 'completed' AND completed_at BETWEEN :date_from AND :date_to
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['summary']['completed_projects'] = $stmt->fetchColumn();
    
    // Toplantı sayısı
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM meetings 
        WHERE meeting_date BETWEEN :date_from AND :date_to
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['summary']['meetings'] = $stmt->fetchColumn();
    
    // Etkinlik sayısı
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM calendar_events 
        WHERE start_datetime BETWEEN :date_from AND :date_to
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['summary']['events'] = $stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
}
?>


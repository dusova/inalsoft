<?php
// export_dashboard.php - Dashboard verilerini dışa aktarma işlemi

session_start();
require_once 'config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

// Parametre kontrolü
$export_type = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'pdf';
$filter_type = isset($_GET['filter_type']) ? sanitize_input($_GET['filter_type']) : 'all';
$date_range = isset($_GET['date_range']) ? sanitize_input($_GET['date_range']) : 'this_week';

// Tarih aralığını belirle
$date_from = '';
$date_to = '';

switch ($date_range) {
    case 'today':
        $date_from = date('Y-m-d 00:00:00');
        $date_to = date('Y-m-d 23:59:59');
        $date_range_text = 'Bugün';
        break;
    case 'yesterday':
        $date_from = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $date_to = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $date_range_text = 'Dün';
        break;
    case 'this_week':
        $date_from = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $date_to = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        $date_range_text = 'Bu Hafta';
        break;
    case 'last_week':
        $date_from = date('Y-m-d 00:00:00', strtotime('monday last week'));
        $date_to = date('Y-m-d 23:59:59', strtotime('sunday last week'));
        $date_range_text = 'Geçen Hafta';
        break;
    case 'this_month':
        $date_from = date('Y-m-01 00:00:00');
        $date_to = date('Y-m-t 23:59:59');
        $date_range_text = 'Bu Ay';
        break;
    case 'last_month':
        $date_from = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $date_to = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        $date_range_text = 'Geçen Ay';
        break;
    case 'custom':
        if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
            $date_from = sanitize_input($_GET['date_from']) . ' 00:00:00';
            $date_to = sanitize_input($_GET['date_to']) . ' 23:59:59';
            $date_range_text = date('d.m.Y', strtotime($_GET['date_from'])) . ' - ' . date('d.m.Y', strtotime($_GET['date_to']));
        } else {
            $_SESSION['error'] = "Özel tarih aralığı için başlangıç ve bitiş tarihleri gereklidir.";
            header("Location: dashboard.php");
            exit;
        }
        break;
    default:
        $date_from = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $date_to = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        $date_range_text = 'Bu Hafta';
}

// Veritabanı bağlantısı
$db = connect_db();

// Verileri al
try {
    $data = [];
    
    // Aktif projeler
    $stmt = $db->prepare("
        SELECT * FROM projects 
        WHERE (created_at BETWEEN :date_from AND :date_to OR updated_at BETWEEN :date_from AND :date_to)
        ORDER BY status, due_date ASC
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['projects'] = $stmt->fetchAll();
    
    // Toplantılar
    $stmt = $db->prepare("
        SELECT * FROM meetings 
        WHERE meeting_date BETWEEN :date_from AND :date_to
        ORDER BY meeting_date ASC
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['meetings'] = $stmt->fetchAll();
    
    // Etkinlikler
    $stmt = $db->prepare("
        SELECT * FROM calendar_events 
        WHERE start_datetime BETWEEN :date_from AND :date_to
        ORDER BY start_datetime ASC
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['events'] = $stmt->fetchAll();
    
    // Aktiviteler
    $stmt = $db->prepare("
        SELECT a.*, u.full_name 
        FROM activities a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.created_at BETWEEN :date_from AND :date_to
        ORDER BY a.created_at DESC
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['activities'] = $stmt->fetchAll();
    
    // Özet istatistikler
    $data['summary'] = [
        'date_range' => [
            'from' => date('d.m.Y', strtotime($date_from)),
            'to' => date('d.m.Y', strtotime($date_to)),
            'text' => $date_range_text
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
    
    // Dışa aktarma işlemi
    switch ($export_type) {
        case 'pdf':
            export_to_pdf($data, $date_range_text);
            break;
        case 'excel':
            export_to_excel($data, $date_range_text);
            break;
        case 'csv':
            export_to_csv($data, $date_range_text);
            break;
        default:
            $_SESSION['error'] = "Desteklenmeyen dışa aktarma türü.";
            header("Location: dashboard.php");
            exit;
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Dışa aktarma sırasında bir hata oluştu: " . $e->getMessage();
    header("Location: dashboard.php");
    exit;
}

/**
 * PDF olarak dışa aktar
 */
function export_to_pdf($data, $date_range_text) {
    // PDF oluşturmak için mPDF veya TCPDF gibi kütüphaneleri kullanabilirsiniz
    // Bu örnek için basit bir HTML çıktısı oluşturup tarayıcıda görüntüleyelim
    
    // HTML çıktısı hazırla
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Dashboard Raporu - ' . $date_range_text . '</title>
        <style>
            body { font-family: Arial, sans-serif; }
            .header { text-align: center; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; }
            th { background-color: #f2f2f2; }
            .section-title { margin-top: 20px; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>inalsoft Proje Yönetim Sistemi</h1>
            <h2>Dashboard Raporu - ' . $date_range_text . '</h2>
            <p>Rapor Tarihi: ' . date('d.m.Y H:i') . '</p>
        </div>
        
        <h3 class="section-title">Özet Bilgiler</h3>
        <table>
            <tr>
                <th>Yeni Projeler</th>
                <th>Tamamlanan Projeler</th>
                <th>Toplantılar</th>
            </tr>
            <tr>
                <td>' . $data['summary']['new_projects'] . '</td>
                <td>' . $data['summary']['completed_projects'] . '</td>
                <td>' . $data['summary']['meetings'] . '</td>
            </tr>
        </table>';
    
    // Projeler
    if (!empty($data['projects'])) {
        $html .= '
        <h3 class="section-title">Projeler</h3>
        <table>
            <tr>
                <th>Proje Adı</th>
                <th>Durum</th>
                <th>Öncelik</th>
                <th>Başlangıç</th>
                <th>Bitiş</th>
            </tr>';
        
        foreach ($data['projects'] as $project) {
            $status = '';
            switch ($project['status']) {
                case 'planning': $status = 'Planlama'; break;
                case 'in_progress': $status = 'Devam Ediyor'; break;
                case 'review': $status = 'İnceleme'; break;
                case 'completed': $status = 'Tamamlandı'; break;
                default: $status = $project['status'];
            }
            
            $priority = '';
            switch ($project['priority']) {
                case 'low': $priority = 'Düşük'; break;
                case 'medium': $priority = 'Orta'; break;
                case 'high': $priority = 'Yüksek'; break;
                case 'urgent': $priority = 'Acil'; break;
                default: $priority = $project['priority'];
            }
            
            $html .= '
            <tr>
                <td>' . htmlspecialchars($project['name']) . '</td>
                <td>' . $status . '</td>
                <td>' . $priority . '</td>
                <td>' . date('d.m.Y', strtotime($project['start_date'])) . '</td>
                <td>' . date('d.m.Y', strtotime($project['due_date'])) . '</td>
            </tr>';
        }
        
        $html .= '</table>';
    }
    
    // Toplantılar
    if (!empty($data['meetings'])) {
        $html .= '
        <h3 class="section-title">Toplantılar</h3>
        <table>
            <tr>
                <th>Başlık</th>
                <th>Tarih ve Saat</th>
                <th>Süre</th>
                <th>Konum</th>
            </tr>';
        
        foreach ($data['meetings'] as $meeting) {
            $html .= '
            <tr>
                <td>' . htmlspecialchars($meeting['title']) . '</td>
                <td>' . date('d.m.Y H:i', strtotime($meeting['meeting_date'])) . '</td>
                <td>' . $meeting['duration'] . ' dakika</td>
                <td>' . htmlspecialchars($meeting['location']) . '</td>
            </tr>';
        }
        
        $html .= '</table>';
    }
    
    // Etkinlikler
    if (!empty($data['events'])) {
        $html .= '
        <h3 class="section-title">Takvim Etkinlikleri</h3>
        <table>
            <tr>
                <th>Başlık</th>
                <th>Tür</th>
                <th>Başlangıç</th>
                <th>Bitiş</th>
            </tr>';
        
        foreach ($data['events'] as $event) {
            $event_type = '';
            switch ($event['event_type']) {
                case 'meeting': $event_type = 'Toplantı'; break;
                case 'deadline': $event_type = 'Son Tarih'; break;
                case 'reminder': $event_type = 'Hatırlatıcı'; break;
                default: $event_type = 'Diğer';
            }
            
            $html .= '
            <tr>
                <td>' . htmlspecialchars($event['title']) . '</td>
                <td>' . $event_type . '</td>
                <td>' . date('d.m.Y H:i', strtotime($event['start_datetime'])) . '</td>
                <td>' . date('d.m.Y H:i', strtotime($event['end_datetime'])) . '</td>
            </tr>';
        }
        
        $html .= '</table>';
    }
    
    $html .= '
    </body>
    </html>';
    
    // Dosya adı oluştur
    $filename = 'dashboard_raporu_' . date('Y-m-d_H-i') . '.pdf';
    
    // PDF oluştur ve indir
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Burada mPDF veya TCPDF gibi bir kütüphane ile PDF oluşturulabilir
    // Bu örnek için basit HTML çıktısı veriyoruz
    echo $html;
    exit;
}

/**
 * Excel olarak dışa aktar
 */
function export_to_excel($data, $date_range_text) {
    // PHPSpreadsheet veya PHPExcel kütüphanesi kullanılabilir
    // Bu örnek için CSV formatında dışa aktaralım
    
    $filename = 'dashboard_raporu_' . date('Y-m-d_H-i') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Örnek CSV çıktısı verelim
    export_to_csv($data, $date_range_text);
    exit;
}

/**
 * CSV olarak dışa aktar
 */
function export_to_csv($data, $date_range_text) {
    $filename = 'dashboard_raporu_' . date('Y-m-d_H-i') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Başlık satırı
    fputcsv($output, ['inalsoft Proje Yönetim Sistemi - Dashboard Raporu - ' . $date_range_text]);
    fputcsv($output, ['Rapor Tarihi: ' . date('d.m.Y H:i')]);
    fputcsv($output, []);
    
    // Özet bilgiler
    fputcsv($output, ['Özet Bilgiler']);
    fputcsv($output, ['Yeni Projeler', 'Tamamlanan Projeler', 'Toplantılar']);
    fputcsv($output, [
        $data['summary']['new_projects'],
        $data['summary']['completed_projects'],
        $data['summary']['meetings']
    ]);
    fputcsv($output, []);
    
    // Projeler
    if (!empty($data['projects'])) {
        fputcsv($output, ['Projeler']);
        fputcsv($output, ['Proje Adı', 'Durum', 'Öncelik', 'Başlangıç', 'Bitiş']);
        
        foreach ($data['projects'] as $project) {
            $status = '';
            switch ($project['status']) {
                case 'planning': $status = 'Planlama'; break;
                case 'in_progress': $status = 'Devam Ediyor'; break;
                case 'review': $status = 'İnceleme'; break;
                case 'completed': $status = 'Tamamlandı'; break;
                default: $status = $project['status'];
            }
            
            $priority = '';
            switch ($project['priority']) {
                case 'low': $priority = 'Düşük'; break;
                case 'medium': $priority = 'Orta'; break;
                case 'high': $priority = 'Yüksek'; break;
                case 'urgent': $priority = 'Acil'; break;
                default: $priority = $project['priority'];
            }
            
            fputcsv($output, [
                $project['name'],
                $status,
                $priority,
                date('d.m.Y', strtotime($project['start_date'])),
                date('d.m.Y', strtotime($project['due_date']))
            ]);
        }
        
        fputcsv($output, []);
    }
    
    // Toplantılar
    if (!empty($data['meetings'])) {
        fputcsv($output, ['Toplantılar']);
        fputcsv($output, ['Başlık', 'Tarih ve Saat', 'Süre', 'Konum']);
        
        foreach ($data['meetings'] as $meeting) {
            fputcsv($output, [
                $meeting['title'],
                date('d.m.Y H:i', strtotime($meeting['meeting_date'])),
                $meeting['duration'] . ' dakika',
                $meeting['location']
            ]);
        }
        
        fputcsv($output, []);
    }
    
    // Etkinlikler
    if (!empty($data['events'])) {
        fputcsv($output, ['Takvim Etkinlikleri']);
        fputcsv($output, ['Başlık', 'Tür', 'Başlangıç', 'Bitiş']);
        
        foreach ($data['events'] as $event) {
            $event_type = '';
            switch ($event['event_type']) {
                case 'meeting': $event_type = 'Toplantı'; break;
                case 'deadline': $event_type = 'Son Tarih'; break;
                case 'reminder': $event_type = 'Hatırlatıcı'; break;
                default: $event_type = 'Diğer';
            }
            
            fputcsv($output, [
                $event['title'],
                $event_type,
                date('d.m.Y H:i', strtotime($event['start_datetime'])),
                date('d.m.Y H:i', strtotime($event['end_datetime']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}
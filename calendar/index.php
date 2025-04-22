<?php
// calendar/index.php - Takvim sayfası - Projeler eklendi

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Kullanıcı verisini al
$user = get_authenticated_user();
$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Şu anki ay ve yıl
$current_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$current_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Önceki ve sonraki ay/yıl hesaplama
$next_month = $current_month == 12 ? 1 : $current_month + 1;
$next_year = $current_month == 12 ? $current_year + 1 : $current_year;

$prev_month = $current_month == 1 ? 12 : $current_month - 1;
$prev_year = $current_month == 1 ? $current_year - 1 : $current_year;

// Ay başlangıç ve bitiş tarihleri
$start_date = sprintf('%04d-%02d-01', $current_year, $current_month);
$end_date = sprintf('%04d-%02d-%02d', $current_year, $current_month, date('t', strtotime($start_date)));

// Takvim olaylarını al
$stmt = $db->prepare("
    SELECT * FROM calendar_events 
    WHERE (start_datetime BETWEEN :start_date1 AND :end_date1) 
    OR (end_datetime BETWEEN :start_date2 AND :end_date2)
    ORDER BY start_datetime ASC
");
$stmt->execute([
    'start_date1' => $start_date . ' 00:00:00',
    'end_date1' => $end_date . ' 23:59:59',
    'start_date2' => $start_date . ' 00:00:00',
    'end_date2' => $end_date . ' 23:59:59'
]);
$events = $stmt->fetchAll();

// PROJELERİ TAKVİME EKLE
// Projeleri al (başlangıç ve bitiş tarihleri bu ay içinde olanlar)
$stmt = $db->prepare("
    SELECT 
        p.id, 
        p.name as title, 
        'project' as event_type, 
        p.start_date as start_datetime, 
        p.due_date as end_datetime, 
        p.description, 
        p.category,
        p.status,
        pc.name as category_name
    FROM projects p
    LEFT JOIN project_categories pc ON p.category = pc.id
    WHERE (p.start_date BETWEEN :start_date3 AND :end_date3) 
       OR (p.due_date BETWEEN :start_date4 AND :end_date4)
       OR (p.start_date <= :start_date5 AND p.due_date >= :end_date5)
    ORDER BY p.start_date ASC
");
$stmt->execute([
    'start_date3' => $start_date,
    'end_date3' => $end_date,
    'start_date4' => $start_date,
    'end_date4' => $end_date,
    'start_date5' => $start_date,
    'end_date5' => $end_date
]);
$projects = $stmt->fetchAll();

// Projeleri takvim olaylarına ekle
foreach ($projects as &$project) {
    // Tarih formatını düzenle (etkinliklerle uyumlu olması için)
    $project['start_datetime'] = $project['start_datetime'] . ' 00:00:00';
    $project['end_datetime'] = $project['end_datetime'] . ' 23:59:59';
    
    // Kategori adını ekle
    if (empty($project['category_name'])) {
        // ENUM değerine göre kategori adı ata
        switch ($project['category']) {
            case 'website':
                $project['category_name'] = 'Website';
                break;
            case 'social_media':
                $project['category_name'] = 'Sosyal Medya';
                break;
            case 'bionluk':
                $project['category_name'] = 'Bionluk';
                break;
            default:
                $project['category_name'] = 'Diğer';
                break;
        }
    }
    
    // Başlığı daha açıklayıcı hale getir
    $project['title'] = '[Proje] ' . $project['title'] . ' (' . $project['category_name'] . ')';
}

// Etkinlikler ve projeleri birleştir
$calendar_items = array_merge($events, $projects);

// Takvim oluşturma fonksiyonu
function build_calendar($month, $year, $calendar_items) {
    // Ay verisini oluştur
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $days_in_month = date('t', $first_day);
    $day_of_week = date('N', $first_day); // 1 (Pazartesi) - 7 (Pazar)
    
    // Takvim başlığı
    $month_name = date('F', $first_day);
    $month_name_tr = [
        'January' => 'Ocak',
        'February' => 'Şubat',
        'March' => 'Mart',
        'April' => 'Nisan',
        'May' => 'Mayıs',
        'June' => 'Haziran',
        'July' => 'Temmuz',
        'August' => 'Ağustos',
        'September' => 'Eylül',
        'October' => 'Ekim',
        'November' => 'Kasım',
        'December' => 'Aralık'
    ];
    
    // Günleri topla
    $day_names = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
    
    // Etkinlikleri günlere göre organize et
    $events_by_day = [];
    foreach ($calendar_items as $item) {
        $start_date = new DateTime($item['start_datetime']);
        $end_date = new DateTime($item['end_datetime']);
        
        // Etkinliğin olduğu her günü işaretle
        $period = new DatePeriod(
            $start_date,
            new DateInterval('P1D'),
            $end_date->modify('+1 day')
        );
        
        foreach ($period as $day) {
            $day_num = $day->format('j');
            if ($day->format('m') == $month && $day->format('Y') == $year) {
                if (!isset($events_by_day[$day_num])) {
                    $events_by_day[$day_num] = [];
                }
                $events_by_day[$day_num][] = $item;
            }
        }
    }
    
    // Takvim html'ini oluştur
    $calendar = '<table class="table table-bordered calendar-table">';
    $calendar .= '<thead>';
    $calendar .= '<tr>';
    
    // Gün başlıkları
    foreach ($day_names as $day) {
        $calendar .= '<th>' . $day . '</th>';
    }
    
    $calendar .= '</tr>';
    $calendar .= '</thead>';
    $calendar .= '<tbody>';
    
    // İlk satırın başında boş hücreler oluştur
    $calendar .= '<tr>';
    for ($i = 1; $i < $day_of_week; $i++) {
        $calendar .= '<td class="empty"></td>';
    }
    
    // Günleri ekle
    $day_count = $day_of_week - 1;
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        // Haftanın son gününde yeni satır başlat
        if ($day_count > 6) {
            $calendar .= '</tr><tr>';
            $day_count = 0;
        }
        
        $day_count++;
        
        // Bugünün tarihi
        $date = $year . '-' . sprintf('%02d', $month) . '-' . sprintf('%02d', $day);
        $today_class = (date('Y-m-d') == $date) ? 'today' : '';
        
        // Günün etkinlikleri
        $events_html = '';
        if (isset($events_by_day[$day])) {
            foreach ($events_by_day[$day] as $item) {
                $event_class = '';
                switch ($item['event_type']) {
                    case 'meeting':
                        $event_class = 'event-meeting';
                        break;
                    case 'deadline':
                        $event_class = 'event-deadline';
                        break;
                    case 'reminder':
                        $event_class = 'event-reminder';
                        break;
                    case 'project':
                        $event_class = 'event-project';
                        // Projenin durumuna göre alt sınıf ekle
                        if (isset($item['status'])) {
                            switch ($item['status']) {
                                case 'planning':
                                    $event_class .= ' project-planning';
                                    break;
                                case 'in_progress':
                                    $event_class .= ' project-progress';
                                    break;
                                case 'review':
                                    $event_class .= ' project-review';
                                    break;
                                case 'completed':
                                    $event_class .= ' project-completed';
                                    break;
                            }
                        }
                        break;
                    default:
                        $event_class = 'event-other';
                }
                
                // Başlangıç tarihi bu gün mü?
                $is_start_date = false;
                $start_time = '';
                if (substr($item['start_datetime'], 0, 10) == $date) {
                    $is_start_date = true;
                    $start_time = (new DateTime($item['start_datetime']))->format('H:i');
                }
                
                // Bitiş tarihi bu gün mü?
                $is_end_date = false;
                if (substr($item['end_datetime'], 0, 10) == $date) {
                    $is_end_date = true;
                }
                
                $events_html .= '<div class="calendar-event ' . $event_class . '" data-event-id="' . $item['id'] . '" data-event-type="' . $item['event_type'] . '">';
                
                // Projeleri özel göster
                if ($item['event_type'] == 'project') {
                    if ($is_start_date) {
                        $events_html .= '<i class="bi bi-flag-fill"></i> '; // Başlangıç için bayrak ikonu
                    } else if ($is_end_date) {
                        $events_html .= '<i class="bi bi-flag-fill"></i> '; // Bitiş için bayrak ikonu
                    } else {
                        $events_html .= '<i class="bi bi-kanban"></i> '; // Devam eden proje için
                    }
                } else {
                    // Normal etkinliklerde saat göster
                    if (!empty($start_time)) {
                        $events_html .= '<span class="event-time">' . $start_time . '</span> ';
                    }
                }
                
                $events_html .= htmlspecialchars($item['title']);
                $events_html .= '</div>';
            }
        }
        
        // Gün hücresini oluştur
        $calendar .= '<td class="day ' . $today_class . '" data-date="' . $date . '">';
        $calendar .= '<div class="day-number">' . $day . '</div>';
        $calendar .= '<div class="day-events">' . $events_html . '</div>';
        $calendar .= '</td>';
    }
    
    // Son satırdaki kalan günleri doldur
    while ($day_count < 7) {
        $calendar .= '<td class="empty"></td>';
        $day_count++;
    }
    
    $calendar .= '</tr>';
    $calendar .= '</tbody>';
    $calendar .= '</table>';
    
    return $calendar;
}

?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Takvim</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .calendar-table {
            table-layout: fixed;
            height: 600px;
        }
        .calendar-table th {
            text-align: center;
            background-color: var(--bs-primary);
            color: white;
        }
        .calendar-table td {
            vertical-align: top;
            height: 100px;
            padding: 5px;
        }
        .calendar-table td.empty {
            background-color: #f8f9fa;
        }
        .day-number {
            font-weight: bold;
            margin-bottom: 5px;
            text-align: right;
        }
        .day.today {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            box-shadow: inset 0 0 0 2px var(--bs-primary);
        }
        .calendar-event {
            font-size: 0.8rem;
            padding: 2px 4px;
            margin-bottom: 3px;
            border-radius: 3px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .event-meeting {
            background-color: rgba(var(--bs-primary-rgb), 0.2);
            border-left: 3px solid var(--bs-primary);
        }
        .event-deadline {
            background-color: rgba(var(--bs-danger-rgb), 0.2);
            border-left: 3px solid var(--bs-danger);
        }
        .event-reminder {
            background-color: rgba(var(--bs-warning-rgb), 0.2);
            border-left: 3px solid var(--bs-warning);
        }
        .event-other {
            background-color: rgba(var(--bs-info-rgb), 0.2);
            border-left: 3px solid var(--bs-info);
        }
        /* Proje stilleri */
        .event-project {
            background-color: rgba(var(--bs-success-rgb), 0.2);
            border-left: 3px solid var(--bs-success);
            font-weight: bold;
        }
        .project-planning {
            background-color: rgba(var(--bs-secondary-rgb), 0.2);
            border-left: 3px solid var(--bs-secondary);
        }
        .project-progress {
            background-color: rgba(var(--bs-success-rgb), 0.2);
            border-left: 3px solid var(--bs-success);
        }
        .project-review {
            background-color: rgba(var(--bs-warning-rgb), 0.2);
            border-left: 3px solid var(--bs-warning);
        }
        .project-completed {
            background-color: rgba(var(--bs-dark-rgb), 0.2);
            border-left: 3px solid var(--bs-dark);
            text-decoration: line-through;
        }
        .event-time {
            font-weight: bold;
        }
        .calendar-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .calendar-title {
            font-size: 1.5rem;
            font-weight: bold;
        }
        /* Lejant */
        .calendar-legend {
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            font-size: 0.8rem;
            margin-right: 15px;
        }
        .legend-color {
            width: 16px;
            height: 16px;
            margin-right: 5px;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Ana içerik -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Takvim</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newEventModal">
                            <i class="bi bi-plus-lg"></i> Yeni Etkinlik
                        </button>
                    </div>
                </div>
                
                <!-- Takvim Gezinme -->
                <div class="calendar-navigation">
                    <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-chevron-left"></i> Önceki Ay
                    </a>
                    
                    <div class="calendar-title">
                        <?php 
                            $month_name = date('F', mktime(0, 0, 0, $current_month, 1, $current_year));
                            $month_name_tr = [
                                'January' => 'Ocak',
                                'February' => 'Şubat',
                                'March' => 'Mart',
                                'April' => 'Nisan',
                                'May' => 'Mayıs',
                                'June' => 'Haziran',
                                'July' => 'Temmuz',
                                'August' => 'Ağustos',
                                'September' => 'Eylül',
                                'October' => 'Ekim',
                                'November' => 'Kasım',
                                'December' => 'Aralık'
                            ];
                            echo $month_name_tr[$month_name] . ' ' . $current_year;
                        ?>
                    </div>
                    
                    <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="btn btn-outline-primary">
                        Sonraki Ay <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
                
                <!-- Lejant (Açıklama) -->
                <div class="calendar-legend card p-2 mb-2">
                    <div class="d-flex flex-wrap">
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-primary-rgb), 0.2); border-left: 3px solid var(--bs-primary);"></div>
                            <span>Toplantı</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-danger-rgb), 0.2); border-left: 3px solid var(--bs-danger);"></div>
                            <span>Son Tarih</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-warning-rgb), 0.2); border-left: 3px solid var(--bs-warning);"></div>
                            <span>Hatırlatıcı</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-secondary-rgb), 0.2); border-left: 3px solid var(--bs-secondary);"></div>
                            <span>Planlama Aşamasında Proje</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-success-rgb), 0.2); border-left: 3px solid var(--bs-success);"></div>
                            <span>Devam Eden Proje</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-warning-rgb), 0.2); border-left: 3px solid var(--bs-warning);"></div>
                            <span>İnceleme Aşamasında Proje</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: rgba(var(--bs-dark-rgb), 0.2); border-left: 3px solid var(--bs-dark);"></div>
                            <span>Tamamlanmış Proje</span>
                        </div>
                    </div>
                </div>
                
                <!-- Takvim -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <?php echo build_calendar($current_month, $current_year, $calendar_items); ?>
                    </div>
                </div>
                
                <!-- Etkinlik ve Proje Listesi -->
                <div class="row">
                    <!-- Etkinlik Listesi -->
                    <div class="col-md-6">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Bu Aydaki Etkinlikler</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>Başlık</th>
                                                <th>Tür</th>
                                                <th>Başlangıç</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($events) > 0): ?>
                                                <?php foreach ($events as $event): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($event['title']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($event['event_type']) {
                                                                    case 'meeting': echo 'primary'; break;
                                                                    case 'deadline': echo 'danger'; break;
                                                                    case 'reminder': echo 'warning'; break;
                                                                    default: echo 'info'; break;
                                                                }
                                                            ?>">
                                                                <?php 
                                                                    switch($event['event_type']) {
                                                                        case 'meeting': echo 'Toplantı'; break;
                                                                        case 'deadline': echo 'Son Tarih'; break;
                                                                        case 'reminder': echo 'Hatırlatıcı'; break;
                                                                        default: echo 'Diğer'; break;
                                                                    }
                                                                ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo format_date($event['start_datetime']); ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-primary view-event" data-event-id="<?php echo $event['id']; ?>">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-warning edit-event" data-event-id="<?php echo $event['id']; ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-danger delete-event" data-event-id="<?php echo $event['id']; ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">Bu ay için etkinlik bulunmuyor.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Proje Listesi -->
                    <div class="col-md-6">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-success">Bu Aydaki Projeler</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>Proje</th>
                                                <th>Kategori</th>
                                                <th>Durum</th>
                                                <th>Tarihler</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($projects) > 0): ?>
                                                <?php foreach ($projects as $project): ?>
                                                    <tr>
                                                        <td>
                                                            <a href="../projects/view.php?id=<?php echo $project['id']; ?>">
                                                                <?php echo htmlspecialchars(str_replace('[Proje] ', '', $project['title'])); ?>
                                                            </a>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($project['category_name']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($project['status']) {
                                                                    case 'planning': echo 'secondary'; break;
                                                                    case 'in_progress': echo 'primary'; break;
                                                                    case 'review': echo 'warning'; break;
                                                                    case 'completed': echo 'success'; break;
                                                                    default: echo 'info'; break;
                                                                }
                                                            ?>">
                                                                <?php 
                                                                    switch($project['status']) {
                                                                        case 'planning': echo 'Planlama'; break;
                                                                        case 'in_progress': echo 'Devam Ediyor'; break;
                                                                        case 'review': echo 'İnceleme'; break;
                                                                        case 'completed': echo 'Tamamlandı'; break;
                                                                        default: echo $project['status']; break;
                                                                    }
                                                                ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small>
                                                                <strong>Başlangıç:</strong> <?php echo date('d.m.Y', strtotime($project['start_datetime'])); ?><br>
                                                                <strong>Bitiş:</strong> <?php echo date('d.m.Y', strtotime($project['end_datetime'])); ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">Bu ay için proje bulunmuyor.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Yeni Etkinlik Modalı -->
    <div class="modal fade" id="newEventModal" tabindex="-1" aria-labelledby="newEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="create_event.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="newEventModalLabel">Yeni Etkinlik Oluştur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="event_title" class="form-label">Etkinlik Başlığı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="event_title" name="title" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="event_type" class="form-label">Etkinlik Türü <span class="text-danger">*</span></label>
                                <select class="form-select" id="event_type" name="event_type" required>
                                    <option value="meeting">Toplantı</option>
                                    <option value="deadline">Son Tarih</option>
                                    <option value="reminder">Hatırlatıcı</option>
                                    <option value="other">Diğer</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="event_description" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="event_description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="event_start_date" class="form-label">Başlangıç Tarihi ve Saati <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" id="event_start_date" name="start_datetime" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="event_end_date" class="form-label">Bitiş Tarihi ve Saati <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" id="event_end_date" name="end_datetime" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="event_location" class="form-label">Konum</label>
                                <input type="text" class="form-control" id="event_location" name="location">
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="event_all_day" name="all_day" value="1">
                                    <label class="form-check-label" for="event_all_day">
                                        Tüm gün sürecek etkinlik
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row" id="related_entity_row">
                            <div class="col-md-6 mb-3">
                                <label for="related_type" class="form-label">İlişkili Öğe Türü</label>
                                <select class="form-select" id="related_type" name="related_type">
                                    <option value="">Seçiniz</option>
                                    <option value="project">Proje</option>
                                    <option value="task">Görev</option>
                                    <option value="meeting">Toplantı</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="related_id" class="form-label">İlişkili Öğe</label>
                                <select class="form-select" id="related_id" name="related_id" disabled>
                                    <option value="">Önce öğe türü seçin</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Etkinliği Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Etkinlik Görüntüleme Modalı -->
    <div class="modal fade" id="viewEventModal" tabindex="-1" aria-labelledby="viewEventModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewEventModalLabel">Etkinlik Detayları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="viewEventContent">
                    <!-- AJAX ile doldurulacak -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Etkinlik Silme Modalı -->
    <div class="modal fade" id="deleteEventModal" tabindex="-1" aria-labelledby="deleteEventModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="deleteEventForm" action="delete_event.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" id="delete_event_id" name="event_id" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteEventModalLabel">Etkinliği Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p>Bu etkinliği silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Etkinliği Sil</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/notifications.js"></script>
    
    <script>
        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            // Takvim gününe tıklanınca yeni etkinlik modalını aç
            document.querySelectorAll('.day').forEach(function(day) {
                day.addEventListener('click', function() {
                    // Seçilen tarihi al
                    var date = this.getAttribute('data-date');
                    
                    if (date) {
                        // Başlangıç ve bitiş tarihlerini ayarla
                        document.getElementById('event_start_date').value = date + 'T09:00';
                        document.getElementById('event_end_date').value = date + 'T10:00';
                        
                        // Modalı aç
                        var modal = new bootstrap.Modal(document.getElementById('newEventModal'));
                        modal.show();
                    }
                });
            });
            
            // Etkinliğe tıklanınca görüntüleme modalını aç
            document.querySelectorAll('.calendar-event').forEach(function(event) {
                event.addEventListener('click', function(e) {
                    e.stopPropagation();
                    
                    var eventId = this.getAttribute('data-event-id');
                    var eventType = this.getAttribute('data-event-type');
                    
                    if (eventType === 'project') {
                        // Projeler için proje detay sayfasına yönlendir
                        window.location.href = '../projects/view.php?id=' + eventId;
                    } else {
                        // Normal etkinlikler için modalı göster
                        viewEvent(eventId);
                    }
                });
            });
            
            // Etkinlik görüntüleme butonları
            document.querySelectorAll('.view-event').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var eventId = this.getAttribute('data-event-id');
                    viewEvent(eventId);
                });
            });
            
            // Etkinlik silme butonları
            document.querySelectorAll('.delete-event').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var eventId = this.getAttribute('data-event-id');
                    document.getElementById('delete_event_id').value = eventId;
                    
                    var modal = new bootstrap.Modal(document.getElementById('deleteEventModal'));
                    modal.show();
                });
            });
            
            // Tüm gün etkinlik seçeneği
            document.getElementById('event_all_day').addEventListener('change', function() {
                if (this.checked) {
                    // Başlangıç saatini 00:00 yap
                    var startDate = document.getElementById('event_start_date').value.split('T')[0];
                    document.getElementById('event_start_date').value = startDate + 'T00:00';
                    
                    // Bitiş saatini 23:59 yap
                    var endDate = document.getElementById('event_end_date').value.split('T')[0];
                    document.getElementById('event_end_date').value = endDate + 'T23:59';
                    
                    // Zaman giriş alanlarını devre dışı bırak
                    document.getElementById('event_start_date').disabled = true;
                    document.getElementById('event_end_date').disabled = true;
                } else {
                    // Zaman giriş alanlarını etkinleştir
                    document.getElementById('event_start_date').disabled = false;
                    document.getElementById('event_end_date').disabled = false;
                }
            });
            
            // İlişkili öğe türü değiştiğinde öğeleri getir
            document.getElementById('related_type').addEventListener('change', function() {
                var relatedType = this.value;
                var relatedIdSelect = document.getElementById('related_id');
                
                if (relatedType) {
                    // AJAX ile ilgili öğeleri getir
                    fetch('../api/get_related_items.php?type=' + relatedType)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Select alanını doldur
                                var options = '<option value="">Seçiniz</option>';
                                data.items.forEach(item => {
                                    options += '<option value="' + item.id + '">' + item.name + '</option>';
                                });
                                
                                relatedIdSelect.innerHTML = options;
                                relatedIdSelect.disabled = false;
                            } else {
                                relatedIdSelect.innerHTML = '<option value="">Öğeler alınamadı</option>';
                                relatedIdSelect.disabled = true;
                            }
                        })
                        .catch(error => {
                            relatedIdSelect.innerHTML = '<option value="">Bir hata oluştu</option>';
                            relatedIdSelect.disabled = true;
                        });
                } else {
                    relatedIdSelect.innerHTML = '<option value="">Önce öğe türü seçin</option>';
                    relatedIdSelect.disabled = true;
                }
            });
        });
        
        // Etkinlik görüntüleme fonksiyonu
        function viewEvent(eventId) {
            // AJAX ile etkinlik detaylarını al
            fetch('get_event.php?id=' + eventId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Modal içeriğini doldur
                        var event = data.event;
                        var content = '<div class="event-details">';
                        content += '<h4>' + event.title + '</h4>';
                        content += '<p>' + event.description + '</p>';
                        content += '<ul class="list-group list-group-flush">';
                        content += '<li class="list-group-item"><strong>Tür:</strong> ' + getEventTypeText(event.event_type) + '</li>';
                        content += '<li class="list-group-item"><strong>Başlangıç:</strong> ' + formatDateTime(event.start_datetime) + '</li>';
                        content += '<li class="list-group-item"><strong>Bitiş:</strong> ' + formatDateTime(event.end_datetime) + '</li>';
                        if (event.location) {
                            content += '<li class="list-group-item"><strong>Konum:</strong> ' + event.location + '</li>';
                        }
                        content += '</ul>';
                        content += '</div>';
                        
                        document.getElementById('viewEventContent').innerHTML = content;
                        var modal = new bootstrap.Modal(document.getElementById('viewEventModal'));
                        modal.show();
                    } else {
                        alert('Etkinlik detayları alınamadı.');
                    }
                })
                .catch(error => {
                    alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                });
        }
        
        // Etkinlik türü metni
        function getEventTypeText(type) {
            switch(type) {
                case 'meeting': return 'Toplantı';
                case 'deadline': return 'Son Tarih';
                case 'reminder': return 'Hatırlatıcı';
                default: return 'Diğer';
            }
        }
        
        // Tarih formatı
        function formatDateTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString('tr-TR', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // Bugünün tarihini varsayılan olarak ayarla
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const dateString = now.toISOString().slice(0, 16);
            
            // Saati bir saat sonrasına ayarla
            const later = new Date(now.getTime() + 60 * 60 * 1000);
            const laterString = later.toISOString().slice(0, 16);
            
            document.getElementById('event_start_date').value = dateString;
            document.getElementById('event_end_date').value = laterString;
        });
    </script>
</body>
</html>
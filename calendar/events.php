<?php
// calendar/events.php - Takvim etkinliklerini listeleme ve yönetme sayfası

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

// Filtreleme parametreleri
$period = isset($_GET['period']) ? sanitize_input($_GET['period']) : 'upcoming';
$event_type = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';
$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

// Başlangıç ve bitiş tarihleri
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d 00:00:00');
$tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
$week_end = date('Y-m-d 23:59:59', strtotime('+7 days'));
$month_end = date('Y-m-d 23:59:59', strtotime('+30 days'));

// SQL sorgusu için temel koşullar
$where_conditions = [];
$params = [];

// Dönem filtresi
switch ($period) {
    case 'today':
        $where_conditions[] = "(start_datetime BETWEEN :period_start AND :period_end OR end_datetime BETWEEN :period_start AND :period_end)";
        $params['period_start'] = $today;
        $params['period_end'] = date('Y-m-d 23:59:59');
        $period_title = "Bugünkü Etkinlikler";
        break;
        
    case 'tomorrow':
        $where_conditions[] = "(start_datetime BETWEEN :period_start AND :period_end OR end_datetime BETWEEN :period_start AND :period_end)";
        $params['period_start'] = $tomorrow;
        $params['period_end'] = date('Y-m-d 23:59:59', strtotime('+1 day'));
        $period_title = "Yarınki Etkinlikler";
        break;
        
    case 'week':
        $where_conditions[] = "(start_datetime BETWEEN :period_start AND :period_end OR end_datetime BETWEEN :period_start AND :period_end)";
        $params['period_start'] = $today;
        $params['period_end'] = $week_end;
        $period_title = "Bu Haftaki Etkinlikler";
        break;
        
    case 'month':
        $where_conditions[] = "(start_datetime BETWEEN :period_start AND :period_end OR end_datetime BETWEEN :period_start AND :period_end)";
        $params['period_start'] = $today;
        $params['period_end'] = $month_end;
        $period_title = "Bu Ayki Etkinlikler";
        break;
        
    case 'past':
        $where_conditions[] = "end_datetime < :now";
        $params['now'] = $now;
        $period_title = "Geçmiş Etkinlikler";
        break;
        
    case 'all':
        $period_title = "Tüm Etkinlikler";
        break;
        
    default: // upcoming
        $where_conditions[] = "end_datetime >= :now";
        $params['now'] = $now;
        $period_title = "Yaklaşan Etkinlikler";
        break;
}

// Etkinlik türü filtresi
if (!empty($event_type)) {
    $where_conditions[] = "event_type = :event_type";
    $params['event_type'] = $event_type;
}

// Arama
if (!empty($search)) {
    $where_conditions[] = "(title LIKE :search OR description LIKE :search OR location LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

// SQL sorgusu oluştur
$sql = "SELECT * FROM calendar_events";
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}
$sql .= " ORDER BY start_datetime ASC";

// Sorguyu çalıştır
$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Etkinlik türü seçenekleri
$event_types = [
    'meeting' => 'Toplantı',
    'deadline' => 'Son Tarih',
    'reminder' => 'Hatırlatıcı',
    'other' => 'Diğer'
];

// Bildirimler
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Etkinlikler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .event-indicator {
            width: 10px;
            height: 10px;
            display: inline-block;
            margin-right: 5px;
            border-radius: 50%;
        }
        .event-meeting {
            background-color: var(--bs-primary);
        }
        .event-deadline {
            background-color: var(--bs-danger);
        }
        .event-reminder {
            background-color: var(--bs-warning);
        }
        .event-other {
            background-color: var(--bs-info);
        }
        .table-hover tbody tr:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        .filter-card {
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .filter-card .card-body {
            padding: 1rem;
        }
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .description-cell {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
                    <h1 class="h2">Etkinlikler</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newEventModal">
                            <i class="bi bi-plus-lg"></i> Yeni Etkinlik
                        </button>
                    </div>
                </div>
                
                <!-- Bildirimler -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Filtreleme -->
                <div class="card shadow filter-card">
                    <div class="card-body">
                        <form action="events.php" method="get" class="row">
                            <div class="col-md-4 mb-2">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" name="search" placeholder="Etkinlik ara..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            <div class="col-md-3 mb-2">
                                <select class="form-select" name="period">
                                    <option value="upcoming" <?php echo $period == 'upcoming' ? 'selected' : ''; ?>>Yaklaşan Etkinlikler</option>
                                    <option value="today" <?php echo $period == 'today' ? 'selected' : ''; ?>>Bugün</option>
                                    <option value="tomorrow" <?php echo $period == 'tomorrow' ? 'selected' : ''; ?>>Yarın</option>
                                    <option value="week" <?php echo $period == 'week' ? 'selected' : ''; ?>>Bu Hafta</option>
                                    <option value="month" <?php echo $period == 'month' ? 'selected' : ''; ?>>Bu Ay</option>
                                    <option value="past" <?php echo $period == 'past' ? 'selected' : ''; ?>>Geçmiş Etkinlikler</option>
                                    <option value="all" <?php echo $period == 'all' ? 'selected' : ''; ?>>Tüm Etkinlikler</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-2">
                                <select class="form-select" name="type">
                                    <option value="">Tüm Türler</option>
                                    <?php foreach ($event_types as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $event_type == $key ? 'selected' : ''; ?>>
                                            <?php echo $value; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 mb-2">
                                <button type="submit" class="btn btn-primary w-100">Filtrele</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Etkinlikler Tablosu -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><?php echo $period_title; ?></h6>
                        <div class="actions">
                            <a href="index.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-calendar3"></i> Takvim Görünümü
                            </a>
                            <?php if (!empty($search) || !empty($event_type) || $period != 'upcoming'): ?>
                                <a href="events.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Filtreleri Temizle
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($events) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Etkinlik</th>
                                            <th>Tür</th>
                                            <th>Açıklama</th>
                                            <th>Başlangıç</th>
                                            <th>Bitiş</th>
                                            <th>Konum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($events as $event): ?>
                                            <tr>
                                                <td>
                                                    <strong>
                                                        <?php echo htmlspecialchars($event['title']); ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <span class="event-indicator event-<?php echo $event['event_type']; ?>"></span>
                                                    <?php 
                                                        switch($event['event_type']) {
                                                            case 'meeting': echo 'Toplantı'; break;
                                                            case 'deadline': echo 'Son Tarih'; break;
                                                            case 'reminder': echo 'Hatırlatıcı'; break;
                                                            default: echo 'Diğer'; break;
                                                        }
                                                    ?>
                                                </td>
                                                <td class="description-cell">
                                                    <?php echo !empty($event['description']) ? htmlspecialchars($event['description']) : '<span class="text-muted">Açıklama yok</span>'; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $start_date = new DateTime($event['start_datetime']);
                                                        echo $start_date->format('d.m.Y H:i');
                                                        
                                                        // Etkinlik bugün mü?
                                                        $today_date = new DateTime('today');
                                                        if ($start_date->format('Y-m-d') == $today_date->format('Y-m-d')) {
                                                            echo ' <span class="badge bg-primary">Bugün</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $end_date = new DateTime($event['end_datetime']);
                                                        echo $end_date->format('d.m.Y H:i');
                                                        
                                                        // Etkinlik süresi hesaplama
                                                        $duration = $start_date->diff($end_date);
                                                        if ($duration->days > 0) {
                                                            echo ' <span class="badge bg-info">' . $duration->days . ' gün</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php echo !empty($event['location']) ? htmlspecialchars($event['location']) : '<span class="text-muted">-</span>'; ?>
                                                </td>
                                                <td class="action-buttons">
                                                    <button class="btn btn-sm btn-primary view-event" data-event-id="<?php echo $event['id']; ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <a href="edit_event.php?id=<?php echo $event['id']; ?>" class="btn btn-sm btn-warning">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-danger delete-event" data-event-id="<?php echo $event['id']; ?>" data-bs-toggle="modal" data-bs-target="#deleteEventModal" data-event-title="<?php echo htmlspecialchars($event['title']); ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i> Seçilen kriterlere uygun etkinlik bulunamadı.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Özet Bilgiler -->
                <div class="row">
                    <!-- Bugünkü Etkinlikler -->
                    <div class="col-md-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Bugünkü Etkinlikler</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                    $today_events = array_filter($events, function($event) use ($today) {
                                        return (
                                            (substr($event['start_datetime'], 0, 10) <= date('Y-m-d') && 
                                             substr($event['end_datetime'], 0, 10) >= date('Y-m-d'))
                                        );
                                    });
                                    
                                    if (count($today_events) > 0):
                                ?>
                                    <ul class="list-group">
                                        <?php foreach ($today_events as $event): ?>
                                            <li class="list-group-item">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <div>
                                                        <span class="event-indicator event-<?php echo $event['event_type']; ?>"></span>
                                                        <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                                    </div>
                                                    <small><?php echo (new DateTime($event['start_datetime']))->format('H:i'); ?></small>
                                                </div>
                                                <?php if (!empty($event['location'])): ?>
                                                    <small class="text-muted"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($event['location']); ?></small>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="alert alert-light">
                                        <i class="bi bi-calendar-x me-2"></i> Bugün için planlanmış etkinlik bulunmuyor.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yaklaşan Önemli Etkinlikler -->
                    <div class="col-md-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Yaklaşan Önemli Etkinlikler</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                    // Deadline ve Meeting türündeki etkinlikleri filtreleme
                                    $important_events = array_filter($events, function($event) use ($now) {
                                        return (
                                            ($event['event_type'] == 'deadline' || $event['event_type'] == 'meeting') && 
                                            $event['end_datetime'] >= $now
                                        );
                                    });
                                    
                                    // Tarihe göre sırala
                                    usort($important_events, function($a, $b) {
                                        return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
                                    });
                                    
                                    // En yakın 5 etkinliği göster
                                    $important_events = array_slice($important_events, 0, 5);
                                    
                                    if (count($important_events) > 0):
                                ?>
                                    <ul class="list-group">
                                        <?php foreach ($important_events as $event): ?>
                                            <li class="list-group-item">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <div>
                                                        <span class="event-indicator event-<?php echo $event['event_type']; ?>"></span>
                                                        <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                                    </div>
                                                    <small>
                                                        <?php 
                                                            $event_date = new DateTime($event['start_datetime']);
                                                            $today_date = new DateTime('today');
                                                            $diff = $today_date->diff($event_date);
                                                            
                                                            if ($diff->days == 0) {
                                                                echo 'Bugün';
                                                            } elseif ($diff->days == 1) {
                                                                echo 'Yarın';
                                                            } else {
                                                                echo $diff->days . ' gün sonra';
                                                            }
                                                        ?>
                                                    </small>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar"></i> <?php echo $event_date->format('d.m.Y H:i'); ?>
                                                </small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="alert alert-light">
                                        <i class="bi bi-calendar-check me-2"></i> Yaklaşan önemli etkinlik bulunmuyor.
                                    </div>
                                <?php endif; ?>
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
                        <p><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> <strong id="delete_event_title"></strong> etkinliğini silmek istediğinizden emin misiniz?</p>
                        <p>Bu işlem geri alınamaz.</p>
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
                    var eventTitle = this.getAttribute('data-event-title');
                    
                    document.getElementById('delete_event_id').value = eventId;
                    document.getElementById('delete_event_title').textContent = eventTitle;
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
                        content += '<p>' + (event.description || 'Açıklama yok') + '</p>';
                        content += '<ul class="list-group list-group-flush">';
                        content += '<li class="list-group-item"><strong>Tür:</strong> ' + getEventTypeText(event.event_type) + '</li>';
                        content += '<li class="list-group-item"><strong>Başlangıç:</strong> ' + formatDateTime(event.start_datetime) + '</li>';
                        content += '<li class="list-group-item"><strong>Bitiş:</strong> ' + formatDateTime(event.end_datetime) + '</li>';
                        if (event.location) {
                            content += '<li class="list-group-item"><strong>Konum:</strong> ' + event.location + '</li>';
                        }
                        content += '</ul>';
                        
                        // İlişkili öğe varsa göster
                        if (event.related_type && event.related_id) {
                            content += '<div class="mt-3">';
                            content += '<h5>İlişkili Öğe</h5>';
                            content += '<p>';
                            
                            switch (event.related_type) {
                                case 'project':
                                    content += '<a href="../projects/view.php?id=' + event.related_id + '">';
                                    content += '<i class="bi bi-kanban"></i> Proje Detayları';
                                    content += '</a>';
                                    break;
                                case 'task':
                                    content += '<a href="../projects/task_view.php?id=' + event.related_id + '">';
                                    content += '<i class="bi bi-check2-square"></i> Görev Detayları';
                                    content += '</a>';
                                    break;
                                case 'meeting':
                                    content += '<a href="../meetings/view.php?id=' + event.related_id + '">';
                                    content += '<i class="bi bi-people"></i> Toplantı Detayları';
                                    content += '</a>';
                                    break;
                            }
                            
                            content += '</p>';
                            content += '</div>';
                        }
                        
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
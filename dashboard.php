<?php
// dashboard.php - Ana gösterge paneli sayfası

session_start();
require_once 'config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

// Kullanıcı verisini al
$user = get_authenticated_user();
$theme = get_user_theme();

// Son aktiviteleri al
$db = connect_db();
$stmt = $db->prepare("
    SELECT a.*, u.full_name 
    FROM activities a 
    JOIN users u ON a.user_id = u.id 
    ORDER BY a.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$activities = $stmt->fetchAll();

// Yaklaşan toplantıları al
$stmt = $db->prepare("
    SELECT * FROM meetings 
    WHERE meeting_date >= NOW() 
    ORDER BY meeting_date ASC 
    LIMIT 5
");
$stmt->execute();
$upcoming_meetings = $stmt->fetchAll();

// Devam eden projeleri al
$stmt = $db->prepare("
    SELECT * FROM projects 
    WHERE status = 'in_progress' 
    ORDER BY due_date ASC
");
$stmt->execute();
$active_projects = $stmt->fetchAll();

// Bugünkü takvim etkinliklerini al
$today = date('Y-m-d');
$stmt = $db->prepare("
    SELECT * FROM calendar_events 
    WHERE DATE(start_datetime) = :today 
    ORDER BY start_datetime ASC
");
$stmt->execute(['today' => $today]);
$today_events = $stmt->fetchAll();

// Okunmamış bildirimleri al
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = :user_id AND is_read = 0 
    ORDER BY created_at DESC
");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$unread_notifications = $stmt->fetchAll();
$notification_count = count($unread_notifications);
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Navbar -->
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'includes/sidebar.php'; ?>
            
            <!-- Ana içerik -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">Paylaş</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">Dışa Aktar</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle">
                            <i class="bi bi-calendar"></i> Bu Hafta
                        </button>
                    </div>
                </div>
                
                <!-- Özet Bilgiler -->
                <div class="row">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Aktif Projeler</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo count($active_projects); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-folder-fill fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Bugünkü Etkinlikler</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo count($today_events); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-calendar-date fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Yaklaşan Toplantılar</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo count($upcoming_meetings); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-people-fill fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Bildirimler</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo $notification_count; ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-bell-fill fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- İçerik Satırları -->
                <div class="row">
                    <!-- Aktiviteler -->
                    <div class="col-lg-8">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Son Aktiviteler</h6>
                            </div>
                            <div class="card-body">
                                <div class="activity-feed">
                                    <?php foreach ($activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-content">
                                                <div class="activity-header">
                                                    <span class="activity-user"><?php echo htmlspecialchars($activity['full_name']); ?></span>
                                                    <span class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></span>
                                                    <span class="activity-time"><?php echo format_date($activity['created_at']); ?></span>
                                                </div>
                                                <div class="activity-description">
                                                    <?php echo htmlspecialchars($activity['description']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yaklaşan Toplantılar ve Etkinlikler -->
                    <div class="col-lg-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Yaklaşan Toplantılar</h6>
                            </div>
                            <div class="card-body">
                                <?php if (count($upcoming_meetings) > 0): ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($upcoming_meetings as $meeting): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                                <div class="ms-2 me-auto">
                                                    <div class="fw-bold"><?php echo htmlspecialchars($meeting['title']); ?></div>
                                                    <?php echo format_date($meeting['meeting_date']); ?>
                                                </div>
                                                <a href="meetings/view.php?id=<?php echo $meeting['id']; ?>" class="badge bg-primary rounded-pill">Detay</a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-center py-3">Yaklaşan toplantı bulunmuyor.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Bugünkü Etkinlikler</h6>
                            </div>
                            <div class="card-body">
                                <?php if (count($today_events) > 0): ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($today_events as $event): ?>
                                            <li class="list-group-item">
                                                <div class="fw-bold"><?php echo htmlspecialchars($event['title']); ?></div>
                                                <small><?php echo format_date($event['start_datetime'], 'H:i'); ?> - <?php echo format_date($event['end_datetime'], 'H:i'); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-center py-3">Bugün için planlanmış etkinlik bulunmuyor.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Aktif Projeler Tablosu -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Aktif Projeler</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>Proje Adı</th>
                                        <th>Kategori</th>
                                        <th>Durum</th>
                                        <th>Öncelik</th>
                                        <th>Başlangıç</th>
                                        <th>Bitiş</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_projects as $project): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($project['name']); ?></td>
                                            <td><?php echo htmlspecialchars($project['category']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch($project['status']) {
                                                        case 'planning': echo 'secondary'; break;
                                                        case 'in_progress': echo 'primary'; break;
                                                        case 'review': echo 'warning'; break;
                                                        case 'completed': echo 'success'; break;
                                                        default: echo 'light'; break;
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
                                                <span class="badge bg-<?php 
                                                    switch($project['priority']) {
                                                        case 'low': echo 'success'; break;
                                                        case 'medium': echo 'info'; break;
                                                        case 'high': echo 'warning'; break;
                                                        case 'urgent': echo 'danger'; break;
                                                        default: echo 'light'; break;
                                                    }
                                                ?>">
                                                    <?php 
                                                        switch($project['priority']) {
                                                            case 'low': echo 'Düşük'; break;
                                                            case 'medium': echo 'Orta'; break;
                                                            case 'high': echo 'Yüksek'; break;
                                                            case 'urgent': echo 'Acil'; break;
                                                            default: echo $project['priority']; break;
                                                        }
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo format_date($project['start_date'], 'd.m.Y'); ?></td>
                                            <td><?php echo format_date($project['due_date'], 'd.m.Y'); ?></td>
                                            <td>
                                                <a href="projects/view.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">Görüntüle</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Bildirim modalı -->
    <div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationsModalLabel">Bildirimler</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <?php if (count($unread_notifications) > 0): ?>
                        <ul class="list-group">
                            <?php foreach ($unread_notifications as $notification): ?>
                                <li class="list-group-item">
                                    <div class="fw-bold"><?php echo htmlspecialchars($notification['title']); ?></div>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <small class="text-muted"><?php echo format_date($notification['created_at']); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center">Okunmamış bildiriminiz bulunmuyor.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-primary" id="markAllRead">Tümünü Okundu İşaretle</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/theme-switcher.js"></script>
    <script src="assets/js/notifications.js"></script>
    
    <script>
        // Gerçek zamanlı bildirimler için
        const notificationCheck = function() {
            $.ajax({
                url: 'api/check_notifications.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data.count > 0) {
                        $('#notification-badge').text(data.count).show();
                    } else {
                        $('#notification-badge').hide();
                    }
                }
            });
        };
        
        // Her 30 saniyede bir bildirim kontrolü yap
        setInterval(notificationCheck, 30000);
        
        // Okundu işaretleme
        $('#markAllRead').click(function() {
            $.ajax({
                url: 'api/mark_notifications_read.php',
                type: 'POST',
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#notification-badge').hide();
                        $('#notificationsModal').modal('hide');
                    }
                }
            });
        });
    </script>
</body>
</html>
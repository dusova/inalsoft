<?php
// admin/index.php - Yönetim paneli ana sayfası

session_start();
require_once '../config/database.php';

// Sadece yöneticilerin erişimine izin ver
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../dashboard.php");
    exit;
}

// Kullanıcı verisini al
$user = get_authenticated_user();
$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Özet istatistikleri al
// Toplam kullanıcı sayısı
$stmt = $db->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = $stmt->fetchColumn();

// Toplam proje sayısı
$stmt = $db->prepare("SELECT COUNT(*) FROM projects");
$stmt->execute();
$total_projects = $stmt->fetchColumn();

// Toplam toplantı sayısı
$stmt = $db->prepare("SELECT COUNT(*) FROM meetings");
$stmt->execute();
$total_meetings = $stmt->fetchColumn();

// Toplam görev sayısı
$stmt = $db->prepare("SELECT COUNT(*) FROM tasks");
$stmt->execute();
$total_tasks = $stmt->fetchColumn();

// Son aktiviteleri al
$stmt = $db->prepare("
    SELECT a.*, u.full_name 
    FROM activities a 
    JOIN users u ON a.user_id = u.id 
    ORDER BY a.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$activities = $stmt->fetchAll();

// Son kaydolan kullanıcılar
$stmt = $db->prepare("
    SELECT id, username, full_name, email, role, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_users = $stmt->fetchAll();

// Sistem bildirimleri
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE type = 'system' 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$system_notifications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Yönetim Paneli</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
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
                    <h1 class="h2">Yönetim Paneli</h1>
                </div>
                
                <!-- Bildirimler -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Özet Bilgiler -->
                <div class="row">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Toplam Kullanıcılar</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo $total_users; ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-people-fill fa-2x text-gray-300"></i>
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
                                            Toplam Projeler</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo $total_projects; ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-folder-fill fa-2x text-gray-300"></i>
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
                                            Toplam Toplantılar</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo $total_meetings; ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-calendar-event-fill fa-2x text-gray-300"></i>
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
                                            Toplam Görevler</div>
                                        <div class="h5 mb-0 font-weight-bold"><?php echo $total_tasks; ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-list-check fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- İçerik Satırları -->
                <div class="row">
                    <!-- Sol Sütun -->
                    <div class="col-lg-8">
                        <!-- Son Aktiviteler -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Son Aktiviteler</h6>
                                <div class="dropdown no-arrow">
                                    <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in" aria-labelledby="dropdownMenuLink">
                                        <div class="dropdown-header">İşlemler:</div>
                                        <a class="dropdown-item" href="logs.php">Tüm Logları Görüntüle</a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="logs.php?clear=1">Logları Temizle</a>
                                    </div>
                                </div>
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
                        
                        <!-- Hızlı İşlemler -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Hızlı İşlemler</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <a href="users.php" class="btn btn-primary btn-block d-flex flex-column align-items-center p-3 h-100">
                                            <i class="bi bi-people fs-2"></i>
                                            <span class="mt-2">Kullanıcı Yönetimi</span>
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="settings.php" class="btn btn-info btn-block d-flex flex-column align-items-center p-3 h-100">
                                            <i class="bi bi-gear fs-2"></i>
                                            <span class="mt-2">Sistem Ayarları</span>
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="broadcast.php" class="btn btn-warning btn-block d-flex flex-column align-items-center p-3 h-100">
                                            <i class="bi bi-megaphone fs-2"></i>
                                            <span class="mt-2">Duyuru Yayınla</span>
                                        </a>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <a href="../projects/categories.php" class="btn btn-success btn-block d-flex flex-column align-items-center p-3 h-100">
                                            <i class="bi bi-folder-plus fs-2"></i>
                                            <span class="mt-2">Kategoriler</span>
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="reports.php" class="btn btn-secondary btn-block d-flex flex-column align-items-center p-3 h-100">
                                            <i class="bi bi-file-earmark-bar-graph fs-2"></i>
                                            <span class="mt-2">Raporlar</span>
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="backup.php" class="btn btn-danger btn-block d-flex flex-column align-items-center p-3 h-100">
                                            <i class="bi bi-cloud-download fs-2"></i>
                                            <span class="mt-2">Veritabanı Yedekle</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sağ Sütun -->
                    <div class="col-lg-4">
                        <!-- Son Kaydolan Kullanıcılar -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Son Kaydolan Kullanıcılar</h6>
                                <a href="users.php" class="btn btn-sm btn-primary">
                                    <i class="bi bi-person-plus"></i> Yeni Ekle
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="list-group">
                                    <?php foreach ($recent_users as $user): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h6>
                                                <small class="text-muted"><?php echo format_date($user['created_at'], 'd.m.Y'); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <small>
                                                    <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                                                </small>
                                            </p>
                                            <div>
                                                <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                                    <?php echo $user['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sistem Bildirimleri -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Sistem Bildirimleri</h6>
                            </div>
                            <div class="card-body">
                                <?php if (count($system_notifications) > 0): ?>
                                    <div class="list-group">
                                        <?php foreach ($system_notifications as $notification): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                    <small class="text-muted"><?php echo format_date($notification['created_at']); ?></small>
                                                </div>
                                                <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center py-3">Sistem bildirimi bulunmuyor.</p>
                                <?php endif; ?>
                                <div class="mt-3">
                                    <a href="broadcast.php" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-megaphone"></i> Yeni Duyuru Oluştur
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>

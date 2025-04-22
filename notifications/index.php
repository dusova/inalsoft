<?php
// notifications/index.php - Bildirim listesi ve ayarları sayfası

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

// Sayfalama için değişkenler
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Bildirim filtreleri
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// SQL sorgusunu oluştur
$sql = "SELECT * FROM notifications WHERE user_id = :user_id";
$params = ['user_id' => $_SESSION['user_id']];

// Filtreleri uygula
if (!empty($filter_type)) {
    $sql .= " AND type = :type";
    $params['type'] = $filter_type;
}

if ($filter_status === 'read') {
    $sql .= " AND is_read = 1";
} elseif ($filter_status === 'unread') {
    $sql .= " AND is_read = 0";
}

// Toplam bildirim sayısını al
$stmt = $db->prepare("SELECT COUNT(*) FROM ($sql) as count_query");
$stmt->execute($params);
$total_notifications = $stmt->fetchColumn();

// Toplam sayfa sayısını hesapla
$total_pages = ceil($total_notifications / $per_page);

// Bildirim listesini al
$sql .= " ORDER BY created_at DESC LIMIT :offset, :per_page";
$stmt = $db->prepare($sql);

// Parametreleri bağla
foreach ($params as $key => $value) {
    $stmt->bindValue(":$key", $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);

$stmt->execute();
$notifications = $stmt->fetchAll();

// Bildirim tercihlerini al
$stmt = $db->prepare("SELECT notification_preference FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$notification_pref = $stmt->fetchColumn();
$notification_settings = $notification_pref ? json_decode($notification_pref, true) : [
    'email_notifications' => true,
    'browser_notifications' => true,
    'project_updates' => true,
    'meeting_reminders' => true,
    'task_assignments' => true,
    'system_announcements' => true
];

// Form işleme - Bildirim Ayarları
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_preferences') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: index.php");
        exit;
    }
    
    // Yeni tercihler
    $new_settings = [
        'email_notifications' => isset($_POST['email_notifications']),
        'browser_notifications' => isset($_POST['browser_notifications']),
        'project_updates' => isset($_POST['project_updates']),
        'meeting_reminders' => isset($_POST['meeting_reminders']),
        'task_assignments' => isset($_POST['task_assignments']),
        'system_announcements' => isset($_POST['system_announcements'])
    ];
    
    // Tercihleri güncelle
    $stmt = $db->prepare("UPDATE users SET notification_preference = :prefs WHERE id = :id");
    $result = $stmt->execute([
        'prefs' => json_encode($new_settings),
        'id' => $_SESSION['user_id']
    ]);
    
    if ($result) {
        $notification_settings = $new_settings;
        $_SESSION['success'] = "Bildirim tercihleriniz başarıyla güncellendi.";
    } else {
        $_SESSION['error'] = "Bildirim tercihleri güncellenirken bir hata oluştu.";
    }
    
    header("Location: index.php");
    exit;
}

// Form işleme - Tüm Bildirimleri Okundu İşaretle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: index.php");
        exit;
    }
    
    // Tüm bildirimleri okundu işaretle
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :user_id");
    $result = $stmt->execute(['user_id' => $_SESSION['user_id']]);
    
    if ($result) {
        $_SESSION['success'] = "Tüm bildirimler okundu olarak işaretlendi.";
    } else {
        $_SESSION['error'] = "Bildirimler işaretlenirken bir hata oluştu.";
    }
    
    header("Location: index.php");
    exit;
}

// Form işleme - Tek Bildirimi Okundu İşaretle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: index.php");
        exit;
    }
    
    $notification_id = intval($_POST['notification_id']);
    
    // Bildirimi okundu işaretle
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id");
    $result = $stmt->execute([
        'id' => $notification_id,
        'user_id' => $_SESSION['user_id']
    ]);
    
    if ($result) {
        $_SESSION['success'] = "Bildirim okundu olarak işaretlendi.";
    } else {
        $_SESSION['error'] = "Bildirim işaretlenirken bir hata oluştu.";
    }
    
    header("Location: index.php");
    exit;
}

// Form işleme - Bildirimi Sil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: index.php");
        exit;
    }
    
    $notification_id = intval($_POST['notification_id']);
    
    // Bildirimi sil
    $stmt = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :user_id");
    $result = $stmt->execute([
        'id' => $notification_id,
        'user_id' => $_SESSION['user_id']
    ]);
    
    if ($result) {
        $_SESSION['success'] = "Bildirim silindi.";
    } else {
        $_SESSION['error'] = "Bildirim silinirken bir hata oluştu.";
    }
    
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Bildirimler</title>
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
                    <h1 class="h2">Bildirimler</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="dropdown me-2">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" id="settingsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i> Ayarlar
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="settingsDropdown">
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#notificationSettingsModal">Bildirim Tercihleri</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form action="index.php" method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="action" value="mark_all_read">
                                        <button type="submit" class="dropdown-item">Tümünü Okundu İşaretle</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="btn-group me-2">
                            <a href="index.php" class="btn btn-sm btn-outline-secondary <?php echo empty($filter_status) && empty($filter_type) ? 'active' : ''; ?>">Tümü</a>
                            <a href="index.php?status=unread" class="btn btn-sm btn-outline-secondary <?php echo $filter_status === 'unread' ? 'active' : ''; ?>">Okunmamış</a>
                            <a href="index.php?status=read" class="btn btn-sm btn-outline-secondary <?php echo $filter_status === 'read' ? 'active' : ''; ?>">Okunmuş</a>
                        </div>
                    </div>
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
                
                <!-- Bildirim filtreleri -->
                <div class="mb-4">
                    <div class="btn-group">
                        <a href="index.php<?php echo $filter_status ? "?status=$filter_status" : ''; ?>" class="btn btn-sm btn-outline-secondary <?php echo empty($filter_type) ? 'active' : ''; ?>">Tüm Türler</a>
                        <a href="index.php?type=project<?php echo $filter_status ? "&status=$filter_status" : ''; ?>" class="btn btn-sm btn-outline-secondary <?php echo $filter_type === 'project' ? 'active' : ''; ?>"><i class="bi bi-folder"></i> Projeler</a>
                        <a href="index.php?type=task<?php echo $filter_status ? "&status=$filter_status" : ''; ?>" class="btn btn-sm btn-outline-secondary <?php echo $filter_type === 'task' ? 'active' : ''; ?>"><i class="bi bi-check-square"></i> Görevler</a>
                        <a href="index.php?type=meeting<?php echo $filter_status ? "&status=$filter_status" : ''; ?>" class="btn btn-sm btn-outline-secondary <?php echo $filter_type === 'meeting' ? 'active' : ''; ?>"><i class="bi bi-people"></i> Toplantılar</a>
                        <a href="index.php?type=system<?php echo $filter_status ? "&status=$filter_status" : ''; ?>" class="btn btn-sm btn-outline-secondary <?php echo $filter_type === 'system' ? 'active' : ''; ?>"><i class="bi bi-gear"></i> Sistem</a>
                    </div>
                </div>
                
                <!-- Bildirim listesi -->
                <div class="card shadow mb-4">
                    <div class="card-body p-0">
                        <?php if (count($notifications) > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="list-group-item list-group-item-action <?php echo $notification['is_read'] ? '' : 'list-group-item-light'; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <div class="d-flex align-items-center">
                                                <div class="notification-icon me-3">
                                                    <?php 
                                                        switch ($notification['type']) {
                                                            case 'project':
                                                                echo '<i class="bi bi-folder text-primary fs-4"></i>';
                                                                break;
                                                            case 'task':
                                                                echo '<i class="bi bi-check-square text-success fs-4"></i>';
                                                                break;
                                                            case 'meeting':
                                                                echo '<i class="bi bi-people text-info fs-4"></i>';
                                                                break;
                                                            default:
                                                                echo '<i class="bi bi-bell text-warning fs-4"></i>';
                                                        }
                                                    ?>
                                                </div>
                                                <div>
                                                    <h5 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h5>
                                                    <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                    <small class="text-muted">
                                                        <i class="bi bi-clock"></i> <?php echo format_date($notification['created_at']); ?>
                                                        <?php if (!$notification['is_read']): ?>
                                                            <span class="badge bg-danger">Yeni</span>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="notification-actions">
                                                <div class="btn-group">
                                                    <?php if (!$notification['is_read']): ?>
                                                        <form action="index.php" method="post">
                                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                            <input type="hidden" name="action" value="mark_read">
                                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Okundu İşaretle">
                                                                <i class="bi bi-check"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($notification['related_id'])): ?>
                                                        <a href="<?php echo get_related_link($notification['type'], $notification['related_id']); ?>" class="btn btn-sm btn-outline-secondary" title="Görüntüle">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <form action="index.php" method="post" onsubmit="return confirm('Bu bildirimi silmek istediğinizden emin misiniz?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Sayfalama -->
                            <?php if ($total_pages > 1): ?>
                                <div class="d-flex justify-content-center my-4">
                                    <nav aria-label="Bildirim sayfaları">
                                        <ul class="pagination">
                                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($filter_type) ? "&type=$filter_type" : ''; ?><?php echo !empty($filter_status) ? "&status=$filter_status" : ''; ?>" aria-label="Önceki">
                                                    <span aria-hidden="true">&laquo;</span>
                                                </a>
                                            </li>
                                            
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($filter_type) ? "&type=$filter_type" : ''; ?><?php echo !empty($filter_status) ? "&status=$filter_status" : ''; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($filter_type) ? "&type=$filter_type" : ''; ?><?php echo !empty($filter_status) ? "&status=$filter_status" : ''; ?>" aria-label="Sonraki">
                                                    <span aria-hidden="true">&raquo;</span>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center p-5">
                                <i class="bi bi-bell-slash fs-1 mb-3 text-muted"></i>
                                <h4>Bildirim Bulunamadı</h4>
                                <p class="text-muted">Seçili filtrelere uygun bildirim bulunmamaktadır.</p>
                                <a href="index.php" class="btn btn-outline-primary">Tüm Bildirimleri Göster</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Bildirim Tercihleri Modalı -->
    <div class="modal fade" id="notificationSettingsModal" tabindex="-1" aria-labelledby="notificationSettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="index.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="save_preferences">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="notificationSettingsModalLabel">Bildirim Tercihleri</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-4">
                            <label class="form-label">Bildirim Kanalları</label>
                            <div class="form-text mb-2">Bildirimleri hangi kanallardan almak istediğinizi seçin.</div>
                            
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="email_notifications" id="email_notifications" <?php echo $notification_settings['email_notifications'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_notifications">
                                    <i class="bi bi-envelope"></i> E-posta Bildirimleri
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="browser_notifications" id="browser_notifications" <?php echo $notification_settings['browser_notifications'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="browser_notifications">
                                    <i class="bi bi-browser-chrome"></i> Tarayıcı Bildirimleri
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Bildirim Türleri</label>
                            <div class="form-text mb-2">Hangi etkinlikler için bildirim almak istediğinizi seçin.</div>
                            
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="project_updates" id="project_updates" <?php echo $notification_settings['project_updates'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="project_updates">
                                    <i class="bi bi-folder"></i> Proje Güncellemeleri
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="meeting_reminders" id="meeting_reminders" <?php echo $notification_settings['meeting_reminders'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="meeting_reminders">
                                    <i class="bi bi-calendar-event"></i> Toplantı Hatırlatıcıları
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="task_assignments" id="task_assignments" <?php echo $notification_settings['task_assignments'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="task_assignments">
                                    <i class="bi bi-check2-square"></i> Görev Atamaları
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="system_announcements" id="system_announcements" <?php echo $notification_settings['system_announcements'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="system_announcements">
                                    <i class="bi bi-megaphone"></i> Sistem Duyuruları
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Tercihleri Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>

<?php
// İlgili öğenin bağlantısını oluşturan yardımcı fonksiyon
function get_related_link($type, $id) {
    switch ($type) {
        case 'project':
            return "../projects/view.php?id=$id";
        case 'task':
            return "../tasks/view.php?id=$id";
        case 'meeting':
            return "../meetings/view.php?id=$id";
        default:
            return "#";
    }
}
?>
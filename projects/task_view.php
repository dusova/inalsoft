<?php
// projects/task_view.php - Proje görevi detay sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Görev ID kontrol et
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Geçersiz görev ID'si.";
    header("Location: index.php");
    exit;
}

$task_id = intval($_GET['id']);

// Kullanıcı verisini al
$user = get_authenticated_user();
$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Görev bilgilerini al
$stmt = $db->prepare("
    SELECT t.*, p.id as project_id, p.name as project_name, 
           u_assigned.full_name as assigned_to_name, 
           u_created.full_name as created_by_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    LEFT JOIN users u_created ON t.created_by = u_created.id
    WHERE t.id = :id
");
$stmt->execute(['id' => $task_id]);
$task = $stmt->fetch();

// Görev bulunamadıysa
if (!$task) {
    $_SESSION['error'] = "Görev bulunamadı.";
    header("Location: index.php");
    exit;
}

$project_id = $task['project_id'];

// Görev aktiviteleri için değişken tanımla
$activities = [];

// Aktivite tablosundaki kolon isimlerini kontrol et
try {
    // Sadece task_id'ye göre filtreleme yaparak aktiviteleri almayı dene
    $stmt = $db->prepare("
        SELECT a.*, u.full_name
        FROM activities a
        JOIN users u ON a.user_id = u.id
        WHERE a.related_id = :task_id
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['task_id' => $task_id]);
    $activities = $stmt->fetchAll();
} catch (Exception $e) {
    // Hata durumunda aktiviteleri gösterme, sessiz bir şekilde devam et
    // Aktiviteler hayati değil, sadece bilgi amaçlı olduğu için
    // $_SESSION['error'] = "Aktiviteler yüklenirken bir hata oluştu: " . $e->getMessage();
}

// Görev silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: task_view.php?id=$task_id");
        exit;
    }
    
    try {
        // Görevi sil
        $stmt = $db->prepare("DELETE FROM tasks WHERE id = :id");
        $result = $stmt->execute(['id' => $task_id]);
        
        if ($result) {
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'delete', 'task', $task_id, "Görev silindi");
            
            $_SESSION['success'] = "Görev başarıyla silindi.";
            header("Location: tasks.php?project_id=$project_id");
            exit;
        } else {
            $_SESSION['error'] = "Görev silinirken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: task_view.php?id=$task_id");
    exit;
}

// Görev durumunu güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: task_view.php?id=$task_id");
        exit;
    }
    
    $new_status = sanitize_input($_POST['status']);
    
    // Geçerli durum kontrolü
    if (!in_array($new_status, ['to_do', 'in_progress', 'review', 'done'])) {
        $_SESSION['error'] = "Geçersiz görev durumu.";
        header("Location: task_view.php?id=$task_id");
        exit;
    }
    
    try {
        // Görevi güncelle
        $stmt = $db->prepare("UPDATE tasks SET status = :status WHERE id = :id");
        $result = $stmt->execute([
            'status' => $new_status,
            'id' => $task_id
        ]);
        
        if ($result) {
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'update', 'task', $task_id, "Görev durumu güncellendi: $new_status");
            
            // Durum tamamlandı ise tamamlanma zamanını güncelle
            if ($new_status === 'done') {
                $stmt = $db->prepare("UPDATE tasks SET completed_at = NOW() WHERE id = :id");
                $stmt->execute(['id' => $task_id]);
            }
            
            $_SESSION['success'] = "Görev durumu başarıyla güncellendi.";
        } else {
            $_SESSION['error'] = "Görev durumu güncellenirken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: task_view.php?id=$task_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Görev Detayı | <?php echo htmlspecialchars($task['title']); ?></title>
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
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Projeler</a></li>
                        <li class="breadcrumb-item"><a href="view.php?id=<?php echo $project_id; ?>"><?php echo htmlspecialchars($task['project_name']); ?></a></li>
                        <li class="breadcrumb-item"><a href="tasks.php?project_id=<?php echo $project_id; ?>">Görevler</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($task['title']); ?></li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?php echo htmlspecialchars($task['title']); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="task_edit.php?id=<?php echo $task_id; ?>" class="btn btn-warning">
                                <i class="bi bi-pencil"></i> Düzenle
                            </a>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteTaskModal">
                                <i class="bi bi-trash"></i> Sil
                            </button>
                        </div>
                        <a href="tasks.php?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Görevlere Dön
                        </a>
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
                
                <!-- Görev Detayları -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card shadow h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Görev Bilgileri</h5>
                                <div>
                                    <span class="badge bg-<?php 
                                        switch($task['status']) {
                                            case 'to_do': echo 'secondary'; break;
                                            case 'in_progress': echo 'primary'; break;
                                            case 'review': echo 'warning'; break;
                                            case 'done': echo 'success'; break;
                                            default: echo 'light'; break;
                                        }
                                    ?>">
                                        <?php 
                                            switch($task['status']) {
                                                case 'to_do': echo 'Yapılacak'; break;
                                                case 'in_progress': echo 'Devam Ediyor'; break;
                                                case 'review': echo 'İncelemede'; break;
                                                case 'done': echo 'Tamamlandı'; break;
                                                default: echo $task['status']; break;
                                            }
                                        ?>
                                    </span>
                                    <span class="badge bg-<?php 
                                        switch($task['priority']) {
                                            case 'low': echo 'success'; break;
                                            case 'medium': echo 'info'; break;
                                            case 'high': echo 'warning'; break;
                                            case 'urgent': echo 'danger'; break;
                                            default: echo 'light'; break;
                                        }
                                    ?>">
                                        <?php 
                                            switch($task['priority']) {
                                                case 'low': echo 'Düşük'; break;
                                                case 'medium': echo 'Orta'; break;
                                                case 'high': echo 'Yüksek'; break;
                                                case 'urgent': echo 'Acil'; break;
                                                default: echo $task['priority']; break;
                                            }
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="mb-4">
                                    <h5>Açıklama</h5>
                                    <p><?php echo nl2br(htmlspecialchars($task['description'] ?: 'Açıklama bulunmamaktadır.')); ?></p>
                                </div>
                                
                                <h5>Durum Değiştir</h5>
                                <form action="task_view.php?id=<?php echo $task_id; ?>" method="post" class="mb-4">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="update_task_status">
                                    
                                    <div class="input-group">
                                        <select class="form-select" name="status">
                                            <option value="to_do" <?php echo $task['status'] === 'to_do' ? 'selected' : ''; ?>>Yapılacak</option>
                                            <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>Devam Ediyor</option>
                                            <option value="review" <?php echo $task['status'] === 'review' ? 'selected' : ''; ?>>İncelemede</option>
                                            <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Tamamlandı</option>
                                        </select>
                                        <button class="btn btn-primary" type="submit">Durumu Güncelle</button>
                                    </div>
                                </form>
                                
                                <?php if (!empty($activities)): ?>
                                <div class="mt-4">
                                    <h5>Son Aktiviteler</h5>
                                    <ul class="list-group">
                                        <?php foreach ($activities as $activity): ?>
                                            <li class="list-group-item">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($activity['full_name']); ?></h6>
                                                    <small><?php echo format_date($activity['created_at'], 'd.m.Y H:i'); ?></small>
                                                </div>
                                                <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card shadow h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Detaylar</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Proje</span>
                                        <a href="view.php?id=<?php echo $project_id; ?>"><?php echo htmlspecialchars($task['project_name']); ?></a>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Atanan Kişi</span>
                                        <span><?php echo $task['assigned_to'] ? htmlspecialchars($task['assigned_to_name']) : '<span class="text-muted">Atanmamış</span>'; ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Oluşturan</span>
                                        <span><?php echo htmlspecialchars($task['created_by_name']); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Başlangıç Tarihi</span>
                                        <span><?php echo format_date($task['start_date'], 'd.m.Y'); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Bitiş Tarihi</span>
                                        <span>
                                            <?php 
                                                $due_date = new DateTime($task['due_date']);
                                                $now = new DateTime();
                                                $is_overdue = ($task['status'] !== 'done' && $due_date < $now);
                                                
                                                echo '<span class="' . ($is_overdue ? 'text-danger fw-bold' : '') . '">';
                                                echo format_date($task['due_date'], 'd.m.Y');
                                                echo '</span>';
                                                
                                                if ($is_overdue) {
                                                    echo ' <span class="badge bg-danger">Gecikti</span>';
                                                }
                                            ?>
                                        </span>
                                    </li>
                                    <?php if ($task['status'] === 'done' && $task['completed_at']): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Tamamlanma Tarihi</span>
                                        <span><?php echo format_date($task['completed_at'], 'd.m.Y H:i'); ?></span>
                                    </li>
                                    <?php endif; ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Oluşturulma Tarihi</span>
                                        <span><?php echo format_date($task['created_at'], 'd.m.Y H:i'); ?></span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Görev Silme Modalı -->
    <div class="modal fade" id="deleteTaskModal" tabindex="-1" aria-labelledby="deleteTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="task_view.php?id=<?php echo $task_id; ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_task">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteTaskModalLabel">Görevi Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Uyarı:</span> <strong><?php echo htmlspecialchars($task['title']); ?></strong> görevini silmek istediğinizden emin misiniz?</p>
                        <p>Bu işlem geri alınamaz.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Görevi Sil</button>
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
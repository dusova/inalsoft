
<?php
// projects/tasks.php - Proje görevleri sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Proje ID kontrol et
if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    $_SESSION['error'] = "Geçersiz proje ID'si.";
    header("Location: index.php");
    exit;
}

$project_id = intval($_GET['project_id']);

// Kullanıcı verisini al
$user = get_authenticated_user();
$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Proje bilgilerini al
$stmt = $db->prepare("
    SELECT p.*, pc.name as category_name, u.full_name as created_by_name
    FROM projects p
    LEFT JOIN project_categories pc ON p.category = pc.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.id = :id
");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

// Proje bulunamadıysa
if (!$project) {
    $_SESSION['error'] = "Proje bulunamadı.";
    header("Location: index.php");
    exit;
}

// Görevleri al
$stmt = $db->prepare("
    SELECT t.*, u.full_name as assigned_to_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.project_id = :project_id
    ORDER BY t.due_date ASC
");
$stmt->execute(['project_id' => $project_id]);
$tasks = $stmt->fetchAll();

// Kullanıcı listesini al (görev atama için)
$stmt = $db->prepare("SELECT id, full_name FROM users ORDER BY full_name ASC");
$stmt->execute();
$users = $stmt->fetchAll();

// Yeni görev ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_task') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: tasks.php?project_id=$project_id");
        exit;
    }
    
    // Form verilerini al
    $title = sanitize_input($_POST['title']);
    $description = sanitize_input($_POST['description'] ?? '');
    $status = sanitize_input($_POST['status']);
    $priority = sanitize_input($_POST['priority']);
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $start_date = sanitize_input($_POST['start_date']);
    $due_date = sanitize_input($_POST['due_date']);
    
    // Zorunlu alanları kontrol et
    if (empty($title) || empty($status) || empty($priority) || empty($start_date) || empty($due_date)) {
        $_SESSION['error'] = "Lütfen tüm zorunlu alanları doldurun.";
        header("Location: tasks.php?project_id=$project_id");
        exit;
    }
    
    // Tarih formatını kontrol et
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $due_date)) {
        $_SESSION['error'] = "Geçersiz tarih formatı.";
        header("Location: tasks.php?project_id=$project_id");
        exit;
    }
    
    // Bitiş tarihi başlangıç tarihinden önce olamaz
    if (strtotime($due_date) < strtotime($start_date)) {
        $_SESSION['error'] = "Bitiş tarihi başlangıç tarihinden önce olamaz.";
        header("Location: tasks.php?project_id=$project_id");
        exit;
    }
    
    try {
        // Görevi ekle
        $stmt = $db->prepare("
            INSERT INTO tasks (project_id, title, description, status, priority, assigned_to, start_date, due_date, created_by, created_at) 
            VALUES (:project_id, :title, :description, :status, :priority, :assigned_to, :start_date, :due_date, :created_by, NOW())
        ");
        
        $result = $stmt->execute([
            'project_id' => $project_id,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'assigned_to' => $assigned_to,
            'start_date' => $start_date,
            'due_date' => $due_date,
            'created_by' => $_SESSION['user_id']
        ]);
        
        if ($result) {
            $task_id = $db->lastInsertId();
            
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'create', 'task', $task_id, "Yeni görev oluşturuldu: $title");
            
            // Atanan kişiye bildirim gönder
            if ($assigned_to && $assigned_to != $_SESSION['user_id']) {
                create_notification(
                    $assigned_to,
                    "Yeni Görev Atandı",
                    "{$_SESSION['full_name']} tarafından size '{$title}' başlıklı yeni bir görev atandı.",
                    'task',
                    $task_id
                );
            }
            
            $_SESSION['success'] = "Görev başarıyla oluşturuldu.";
        } else {
            $_SESSION['error'] = "Görev oluşturulurken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: tasks.php?project_id=$project_id");
    exit;
}

// Görev durumunu güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: tasks.php?project_id=$project_id");
        exit;
    }
    
    $task_id = intval($_POST['task_id']);
    $new_status = sanitize_input($_POST['status']);
    
    // Geçerli durum kontrolü
    if (!in_array($new_status, ['to_do', 'in_progress', 'review', 'done'])) {
        $_SESSION['error'] = "Geçersiz görev durumu.";
        header("Location: tasks.php?project_id=$project_id");
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
    
    header("Location: tasks.php?project_id=$project_id");
    exit;
}

// Görev silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: tasks.php?project_id=$project_id");
        exit;
    }
    
    $task_id = intval($_POST['task_id']);
    
    try {
        // Görevi sil
        $stmt = $db->prepare("DELETE FROM tasks WHERE id = :id AND project_id = :project_id");
        $result = $stmt->execute([
            'id' => $task_id,
            'project_id' => $project_id
        ]);
        
        if ($result) {
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'delete', 'task', $task_id, "Görev silindi");
            
            $_SESSION['success'] = "Görev başarıyla silindi.";
        } else {
            $_SESSION['error'] = "Görev silinirken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: tasks.php?project_id=$project_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - <?php echo htmlspecialchars($project['name']); ?> | Görevler</title>
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
                        <li class="breadcrumb-item"><a href="view.php?id=<?php echo $project_id; ?>"><?php echo htmlspecialchars($project['name']); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Görevler</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?php echo htmlspecialchars($project['name']); ?> - Görevler</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                            <i class="bi bi-plus-lg"></i> Yeni Görev
                        </button>
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
                
                <!-- Görev Filtreleri -->
                <div class="card shadow mb-4">
                    <div class="card-body py-3">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <div class="btn-group" role="group" aria-label="Durum Filtresi">
                                    <button type="button" class="btn btn-outline-primary filter-btn active" data-filter="all">Tümü</button>
                                    <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="to_do">Yapılacak</button>
                                    <button type="button" class="btn btn-outline-primary filter-btn" data-filter="in_progress">Devam Ediyor</button>
                                    <button type="button" class="btn btn-outline-warning filter-btn" data-filter="review">İncelemede</button>
                                    <button type="button" class="btn btn-outline-success filter-btn" data-filter="done">Tamamlandı</button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="btn-group" role="group" aria-label="Öncelik Filtresi">
                                    <button type="button" class="btn btn-outline-primary priority-btn active" data-filter="all">Tüm Öncelikler</button>
                                    <button type="button" class="btn btn-outline-success priority-btn" data-filter="low">Düşük</button>
                                    <button type="button" class="btn btn-outline-info priority-btn" data-filter="medium">Orta</button>
                                    <button type="button" class="btn btn-outline-warning priority-btn" data-filter="high">Yüksek</button>
                                    <button type="button" class="btn btn-outline-danger priority-btn" data-filter="urgent">Acil</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Görevler Tablosu -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="tasksTable">
                                <thead>
                                    <tr>
                                        <th>Başlık</th>
                                        <th>Durum</th>
                                        <th>Öncelik</th>
                                        <th>Atanan</th>
                                        <th>Başlangıç</th>
                                        <th>Bitiş</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($tasks) > 0): ?>
                                        <?php foreach ($tasks as $task): ?>
                                            <tr class="task-row" 
                                                data-status="<?php echo $task['status']; ?>" 
                                                data-priority="<?php echo $task['priority']; ?>">
                                                <td>
                                                    <a href="#" class="task-link" data-bs-toggle="modal" data-bs-target="#taskDetailModal" 
                                                       data-task-id="<?php echo $task['id']; ?>"
                                                       data-task-title="<?php echo htmlspecialchars($task['title']); ?>"
                                                       data-task-description="<?php echo htmlspecialchars($task['description']); ?>"
                                                       data-task-assigned="<?php echo htmlspecialchars($task['assigned_to_name'] ?? 'Atanmamış'); ?>">
                                                        <?php echo htmlspecialchars($task['title']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <select class="form-select form-select-sm task-status-select" data-task-id="<?php echo $task['id']; ?>">
                                                        <option value="to_do" <?php echo $task['status'] === 'to_do' ? 'selected' : ''; ?>>Yapılacak</option>
                                                        <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>Devam Ediyor</option>
                                                        <option value="review" <?php echo $task['status'] === 'review' ? 'selected' : ''; ?>>İncelemede</option>
                                                        <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Tamamlandı</option>
                                                    </select>
                                                </td>
                                                <td>
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
                                                </td>
                                                <td><?php echo $task['assigned_to'] ? htmlspecialchars($task['assigned_to_name']) : '<span class="text-muted">Atanmamış</span>'; ?></td>
                                                <td><?php echo format_date($task['start_date'], 'd.m.Y'); ?></td>
                                                <td>
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
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-info task-link" data-bs-toggle="modal" data-bs-target="#taskDetailModal" 
                                                           data-task-id="<?php echo $task['id']; ?>"
                                                           data-task-title="<?php echo htmlspecialchars($task['title']); ?>"
                                                           data-task-description="<?php echo htmlspecialchars($task['description']); ?>"
                                                           data-task-assigned="<?php echo htmlspecialchars($task['assigned_to_name'] ?? 'Atanmamış'); ?>">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a href="task_edit.php?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-warning">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-danger delete-task-btn" data-bs-toggle="modal" data-bs-target="#deleteTaskModal" data-task-id="<?php echo $task['id']; ?>" data-task-title="<?php echo htmlspecialchars($task['title']); ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center">Bu proje için henüz görev oluşturulmamış.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Yeni Görev Modalı -->
    <div class="modal fade" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="tasks.php?project_id=<?php echo $project_id; ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_task">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="addTaskModalLabel">Yeni Görev Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="title" class="form-label">Görev Başlığı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status" class="form-label">Durum <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="to_do">Yapılacak</option>
                                    <option value="in_progress">Devam Ediyor</option>
                                    <option value="review">İncelemede</option>
                                    <option value="done">Tamamlandı</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="assigned_to" class="form-label">Atanan Kişi</label>
                                <select class="form-select" id="assigned_to" name="assigned_to">
                                    <option value="">Seçiniz</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="priority" class="form-label">Öncelik <span class="text-danger">*</span></label>
                                <select class="form-select" id="priority" name="priority" required>
                                    <option value="low">Düşük</option>
                                    <option value="medium" selected>Orta</option>
                                    <option value="high">Yüksek</option>
                                    <option value="urgent">Acil</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="start_date" class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="due_date" class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="due_date" name="due_date" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Görevi Ekle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Görev Detay Modalı -->
    <div class="modal fade" id="taskDetailModal" tabindex="-1" aria-labelledby="taskDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskDetailModalLabel">Görev Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <h5 id="task-detail-title"></h5>
                    <p class="mb-3">
                        <strong>Atanan:</strong> <span id="task-detail-assigned"></span>
                    </p>
                    <h6>Açıklama:</h6>
                    <p id="task-detail-description"></p>
                </div>
                <div class="modal-footer">
                    <a href="#" id="task-edit-link" class="btn btn-warning">Düzenle</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Görev Silme Modalı -->
    <div class="modal fade" id="deleteTaskModal" tabindex="-1" aria-labelledby="deleteTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="tasks.php?project_id=<?php echo $project_id; ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_task">
                    <input type="hidden" name="task_id" id="delete_task_id" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteTaskModalLabel">Görevi Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Uyarı:</span> <span id="delete_task_title"></span> görevini silmek istediğinizden emin misiniz?</p>
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
    
    <!-- Görev Durumu Güncelleme Formu -->
    <form id="taskStatusForm" action="tasks.php?project_id=<?php echo $project_id; ?>" method="post" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="action" value="update_task_status">
        <input type="hidden" name="task_id" id="update_task_id" value="">
        <input type="hidden" name="status" id="update_task_status" value="">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/notifications.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Görev durum değişikliği
            const statusSelects = document.querySelectorAll('.task-status-select');
            statusSelects.forEach(select => {
                select.addEventListener('change', function() {
                    const taskId = this.getAttribute('data-task-id');
                    const newStatus = this.value;
                    
                    document.getElementById('update_task_id').value = taskId;
                    document.getElementById('update_task_status').value = newStatus;
                    document.getElementById('taskStatusForm').submit();
                });
            });
            
            // Görev silme modalını doldur
            const deleteTaskBtns = document.querySelectorAll('.delete-task-btn');
            deleteTaskBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const taskId = this.getAttribute('data-task-id');
                    const taskTitle = this.getAttribute('data-task-title');
                    
                    document.getElementById('delete_task_id').value = taskId;
                    document.getElementById('delete_task_title').textContent = taskTitle;
                });
            });
            
            // Görev detay modalını doldur
            const taskLinks = document.querySelectorAll('.task-link');
            taskLinks.forEach(link => {
                link.addEventListener('click', function() {
                    const taskId = this.getAttribute('data-task-id');
                    const taskTitle = this.getAttribute('data-task-title');
                    const taskDescription = this.getAttribute('data-task-description');
                    const taskAssigned = this.getAttribute('data-task-assigned');
                    
                    document.getElementById('task-detail-title').textContent = taskTitle;
                    document.getElementById('task-detail-description').textContent = taskDescription || 'Açıklama yok';
                    document.getElementById('task-detail-assigned').textContent = taskAssigned;
                    document.getElementById('task-edit-link').href = 'task_edit.php?id=' + taskId;
                });
            });
            
            // Durum filtresi
            const filterButtons = document.querySelectorAll('.filter-btn');
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Aktif butonu değiştir
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Filtreleme yap
                    const filter = this.getAttribute('data-filter');
                    filterTasks();
                });
            });
            
            // Öncelik filtresi
            const priorityButtons = document.querySelectorAll('.priority-btn');
            priorityButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Aktif butonu değiştir
                    priorityButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Filtreleme yap
                    const filter = this.getAttribute('data-filter');
                    filterTasks();
                });
            });
            
            // Görevleri filtrele
            function filterTasks() {
                const statusFilter = document.querySelector('.filter-btn.active').getAttribute('data-filter');
                const priorityFilter = document.querySelector('.priority-btn.active').getAttribute('data-filter');
                
                const rows = document.querySelectorAll('.task-row');
                rows.forEach(row => {
                    const status = row.getAttribute('data-status');
                    const priority = row.getAttribute('data-priority');
                    
                    const statusMatch = statusFilter === 'all' || status === statusFilter;
                    const priorityMatch = priorityFilter === 'all' || priority === priorityFilter;
                    
                    if (statusMatch && priorityMatch) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
            
            // Bugünün tarihini varsayılan olarak ayarla
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('start_date').value = today;
            document.getElementById('start_date').min = today;
            document.getElementById('due_date').min = today;
            
            // Başlangıç tarihi değiştiğinde bitiş tarihini güncelle
            document.getElementById('start_date').addEventListener('change', function() {
                document.getElementById('due_date').min = this.value;
                
                // Bitiş tarihi başlangıç tarihinden önce ise başlangıç tarihini ata
                const dueDate = document.getElementById('due_date');
                if (dueDate.value && dueDate.value < this.value) {
                    dueDate.value = this.value;
                }
            });
        });
    </script>
</body>
</html>
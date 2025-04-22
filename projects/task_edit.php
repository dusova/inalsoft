<?php
// projects/task_edit.php - Proje görevi düzenleme sayfası

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
    SELECT t.*, p.id as project_id, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
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

// Kullanıcı listesini al (görev atama için)
$stmt = $db->prepare("SELECT id, full_name FROM users ORDER BY full_name ASC");
$stmt->execute();
$users = $stmt->fetchAll();

// Görev güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: task_edit.php?id=$task_id");
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
        header("Location: task_edit.php?id=$task_id");
        exit;
    }
    
    // Tarih formatını kontrol et
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $due_date)) {
        $_SESSION['error'] = "Geçersiz tarih formatı.";
        header("Location: task_edit.php?id=$task_id");
        exit;
    }
    
    // Bitiş tarihi başlangıç tarihinden önce olamaz
    if (strtotime($due_date) < strtotime($start_date)) {
        $_SESSION['error'] = "Bitiş tarihi başlangıç tarihinden önce olamaz.";
        header("Location: task_edit.php?id=$task_id");
        exit;
    }
    
    try {
        // Görevi güncelle
        $stmt = $db->prepare("
            UPDATE tasks SET 
                title = :title,
                description = :description,
                status = :status,
                priority = :priority,
                assigned_to = :assigned_to,
                start_date = :start_date,
                due_date = :due_date,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $result = $stmt->execute([
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'assigned_to' => $assigned_to,
            'start_date' => $start_date,
            'due_date' => $due_date,
            'id' => $task_id
        ]);
        
        if ($result) {
            // Durum tamamlandı ise tamamlanma zamanını güncelle
            if ($status === 'done' && $task['status'] !== 'done') {
                $stmt = $db->prepare("UPDATE tasks SET completed_at = NOW() WHERE id = :id");
                $stmt->execute(['id' => $task_id]);
            } else if ($status !== 'done' && $task['status'] === 'done') {
                $stmt = $db->prepare("UPDATE tasks SET completed_at = NULL WHERE id = :id");
                $stmt->execute(['id' => $task_id]);
            }
            
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'update', 'task', $task_id, "Görev güncellendi: $title");
            
            // Atanan kişi değiştiyse bildirim gönder
            if ($assigned_to && $assigned_to != $task['assigned_to'] && $assigned_to != $_SESSION['user_id']) {
                create_notification(
                    $assigned_to,
                    "Göreve Atandınız",
                    "{$_SESSION['full_name']} tarafından '{$title}' başlıklı göreve atandınız.",
                    'task',
                    $task_id
                );
            }
            
            $_SESSION['success'] = "Görev başarıyla güncellendi.";
            header("Location: task_view.php?id=$task_id");
            exit;
        } else {
            $_SESSION['error'] = "Görev güncellenirken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: task_edit.php?id=$task_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Görev Düzenle | <?php echo htmlspecialchars($task['title']); ?></title>
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
                        <li class="breadcrumb-item"><a href="task_view.php?id=<?php echo $task_id; ?>"><?php echo htmlspecialchars($task['title']); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Düzenle</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Görev Düzenle</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="task_view.php?id=<?php echo $task_id; ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Göreve Dön
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
                
                <!-- Görev Düzenleme Formu -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Görev Bilgilerini Düzenle</h5>
                    </div>
                    <div class="card-body">
                        <form action="task_edit.php?id=<?php echo $task_id; ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_task">
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="title" class="form-label">Görev Başlığı <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($task['title']); ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="status" class="form-label">Durum <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="to_do" <?php echo $task['status'] === 'to_do' ? 'selected' : ''; ?>>Yapılacak</option>
                                        <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>Devam Ediyor</option>
                                        <option value="review" <?php echo $task['status'] === 'review' ? 'selected' : ''; ?>>İncelemede</option>
                                        <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>Tamamlandı</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($task['description'] ?: ''); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="assigned_to" class="form-label">Atanan Kişi</label>
                                    <select class="form-select" id="assigned_to" name="assigned_to">
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?php echo $u['id']; ?>" <?php echo $task['assigned_to'] == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="priority" class="form-label">Öncelik <span class="text-danger">*</span></label>
                                    <select class="form-select" id="priority" name="priority" required>
                                        <option value="low" <?php echo $task['priority'] === 'low' ? 'selected' : ''; ?>>Düşük</option>
                                        <option value="medium" <?php echo $task['priority'] === 'medium' ? 'selected' : ''; ?>>Orta</option>
                                        <option value="high" <?php echo $task['priority'] === 'high' ? 'selected' : ''; ?>>Yüksek</option>
                                        <option value="urgent" <?php echo $task['priority'] === 'urgent' ? 'selected' : ''; ?>>Acil</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_date" class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $task['start_date']; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="due_date" class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo $task['due_date']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="task_view.php?id=<?php echo $task_id; ?>" class="btn btn-secondary me-md-2">İptal</a>
                                <button type="submit" class="btn btn-primary">Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/notifications.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
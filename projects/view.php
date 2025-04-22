
<?php
// projects/view.php - Proje detay sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Proje ID kontrol et
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Geçersiz proje ID'si.";
    header("Location: index.php");
    exit;
}

$project_id = intval($_GET['id']);

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

// Projeye ait görevleri al
$stmt = $db->prepare("
    SELECT t.*, u.full_name as assigned_to_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.project_id = :project_id
    ORDER BY t.due_date ASC
");
$stmt->execute(['project_id' => $project_id]);
$tasks = $stmt->fetchAll();

// Proje ekibini al (görevlerde yer alan kullanıcılar)
$stmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.profile_image
    FROM users u
    INNER JOIN tasks t ON u.id = t.assigned_to
    WHERE t.project_id = :project_id
    UNION
    SELECT u.id, u.full_name, u.profile_image
    FROM users u
    WHERE u.id = :created_by
");
$stmt->execute([
    'project_id' => $project_id,
    'created_by' => $project['created_by']
]);
$team_members = $stmt->fetchAll();

// Projeye ait yorumları al
$stmt = $db->prepare("
    SELECT c.*, u.full_name, u.profile_image
    FROM comments c
    JOIN users u ON c.created_by = u.id
    WHERE c.entity_type = 'project' AND c.entity_id = :project_id
    ORDER BY c.created_at DESC
");
$stmt->execute(['project_id' => $project_id]);
$comments = $stmt->fetchAll();

// Projeye ait dosyaları al
$stmt = $db->prepare("
    SELECT f.*, u.full_name as uploaded_by_name
    FROM files f
    JOIN users u ON f.uploaded_by = u.id
    WHERE f.entity_type = 'project' AND f.entity_id = :project_id
    ORDER BY f.uploaded_at DESC
");
$stmt->execute(['project_id' => $project_id]);
$files = $stmt->fetchAll();

// Yorum ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: view.php?id=$project_id");
        exit;
    }
    
    $comment = sanitize_input($_POST['comment']);
    
    if (empty($comment)) {
        $_SESSION['error'] = "Yorum boş olamaz.";
        header("Location: view.php?id=$project_id");
        exit;
    }
    
    try {
        // Yorumu ekle
        $stmt = $db->prepare("
            INSERT INTO comments (content, entity_type, entity_id, created_by, created_at) 
            VALUES (:content, 'project', :entity_id, :created_by, NOW())
        ");
        
        $result = $stmt->execute([
            'content' => $comment,
            'entity_id' => $project_id,
            'created_by' => $_SESSION['user_id']
        ]);
        
        if ($result) {
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'comment', 'project', $project_id, "Yorum eklendi");
            
            // Proje sahibine bildirim gönder (kendisi değilse)
            if ($project['created_by'] != $_SESSION['user_id']) {
                create_notification(
                    $project['created_by'],
                    "Projenize yorum yapıldı",
                    "{$_SESSION['full_name']} tarafından '{$project['name']}' projesine yorum yapıldı.",
                    'project',
                    $project_id
                );
            }
            
            $_SESSION['success'] = "Yorum başarıyla eklendi.";
        } else {
            $_SESSION['error'] = "Yorum eklenirken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: view.php?id=$project_id");
    exit;
}

// Dosya yükleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_file') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: view.php?id=$project_id");
        exit;
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Dosya yüklenirken bir hata oluştu.";
        header("Location: view.php?id=$project_id");
        exit;
    }
    
    $file = $_FILES['file'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    // Dosya boyutu kontrolü
    if ($file['size'] > $max_size) {
        $_SESSION['error'] = "Dosya boyutu çok büyük. Maksimum dosya boyutu: 10MB";
        header("Location: view.php?id=$project_id");
        exit;
    }
    
    // Dosya uzantısını al
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Dosya adını güvenli hale getir
    $safe_filename = time() . '_' . preg_replace('/[^a-z0-9_.-]/i', '_', $file['name']);
    
    // Dosya yolunu belirle
    $upload_dir = '../uploads/project_files/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $upload_path = $upload_dir . $safe_filename;
    
    try {
        // Dosyayı yükle
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Veritabanına kaydet
            $stmt = $db->prepare("
                INSERT INTO files (filename, filepath, filesize, filetype, entity_type, entity_id, uploaded_by, uploaded_at)
                VALUES (:filename, :filepath, :filesize, :filetype, 'project', :entity_id, :uploaded_by, NOW())
            ");
            
            $result = $stmt->execute([
                'filename' => $file['name'],
                'filepath' => 'uploads/project_files/' . $safe_filename,
                'filesize' => $file['size'],
                'filetype' => $file['type'],
                'entity_id' => $project_id,
                'uploaded_by' => $_SESSION['user_id']
            ]);
            
            if ($result) {
                // Aktiviteyi logla
                log_activity($_SESSION['user_id'], 'upload', 'project', $project_id, "Dosya yüklendi: {$file['name']}");
                
                $_SESSION['success'] = "Dosya başarıyla yüklendi.";
            } else {
                $_SESSION['error'] = "Dosya yüklenirken bir hata oluştu.";
            }
        } else {
            $_SESSION['error'] = "Dosya taşınırken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: view.php?id=$project_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - <?php echo htmlspecialchars($project['name']); ?></title>
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
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($project['name']); ?></li>
                    </ol>
                </nav>
                
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
                
                <!-- Proje Başlığı ve Butonlar -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?php echo htmlspecialchars($project['name']); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="tasks.php?project_id=<?php echo $project_id; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-list-check"></i> Görevler
                            </a>
                            <a href="edit.php?id=<?php echo $project_id; ?>" class="btn btn-sm btn-warning">
                                <i class="bi bi-pencil"></i> Düzenle
                            </a>
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteProjectModal">
                            <i class="bi bi-trash"></i> Sil
                        </button>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Sol sütun: Proje bilgileri -->
                    <div class="col-md-8">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Proje Bilgileri</h6>
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
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p><strong>Kategori:</strong> <?php echo htmlspecialchars($project['category_name']); ?></p>
                                        <p><strong>Öncelik:</strong> 
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
                                        </p>
                                        <p><strong>Oluşturan:</strong> <?php echo htmlspecialchars($project['created_by_name']); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Başlangıç Tarihi:</strong> <?php echo format_date($project['start_date'], 'd.m.Y'); ?></p>
                                        <p><strong>Bitiş Tarihi:</strong> <?php echo format_date($project['due_date'], 'd.m.Y'); ?></p>
                                        <p><strong>Oluşturulma Tarihi:</strong> <?php echo format_date($project['created_at']); ?></p>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <h6>Açıklama:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                                </div>
                                
                                <!-- İlerleme çubuğu -->
                                <?php
                                    // Proje süresi ve geçen süre hesaplaması
                                    $start = new DateTime($project['start_date']);
                                    $end = new DateTime($project['due_date']);
                                    $now = new DateTime();
                                    
                                    $total_days = $start->diff($end)->days;
                                    $days_passed = $start->diff($now)->days;
                                    
                                    // İlerleyeme yüzdesi (0-100 arasında)
                                    $progress = $total_days > 0 ? min(100, max(0, ($days_passed / $total_days) * 100)) : 0;
                                    
                                    // Renk belirleme
                                    $progress_color = 'success';
                                    if ($progress > 80) {
                                        $progress_color = 'danger';
                                    } elseif ($progress > 60) {
                                        $progress_color = 'warning';
                                    } elseif ($progress > 40) {
                                        $progress_color = 'info';
                                    }
                                ?>
                                <div class="mb-1">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Proje İlerlemesi</span>
                                        <span><?php echo round($progress); ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-<?php echo $progress_color; ?>" role="progressbar" 
                                             style="width: <?php echo $progress; ?>%" 
                                             aria-valuenow="<?php echo $progress; ?>" 
                                             aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Görevler -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Görevler</h6>
                                <a href="tasks.php?project_id=<?php echo $project_id; ?>" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg"></i> Yeni Görev
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (count($tasks) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th width="40%">Başlık</th>
                                                    <th>Atanan</th>
                                                    <th>Durum</th>
                                                    <th>Termin</th>
                                                    <th>İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tasks as $task): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($task['title']); ?></td>
                                                        <td><?php echo $task['assigned_to'] ? htmlspecialchars($task['assigned_to_name']) : '-'; ?></td>
                                                        <td>
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
                                                        </td>
                                                        <td><?php echo format_date($task['due_date'], 'd.m.Y'); ?></td>
                                                        <td>
                                                            <a href="task_view.php?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-primary">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i> Bu proje için henüz görev oluşturulmamış.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Yorumlar -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Yorumlar</h6>
                            </div>
                            <div class="card-body">
                                <!-- Yorum listesi -->
                                <div class="comments-list mb-4">
                                    <?php if (count($comments) > 0): ?>
                                        <?php foreach ($comments as $comment): ?>
                                            <div class="comment-item mb-3">
                                                <div class="d-flex">
                                                    <div class="flex-shrink-0">
                                                        <img src="../<?php echo htmlspecialchars($comment['profile_image']); ?>" class="rounded-circle" width="40" height="40" alt="Profil">
                                                    </div>
                                                    <div class="flex-grow-1 ms-3">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($comment['full_name']); ?></h6>
                                                            <small class="text-muted"><?php echo format_date($comment['created_at']); ?></small>
                                                        </div>
                                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-3">
                                            <p class="text-muted">Henüz yorum yapılmamış. İlk yorumu siz yapın!</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Yorum formu -->
                                <form action="view.php?id=<?php echo $project_id; ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="add_comment">
                                    
                                    <div class="mb-3">
                                        <label for="comment" class="form-label">Yorum Ekle</label>
                                        <textarea class="form-control" id="comment" name="comment" rows="3" required></textarea>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">Yorumu Gönder</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sağ sütun: Ekip ve dosyalar -->
                    <div class="col-md-4">
                        <!-- Proje ekibi -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Proje Ekibi</h6>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php if (count($team_members) > 0): ?>
                                        <?php foreach ($team_members as $member): ?>
                                            <li class="list-group-item d-flex align-items-center">
                                                <img src="../<?php echo htmlspecialchars($member['profile_image']); ?>" class="rounded-circle me-2" width="32" height="32" alt="Profil">
                                                <span><?php echo htmlspecialchars($member['full_name']); ?></span>
                                                <?php if ($member['id'] == $project['created_by']): ?>
                                                    <span class="badge bg-primary ms-auto">Proje Sahibi</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="list-group-item text-center">Henüz ekip üyesi yok.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Dosyalar -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Dosyalar</h6>
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadFileModal">
                                    <i class="bi bi-upload"></i> Yükle
                                </button>
                            </div>
                            <div class="card-body">
                                <?php if (count($files) > 0): ?>
                                    <ul class="list-group">
                                        <?php foreach ($files as $file): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi <?php echo get_file_icon($file['filetype']); ?>"></i>
                                                    <a href="../<?php echo htmlspecialchars($file['filepath']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars($file['filename']); ?>
                                                    </a>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo format_file_size($file['filesize']); ?> - 
                                                        <?php echo format_date($file['uploaded_at']); ?> - 
                                                        <?php echo htmlspecialchars($file['uploaded_by_name']); ?>
                                                    </small>
                                                </div>
                                                <a href="../<?php echo htmlspecialchars($file['filepath']); ?>" download class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <p class="text-muted">Henüz dosya yüklenmemiş.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Proje Silme Modalı -->
    <div class="modal fade" id="deleteProjectModal" tabindex="-1" aria-labelledby="deleteProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="delete_project.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteProjectModalLabel">Projeyi Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Uyarı:</span> Bu projeyi silmek istediğinizden emin misiniz?</p>
                        <p>Bu işlem geri alınamaz ve projeye ait tüm görevler, dosyalar ve yorumlar silinecektir.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Projeyi Sil</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Dosya Yükleme Modalı -->
    <div class="modal fade" id="uploadFileModal" tabindex="-1" aria-labelledby="uploadFileModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="view.php?id=<?php echo $project_id; ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="upload_file">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="uploadFileModalLabel">Dosya Yükle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="file" class="form-label">Dosya Seçin</label>
                            <input class="form-control" type="file" id="file" name="file" required>
                            <div class="form-text">Maksimum dosya boyutu: 10MB</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Yükle</button>
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
// Helper fonksiyonlar
function get_file_icon($filetype) {
    if (strpos($filetype, 'image/') !== false) {
        return 'bi-file-image';
    } elseif (strpos($filetype, 'text/') !== false) {
        return 'bi-file-text';
    } elseif (strpos($filetype, 'pdf') !== false) {
        return 'bi-file-pdf';
    } elseif (strpos($filetype, 'spreadsheet') !== false || strpos($filetype, 'excel') !== false) {
        return 'bi-file-excel';
    } elseif (strpos($filetype, 'word') !== false) {
        return 'bi-file-word';
    } elseif (strpos($filetype, 'presentation') !== false || strpos($filetype, 'powerpoint') !== false) {
        return 'bi-file-ppt';
    } elseif (strpos($filetype, 'zip') !== false || strpos($filetype, 'archive') !== false) {
        return 'bi-file-zip';
    } else {
        return 'bi-file-earmark';
    }
}

function format_file_size($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . ' ' . $units[$i];
}
?>

<?php
// projects/index.php - Proje listesi sayfası (ENUM kategorili versiyonu)

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

// Kategori filtresi - önce kategori ID'si olarak al
$category_id = null;
if (isset($_GET['category']) && !empty($_GET['category']) && is_numeric($_GET['category'])) {
    $category_id = intval($_GET['category']);
}

// Veritabanı bağlantısı
$db = connect_db();

// Kategorileri al
$stmt = $db->prepare("SELECT * FROM project_categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll();

// Seçilen kategorinin ENUM değerini al
$category_enum = null;
if ($category_id !== null) {
    // Kategori adına göre ENUM değerini belirle
    $stmt = $db->prepare("SELECT name FROM project_categories WHERE id = :id");
    $stmt->execute(['id' => $category_id]);
    $categoryData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($categoryData) {
        // Kategori adını ENUM değerine dönüştür
        $categoryName = strtolower($categoryData['name']);
        
        // Basit bir eşleştirme algoritması
        if (strpos($categoryName, 'web') !== false || strpos($categoryName, 'site') !== false || strpos($categoryName, 'tasarım') !== false) {
            $category_enum = 'website';
        } else if (strpos($categoryName, 'sosyal') !== false || strpos($categoryName, 'social') !== false || strpos($categoryName, 'medya') !== false) {
            $category_enum = 'social_media';
        } else if (strpos($categoryName, 'bionluk') !== false || strpos($categoryName, 'Bionluk') !== false || strpos($categoryName, 'BiOnluk') !== false) {
            $category_enum = 'bionluk';
        } else if ($categoryName === 'Marka Yönetimi') {
            $category_enum = 'marka';
        } else {
            $category_enum = 'other';
        }
    }
}

// Projeleri al
if ($category_enum !== null) {
    // Kategori filtrelenmiş sorgu (ENUM kategoriye göre)
    $stmt = $db->prepare("
        SELECT p.*, u.full_name as created_by_name,
        (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as total_tasks,
        (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = 'done') as completed_tasks
        FROM projects p 
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.category = :category
        ORDER BY p.due_date ASC
    ");
    $stmt->execute(['category' => $category_enum]);
} else {
    // Filtresiz tüm projeler sorgusu
    $stmt = $db->prepare("
        SELECT p.*, u.full_name as created_by_name,
        (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as total_tasks,
        (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = 'done') as completed_tasks
        FROM projects p 
        LEFT JOIN users u ON p.created_by = u.id
        ORDER BY p.due_date ASC
    ");
    $stmt->execute();
}
$projects = $stmt->fetchAll();

// Kategori isimlerini ekle (ENUM değerlerini gerçek isimlere çevir)
foreach ($projects as &$project) {
    switch ($project['category']) {
        case 'website':
            $project['category_name'] = 'Website Tasarımı';
            break;
        case 'social_media':
            $project['category_name'] = 'Sosyal Medya';
            break;
        case 'bionluk':
            $project['category_name'] = 'Bionluk';
            break;
        case 'marka':
            $project['category_name'] = 'Marka Yönetimi';
            break;
        case 'other':
            $project['category_name'] = 'Diğer';
            break;
        default:
            $project['category_name'] = $project['category'];
            break;
    }
}
unset($project); // Referansı kaldır

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
    <title>inalsoft - Projeler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .table-project .progress {
            height: 8px;
        }
        .table-project th, .table-project td {
            vertical-align: middle;
        }
        .status-badge {
            min-width: 100px;
            display: inline-block;
            text-align: center;
        }
        .priority-badge {
            min-width: 80px;
            display: inline-block;
            text-align: center;
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
                    <h1 class="h2">Projeler</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#newCategoryModal">
                            <i class="bi bi-tags"></i> Yeni Kategori
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newProjectModal">
                            <i class="bi bi-plus-lg"></i> Yeni Proje
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
                
                <!-- Kategori filtreleme -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Kategoriye Göre Filtrele</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap">
                            <a href="index.php" class="btn btn-outline-primary me-2 mb-2 <?php echo $category_id === null ? 'active' : ''; ?>">
                                Tümü
                            </a>
                            <?php foreach ($categories as $category): ?>
                                <a href="index.php?category=<?php echo $category['id']; ?>" 
                                   class="btn btn-outline-primary me-2 mb-2 <?php echo $category_id === (int)$category['id'] ? 'active' : ''; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Projeler tablosu -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <?php if (count($projects) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-project">
                                    <thead>
                                        <tr>
                                            <th>Proje Adı</th>
                                            <th>Kategori</th>
                                            <th>Durum</th>
                                            <th>Öncelik</th>
                                            <th>Başlangıç</th>
                                            <th>Bitiş</th>
                                            <th>İlerleme</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($projects as $project): ?>
                                            <?php
                                                // Proje süresi ve geçen süre hesaplaması
                                                $start = new DateTime($project['start_date']);
                                                $end = new DateTime($project['due_date']);
                                                $now = new DateTime();
                                                
                                                $total_days = $start->diff($end)->days;
                                                $days_passed = $start->diff($now)->days;
                                                
                                                // İlerleme yüzdesi (0-100 arasında)
                                                $time_progress = $total_days > 0 ? min(100, max(0, ($days_passed / $total_days) * 100)) : 0;
                                                
                                                // Görev tamamlanma yüzdesi
                                                $task_progress = $project['total_tasks'] > 0 ? 
                                                    round(($project['completed_tasks'] / $project['total_tasks']) * 100) : 0;
                                                
                                                // Renk belirleme
                                                $progress_color = 'success';
                                                if ($time_progress > 80 && $task_progress < 80) {
                                                    $progress_color = 'danger';
                                                } elseif ($time_progress > 60 && $task_progress < 60) {
                                                    $progress_color = 'warning';
                                                } elseif ($time_progress > 40 && $task_progress < 40) {
                                                    $progress_color = 'info';
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="view.php?id=<?php echo $project['id']; ?>" class="text-decoration-none fw-bold">
                                                        <?php echo htmlspecialchars($project['name']); ?>
                                                    </a>
                                                    <div class="small text-muted"><?php echo htmlspecialchars(substr($project['description'], 0, 60) . (strlen($project['description']) > 60 ? '...' : '')); ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-dark"><?php echo htmlspecialchars($project['category_name']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($project['status']) {
                                                            case 'planning': echo 'secondary'; break;
                                                            case 'in_progress': echo 'primary'; break;
                                                            case 'review': echo 'warning'; break;
                                                            case 'completed': echo 'success'; break;
                                                            default: echo 'light'; break;
                                                        }
                                                    ?> status-badge">
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
                                                    ?> priority-badge">
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
                                                <td>
                                                    <?php 
                                                        $due_date = new DateTime($project['due_date']);
                                                        $is_overdue = ($project['status'] !== 'completed' && $due_date < $now);
                                                        
                                                        echo '<span class="' . ($is_overdue ? 'text-danger fw-bold' : '') . '">';
                                                        echo format_date($project['due_date'], 'd.m.Y');
                                                        echo '</span>';
                                                        
                                                        if ($is_overdue) {
                                                            echo ' <span class="badge bg-danger">Gecikti</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td style="width: 180px;">
                                                    <div class="d-flex flex-column">
                                                        <small class="text-muted mb-1">
                                                            Görevler: <?php echo $project['completed_tasks']; ?>/<?php echo $project['total_tasks']; ?> 
                                                            (<?php echo $task_progress; ?>%)
                                                        </small>
                                                        <div class="progress mb-2">
                                                            <div class="progress-bar" role="progressbar" 
                                                                style="width: <?php echo $task_progress; ?>%" 
                                                                aria-valuenow="<?php echo $task_progress; ?>" 
                                                                aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                        <small class="text-muted mb-1">
                                                            Süre: <?php echo round($time_progress); ?>%
                                                        </small>
                                                        <div class="progress">
                                                            <div class="progress-bar bg-<?php echo $progress_color; ?>" role="progressbar" 
                                                                style="width: <?php echo $time_progress; ?>%" 
                                                                aria-valuenow="<?php echo $time_progress; ?>" 
                                                                aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="view.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="tasks.php?project_id=<?php echo $project['id']; ?>" class="btn btn-sm btn-info">
                                                            <i class="bi bi-list-check"></i>
                                                        </a>
                                                        <a href="edit.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-warning">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteProjectModal" data-project-id="<?php echo $project['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="bi bi-info-circle me-2"></i> Henüz proje bulunmuyor. Yeni bir proje eklemek için "Yeni Proje" butonuna tıklayın.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Yeni Proje Modalı -->
    <div class="modal fade" id="newProjectModal" tabindex="-1" aria-labelledby="newProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="create_project.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="newProjectModalLabel">Yeni Proje Oluştur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="project_name" class="form-label">Proje Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="project_name" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="project_category" class="form-label">Kategori <span class="text-danger">*</span></label>
                                <select class="form-select" id="project_category" name="category" required>
                                    <option value="">Kategori Seçin</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="project_description" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="project_description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="project_status" class="form-label">Durum <span class="text-danger">*</span></label>
                                <select class="form-select" id="project_status" name="status" required>
                                    <option value="planning">Planlama</option>
                                    <option value="in_progress">Devam Ediyor</option>
                                    <option value="review">İnceleme</option>
                                    <option value="completed">Tamamlandı</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="project_priority" class="form-label">Öncelik <span class="text-danger">*</span></label>
                                <select class="form-select" id="project_priority" name="priority" required>
                                    <option value="low">Düşük</option>
                                    <option value="medium" selected>Orta</option>
                                    <option value="high">Yüksek</option>
                                    <option value="urgent">Acil</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="project_start_date" class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="project_start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="project_due_date" class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="project_due_date" name="due_date" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Projeyi Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Yeni Kategori Modalı -->
    <div class="modal fade" id="newCategoryModal" tabindex="-1" aria-labelledby="newCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newCategoryModalLabel">Yeni Kategori Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <form id="categoryForm">
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Kategori Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="category_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="parent_id" class="form-label">Üst Kategori (Opsiyonel)</label>
                            <select class="form-select" id="parent_id" name="parent_id">
                                <option value="">Ana Kategori</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="categoryFormMessage"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-success" id="saveCategory">Kategori Ekle</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Proje Silme Modalı -->
    <div class="modal fade" id="deleteProjectModal" tabindex="-1" aria-labelledby="deleteProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="deleteProjectForm" action="delete_project.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" id="delete_project_id" name="project_id" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteProjectModalLabel">Projeyi Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p>Bu projeyi silmek istediğinizden emin misiniz? Bu işlem geri alınamaz ve projeye ait tüm görevler, dosyalar ve yorumlar silinecektir.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Projeyi Sil</button>
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
        // Proje silme modalına ID gönderme
        $('#deleteProjectModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var projectId = button.data('project-id');
            $('#delete_project_id').val(projectId);
        });
        
        // Bitiş tarihi başlangıç tarihinden önce seçilemesin
        $('#project_start_date').on('change', function() {
            $('#project_due_date').attr('min', $(this).val());
        });
        
        // Bugünün tarihini varsayılan olarak ayarla
        $(document).ready(function() {
            const today = new Date().toISOString().split('T')[0];
            $('#project_start_date').val(today).attr('min', today);
            $('#project_due_date').attr('min', today);
            
            // Yeni kategori ekleme
            $('#saveCategory').on('click', function() {
                const formData = new FormData(document.getElementById('categoryForm'));
                
                // Form doğrulama
                if (!formData.get('name')) {
                    $('#categoryFormMessage').html('<div class="alert alert-danger">Kategori adı zorunludur</div>');
                    return;
                }
                
                $.ajax({
                    url: 'create_category.php',
                    type: 'POST',
                    data: {
                        name: formData.get('name'),
                        parent_id: formData.get('parent_id')
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#categoryFormMessage').html('<div class="alert alert-success">' + response.message + '</div>');
                            
                            // Kategori listesine yeni kategoriyi ekle
                            const newCategory = response.category;
                            $('#project_category').append(
                                $('<option>', {
                                    value: newCategory.id,
                                    text: newCategory.name
                                })
                            );
                            
                            // Kategori filtresine yeni kategoriyi ekle
                            $('.card-body .d-flex.flex-wrap').append(
                                $('<a>', {
                                    href: 'index.php?category=' + newCategory.id,
                                    class: 'btn btn-outline-primary me-2 mb-2',
                                    text: newCategory.name
                                })
                            );
                            
                            // Formu temizle
                            $('#categoryForm')[0].reset();
                            
                            // 2 saniye sonra modalı kapat
                            setTimeout(function() {
                                $('#newCategoryModal').modal('hide');
                                $('#categoryFormMessage').html('');
                                // Sayfayı yenile
                                location.reload();
                            }, 2000);
                        } else {
                            $('#categoryFormMessage').html('<div class="alert alert-danger">' + response.message + '</div>');
                        }
                    },
                    error: function() {
                        $('#categoryFormMessage').html('<div class="alert alert-danger">Bağlantı hatası. Lütfen tekrar deneyin.</div>');
                    }
                });
            });
        });
    </script>
</body>
</html>
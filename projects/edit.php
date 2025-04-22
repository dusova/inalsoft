<?php
// projects/edit.php - Proje düzenleme sayfası

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
    SELECT p.*, u.full_name as created_by_name
    FROM projects p
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

// Kategorileri al
$stmt = $db->prepare("SELECT * FROM project_categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll();

// Mevcut kategori adını bul
$current_category_name = '';
foreach ($categories as $cat) {
    $categoryName = strtolower($cat['name']);
    
        // Mevcut kategori ile eşleşip eşleşmediğini kontrol et
if (($project['category'] == 'website' && (strpos($categoryName, 'web') !== false || strpos($categoryName, 'site') !== false || strpos($categoryName, 'tasarım') !== false)) ||
($project['category'] == 'social_media' && (strpos($categoryName, 'sosyal') !== false || strpos($categoryName, 'social') !== false || strpos($categoryName, 'medya') !== false)) ||
($project['category'] == 'bionluk' && (strpos($categoryName, 'bionluk') !== false)) ||
($project['category'] == 'marka' && (strpos($categoryName, 'Marka Yönetimi') !== false)) || // Burayı değiştirdim
($project['category'] == 'other' && !((strpos($categoryName, 'web') !== false || strpos($categoryName, 'site') !== false || strpos($categoryName, 'tasarım') !== false) || 
                                  (strpos($categoryName, 'sosyal') !== false || strpos($categoryName, 'Marka Yönetimi') !== false || 
                                   strpos($categoryName, 'social') !== false || strpos($categoryName, 'medya') !== false) ||
                                   strpos($categoryName, 'bionluk') !== false))) {
$current_category_id = $cat['id'];
$current_category_name = $cat['name'];
break;
}
}

// Proje güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_project') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: edit.php?id=$project_id");
        exit;
    }
    
    // Form verilerini al ve temizle
    $name = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description'] ?? '');
    $status = sanitize_input($_POST['status']);
    $priority = sanitize_input($_POST['priority']);
    $start_date = sanitize_input($_POST['start_date']);
    $due_date = sanitize_input($_POST['due_date']);
    
    // Kategori ID'sini al
    if (!isset($_POST['category']) || empty($_POST['category'])) {
        $_SESSION['error'] = "Lütfen bir kategori seçin.";
        header("Location: edit.php?id=$project_id");
        exit;
    }
    $category_id = intval($_POST['category']);
    
    // Kategori adına göre ENUM değerini belirle
    $stmt = $db->prepare("SELECT name FROM project_categories WHERE id = :id");
    $stmt->execute(['id' => $category_id]);
    $categoryData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$categoryData) {
        $_SESSION['error'] = "Seçilen kategori bulunamadı.";
        header("Location: edit.php?id=$project_id");
        exit;
    }
    
    // Kategori adını ENUM değerine dönüştür
    $categoryEnumValue = 'other'; // Varsayılan değer
    $categoryName = strtolower($categoryData['name']);
    
    if (strpos($categoryName, 'web') !== false || strpos($categoryName, 'site') !== false || strpos($categoryName, 'tasarım') !== false) {
        $categoryEnumValue = 'website';
    } else if (strpos($categoryName, 'sosyal') !== false || strpos($categoryName, 'social') !== false || strpos($categoryName, 'medya') !== false) {
        $categoryEnumValue = 'social_media';
    } else if (strpos($categoryName, 'bionluk') !== false) {
        $categoryEnumValue = 'bionluk';
    } else if (strpos($categoryName, 'Marka Yönetimi') !== false) { // Burayı değiştirdim
        $categoryEnumValue = 'marka';
    }
    
    // Zorunlu alanları kontrol et
    if (empty($name) || empty($status) || empty($priority) || empty($start_date) || empty($due_date)) {
        $_SESSION['error'] = "Lütfen tüm zorunlu alanları doldurun.";
        header("Location: edit.php?id=$project_id");
        exit;
    }
    
    // Tarih formatını kontrol et
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $due_date)) {
        $_SESSION['error'] = "Geçersiz tarih formatı.";
        header("Location: edit.php?id=$project_id");
        exit;
    }
    
    // Bitiş tarihi başlangıç tarihinden önce olamaz
    if (strtotime($due_date) < strtotime($start_date)) {
        $_SESSION['error'] = "Bitiş tarihi başlangıç tarihinden önce olamaz.";
        header("Location: edit.php?id=$project_id");
        exit;
    }
    
    try {
        // Projeyi güncelle
        $stmt = $db->prepare("
            UPDATE projects SET 
                name = :name,
                description = :description,
                category = :category,
                status = :status,
                priority = :priority,
                start_date = :start_date,
                due_date = :due_date,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $result = $stmt->execute([
            'name' => $name,
            'description' => $description,
            'category' => $categoryEnumValue,
            'status' => $status,
            'priority' => $priority,
            'start_date' => $start_date,
            'due_date' => $due_date,
            'id' => $project_id
        ]);
        
        if ($result) {
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'update', 'project', $project_id, "Proje güncellendi: $name");
            
            $_SESSION['success'] = "Proje başarıyla güncellendi.";
            header("Location: view.php?id=$project_id");
            exit;
        } else {
            throw new Exception("Proje güncellenirken bir hata oluştu.");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Proje güncellenirken bir hata oluştu: " . $e->getMessage();
        header("Location: edit.php?id=$project_id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Projeyi Düzenle | <?php echo htmlspecialchars($project['name']); ?></title>
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
                        <li class="breadcrumb-item active" aria-current="page">Düzenle</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Projeyi Düzenle</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="view.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Projeye Dön
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
                
                <!-- Proje Düzenleme Formu -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Proje Bilgilerini Düzenle</h5>
                    </div>
                    <div class="card-body">
                        <form action="edit.php?id=<?php echo $project_id; ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_project">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="name" class="form-label">Proje Adı <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($project['name']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="category" class="form-label">Kategori <span class="text-danger">*</span></label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="">Kategori Seçin</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>" <?php echo ($category['name'] == $current_category_name) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($project['description']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="status" class="form-label">Durum <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="planning" <?php echo $project['status'] === 'planning' ? 'selected' : ''; ?>>Planlama</option>
                                        <option value="in_progress" <?php echo $project['status'] === 'in_progress' ? 'selected' : ''; ?>>Devam Ediyor</option>
                                        <option value="review" <?php echo $project['status'] === 'review' ? 'selected' : ''; ?>>İnceleme</option>
                                        <option value="completed" <?php echo $project['status'] === 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="priority" class="form-label">Öncelik <span class="text-danger">*</span></label>
                                    <select class="form-select" id="priority" name="priority" required>
                                        <option value="low" <?php echo $project['priority'] === 'low' ? 'selected' : ''; ?>>Düşük</option>
                                        <option value="medium" <?php echo $project['priority'] === 'medium' ? 'selected' : ''; ?>>Orta</option>
                                        <option value="high" <?php echo $project['priority'] === 'high' ? 'selected' : ''; ?>>Yüksek</option>
                                        <option value="urgent" <?php echo $project['priority'] === 'urgent' ? 'selected' : ''; ?>>Acil</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_date" class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $project['start_date']; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="due_date" class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo $project['due_date']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Oluşturan</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($project['created_by_name']); ?>" disabled>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Oluşturulma Tarihi</label>
                                    <input type="text" class="form-control" value="<?php echo format_date($project['created_at'], 'd.m.Y H:i'); ?>" disabled>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="view.php?id=<?php echo $project_id; ?>" class="btn btn-secondary me-md-2">İptal</a>
                                <button type="submit" class="btn btn-primary">Değişiklikleri Kaydet</button>
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
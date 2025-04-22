<?php
// projects/categories.php - Proje kategorilerini yönetme sayfası

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

// Kategorileri al
$stmt = $db->prepare("SELECT c.*, p.name as parent_name 
                     FROM project_categories c
                     LEFT JOIN project_categories p ON c.parent_id = p.id
                     ORDER BY COALESCE(p.name, c.name), c.name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Form işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: categories.php");
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // Yeni kategori ekleme
    if ($action === 'add') {
        $name = sanitize_input($_POST['name']);
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        
        if (empty($name)) {
            $_SESSION['error'] = "Kategori adı boş olamaz.";
            header("Location: categories.php");
            exit;
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO project_categories (name, parent_id) VALUES (:name, :parent_id)");
            $result = $stmt->execute([
                'name' => $name,
                'parent_id' => $parent_id
            ]);
            
            if ($result) {
                $category_id = $db->lastInsertId();
                log_activity($_SESSION['user_id'], 'create', 'category', $category_id, "Yeni kategori oluşturuldu: $name");
                $_SESSION['success'] = "Kategori başarıyla eklendi.";
            } else {
                $_SESSION['error'] = "Kategori eklenirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Veritabanı hatası: " . $e->getMessage();
        }
        
        header("Location: categories.php");
        exit;
    }
    
    // Kategori düzenleme
    else if ($action === 'edit') {
        $category_id = intval($_POST['category_id']);
        $name = sanitize_input($_POST['name']);
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        
        if (empty($name)) {
            $_SESSION['error'] = "Kategori adı boş olamaz.";
            header("Location: categories.php");
            exit;
        }
        
        // Kendisini kendi ebeveyni olarak atama kontrolü
        if ($parent_id === $category_id) {
            $_SESSION['error'] = "Bir kategori kendisinin alt kategorisi olamaz.";
            header("Location: categories.php");
            exit;
        }
        
        try {
            $stmt = $db->prepare("UPDATE project_categories SET name = :name, parent_id = :parent_id WHERE id = :id");
            $result = $stmt->execute([
                'name' => $name,
                'parent_id' => $parent_id,
                'id' => $category_id
            ]);
            
            if ($result) {
                log_activity($_SESSION['user_id'], 'update', 'category', $category_id, "Kategori güncellendi: $name");
                $_SESSION['success'] = "Kategori başarıyla güncellendi.";
            } else {
                $_SESSION['error'] = "Kategori güncellenirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Veritabanı hatası: " . $e->getMessage();
        }
        
        header("Location: categories.php");
        exit;
    }
    
    // Kategori silme
    else if ($action === 'delete') {
        $category_id = intval($_POST['category_id']);
        
        // Kategorinin projeler tarafından kullanılıp kullanılmadığını kontrol et
        $stmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE category = :category_id");
        $stmt->execute(['category_id' => $category_id]);
        $used_count = $stmt->fetchColumn();
        
        if ($used_count > 0) {
            $_SESSION['error'] = "Bu kategori projeler tarafından kullanılıyor ve silinemez.";
            header("Location: categories.php");
            exit;
        }
        
        // Alt kategorileri kontrol et
        $stmt = $db->prepare("SELECT COUNT(*) FROM project_categories WHERE parent_id = :category_id");
        $stmt->execute(['category_id' => $category_id]);
        $child_count = $stmt->fetchColumn();
        
        if ($child_count > 0) {
            $_SESSION['error'] = "Bu kategorinin alt kategorileri var ve silinemez.";
            header("Location: categories.php");
            exit;
        }
        
        try {
            $stmt = $db->prepare("DELETE FROM project_categories WHERE id = :id");
            $result = $stmt->execute([
                'id' => $category_id
            ]);
            
            if ($result) {
                log_activity($_SESSION['user_id'], 'delete', 'category', $category_id, "Kategori silindi");
                $_SESSION['success'] = "Kategori başarıyla silindi.";
            } else {
                $_SESSION['error'] = "Kategori silinirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Veritabanı hatası: " . $e->getMessage();
        }
        
        header("Location: categories.php");
        exit;
    }
}

// Ana kategorileri al (dropdown için)
$stmt = $db->prepare("SELECT id, name FROM project_categories WHERE parent_id IS NULL ORDER BY name");
$stmt->execute();
$main_categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Proje Kategorileri</title>
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
                    <h1 class="h2">Proje Kategorileri</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="bi bi-plus-lg"></i> Yeni Kategori
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
                
                <!-- Kategoriler Tablosu -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="categoriesTable">
                                <thead>
                                    <tr>
                                        <th width="50">ID</th>
                                        <th>Kategori Adı</th>
                                        <th>Üst Kategori</th>
                                        <th width="150">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($categories) > 0): ?>
                                        <?php foreach ($categories as $category): ?>
                                            <tr>
                                                <td><?php echo $category['id']; ?></td>
                                                <td><?php echo htmlspecialchars($category['name']); ?></td>
                                                <td>
                                                    <?php if ($category['parent_id']): ?>
                                                        <?php echo htmlspecialchars($category['parent_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-warning edit-category" 
                                                            data-id="<?php echo $category['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($category['name']); ?>"
                                                            data-parent="<?php echo $category['parent_id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger delete-category" 
                                                            data-id="<?php echo $category['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($category['name']); ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center">Henüz kategori bulunmuyor.</td>
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
    
    <!-- Yeni Kategori Ekleme Modalı -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="categories.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="addCategoryModalLabel">Yeni Kategori Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Kategori Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="category_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="parent_category" class="form-label">Üst Kategori</label>
                            <select class="form-select" id="parent_category" name="parent_id">
                                <option value="">Ana Kategori (Üst kategori yok)</option>
                                <?php foreach ($main_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">İsteğe bağlı. Eğer bir üst kategori seçerseniz, bu kategori onun alt kategorisi olur.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kategori Ekle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Kategori Düzenleme Modalı -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="categories.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="editCategoryModalLabel">Kategori Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Kategori Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_parent" class="form-label">Üst Kategori</label>
                            <select class="form-select" id="edit_parent" name="parent_id">
                                <option value="">Ana Kategori (Üst kategori yok)</option>
                                <?php foreach ($main_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">İsteğe bağlı. Eğer bir üst kategori seçerseniz, bu kategori onun alt kategorisi olur.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kategoriyi Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Kategori Silme Modalı -->
    <div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="categories.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="category_id" id="delete_category_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteCategoryModalLabel">Kategori Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Uyarı:</span> <span id="delete_category_name"></span> kategorisini silmek istediğinizden emin misiniz?</p>
                        <p>Bu kategori, projelerde kullanılıyorsa veya alt kategorileri varsa silinemez.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Kategoriyi Sil</button>
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
        // Düzenleme modalını açma
        document.querySelectorAll('.edit-category').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const parent = this.getAttribute('data-parent');
                
                document.getElementById('edit_category_id').value = id;
                document.getElementById('edit_name').value = name;
                
                const parentSelect = document.getElementById('edit_parent');
                if (parent) {
                    if (Array.from(parentSelect.options).some(option => option.value === parent)) {
                        parentSelect.value = parent;
                    } else {
                        parentSelect.value = '';
                    }
                } else {
                    parentSelect.value = '';
                }
                
                var modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
                modal.show();
            });
        });
        
        // Silme modalını açma
        document.querySelectorAll('.delete-category').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                
                document.getElementById('delete_category_id').value = id;
                document.getElementById('delete_category_name').textContent = name;
                
                var modal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
                modal.show();
            });
        });
        
        // Üst kategori değiştiğinde, o kategorinin alt kategorilerini göster/gizle
        document.getElementById('edit_parent').addEventListener('change', function() {
            const categoryId = document.getElementById('edit_category_id').value;
            const options = this.options;
            
            // Eğer seçilen kategori, düzenlenmekte olan kategorinin kendisi veya alt kategorisi ise uyarı ver
            if (this.value === categoryId) {
                alert('Bir kategori kendisinin alt kategorisi olamaz.');
                this.value = '';
            }
        });
    </script>
</body>
</html>

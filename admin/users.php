
<?php
// admin/users.php - Kullanıcı yönetimi sayfası

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

// Kullanıcıları al
$stmt = $db->prepare("
    SELECT * FROM users 
    ORDER BY full_name ASC
");
$stmt->execute();
$users = $stmt->fetchAll();

// Kullanıcı silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: users.php");
        exit;
    }
    
    $user_id = intval($_POST['user_id']);
    
    // Mevcut admin hesabını silemezsin
    if ($user_id === $_SESSION['user_id']) {
        $_SESSION['error'] = "Kendi hesabınızı silemezsiniz.";
        header("Location: users.php");
        exit;
    }
    
    try {
        // Kullanıcıyı sil
        $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
        $result = $stmt->execute(['id' => $user_id]);
        
        if ($result) {
            $_SESSION['success'] = "Kullanıcı başarıyla silindi.";
        } else {
            $_SESSION['error'] = "Kullanıcı silinirken bir hata oluştu.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Veritabanı hatası: " . $e->getMessage();
    }
    
    header("Location: users.php");
    exit;
}

// Kullanıcı rolü değiştirme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_role') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: users.php");
        exit;
    }
    
    $user_id = intval($_POST['user_id']);
    $new_role = sanitize_input($_POST['role']);
    
    // Geçerli rol kontrolü
    if (!in_array($new_role, ['user', 'admin'])) {
        $_SESSION['error'] = "Geçersiz rol.";
        header("Location: users.php");
        exit;
    }
    
    // Kendi rolünü değiştiremezsin
    if ($user_id === $_SESSION['user_id']) {
        $_SESSION['error'] = "Kendi rolünüzü değiştiremezsiniz.";
        header("Location: users.php");
        exit;
    }
    
    try {
        // Kullanıcı rolünü güncelle
        $stmt = $db->prepare("UPDATE users SET role = :role WHERE id = :id");
        $result = $stmt->execute([
            'role' => $new_role,
            'id' => $user_id
        ]);
        
        if ($result) {
            $_SESSION['success'] = "Kullanıcı rolü başarıyla güncellendi.";
        } else {
            $_SESSION['error'] = "Kullanıcı rolü güncellenirken bir hata oluştu.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Veritabanı hatası: " . $e->getMessage();
    }
    
    header("Location: users.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Kullanıcı Yönetimi</title>
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
                    <h1 class="h2">Kullanıcı Yönetimi</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="../auth/register.php" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Yeni Kullanıcı Ekle
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
                
                <!-- Kullanıcı listesi -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="usersTable" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Kullanıcı Adı</th>
                                        <th>Ad Soyad</th>
                                        <th>E-posta</th>
                                        <th>Rol</th>
                                        <th>Kayıt Tarihi</th>
                                        <th>Son Giriş</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td><?php echo $u['id']; ?></td>
                                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $u['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                                    <?php echo $u['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo format_date($u['created_at'], 'd.m.Y H:i'); ?></td>
                                            <td><?php echo $u['last_login'] ? format_date($u['last_login'], 'd.m.Y H:i') : '-'; ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#changeRoleModal" data-user-id="<?php echo $u['id']; ?>" data-user-name="<?php echo htmlspecialchars($u['full_name']); ?>" data-user-role="<?php echo $u['role']; ?>">
                                                        <i class="bi bi-person-gear"></i>
                                                    </button>
                                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal" data-user-id="<?php echo $u['id']; ?>" data-user-name="<?php echo htmlspecialchars($u['full_name']); ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
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
    
    <!-- Rol Değiştirme Modalı -->
    <div class="modal fade" id="changeRoleModal" tabindex="-1" aria-labelledby="changeRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="users.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" id="change_role_user_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="changeRoleModalLabel">Kullanıcı Rolünü Değiştir</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span id="change_role_user_name"></span> kullanıcısının rolünü değiştirmek istediğinizden emin misiniz?</p>
                        
                        <div class="mb-3">
                            <label for="role" class="form-label">Rol</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="user">Kullanıcı</option>
                                <option value="admin">Yönetici</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Rolü Değiştir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Kullanıcı Silme Modalı -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="users.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteUserModalLabel">Kullanıcıyı Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Uyarı:</span> <span id="delete_user_name"></span> kullanıcısını silmek istediğinizden emin misiniz?</p>
                        <p>Bu işlem geri alınamaz ve kullanıcının tüm verileri silinecektir.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Kullanıcıyı Sil</button>
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
        // Rol değiştirme modalını doldur
        document.addEventListener('DOMContentLoaded', function() {
            const changeRoleModal = document.getElementById('changeRoleModal');
            if (changeRoleModal) {
                changeRoleModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    const userName = button.getAttribute('data-user-name');
                    const userRole = button.getAttribute('data-user-role');
                    
                    const roleSelect = document.getElementById('role');
                    
                    document.getElementById('change_role_user_id').value = userId;
                    document.getElementById('change_role_user_name').textContent = userName;
                    
                    if (roleSelect) {
                        for (let i = 0; i < roleSelect.options.length; i++) {
                            if (roleSelect.options[i].value === userRole) {
                                roleSelect.selectedIndex = i;
                                break;
                            }
                        }
                    }
                });
            }
            
            // Kullanıcı silme modalını doldur
            const deleteUserModal = document.getElementById('deleteUserModal');
            if (deleteUserModal) {
                deleteUserModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    const userName = button.getAttribute('data-user-name');
                    
                    document.getElementById('delete_user_id').value = userId;
                    document.getElementById('delete_user_name').textContent = userName;
                });
            }
        });
    </script>
</body>
</html>

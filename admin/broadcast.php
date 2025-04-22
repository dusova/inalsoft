
<?php
// admin/broadcast.php - Duyuru yayınlama sayfası

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

// Form gönderildi mi kontrol et
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: broadcast.php");
        exit;
    }
    
    $title = sanitize_input($_POST['title']);
    $message = sanitize_input($_POST['message']);
    $recipients = $_POST['recipients'];
    
    // Boş alan kontrolü
    if (empty($title) || empty($message) || empty($recipients)) {
        $_SESSION['error'] = "Lütfen tüm alanları doldurun.";
        header("Location: broadcast.php");
        exit;
    }
    
    try {
        $sent_count = 0;
        
        // Tüm kullanıcılar
        if (in_array('all', $recipients)) {
            $stmt = $db->prepare("SELECT id FROM users");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($users as $user_id) {
                create_notification($user_id, $title, $message, 'system');
                $sent_count++;
            }
        }
        
        // Yöneticiler
        elseif (in_array('admins', $recipients)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($admins as $admin_id) {
                create_notification($admin_id, $title, $message, 'system');
                $sent_count++;
            }
        }
        
        // Normal kullanıcılar
        elseif (in_array('users', $recipients)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE role = 'user'");
            $stmt->execute();
            $normal_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($normal_users as $user_id) {
                create_notification($user_id, $title, $message, 'system');
                $sent_count++;
            }
        }
        
        // Özel seçilen kullanıcılar
        else {
            foreach ($recipients as $user_id) {
                if (is_numeric($user_id)) {
                    create_notification(intval($user_id), $title, $message, 'system');
                    $sent_count++;
                }
            }
        }
        
        // Aktivite logla
        log_activity($_SESSION['user_id'], 'broadcast', 'system', 0, "Duyuru yayınlandı: $title");
        
        $_SESSION['success'] = "Duyuru başarıyla $sent_count kullanıcıya gönderildi.";
    } catch (Exception $e) {
        $_SESSION['error'] = "Duyuru gönderilirken bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: broadcast.php");
    exit;
}

// Kullanıcıları al
$stmt = $db->prepare("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
$stmt->execute();
$users = $stmt->fetchAll();

// Gruplara ayır
$admin_users = array_filter($users, function($u) { return $u['role'] === 'admin'; });
$normal_users = array_filter($users, function($u) { return $u['role'] === 'user'; });
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Duyuru Yayınla</title>
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
                    <h1 class="h2">Duyuru Yayınla</h1>
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
                
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form action="broadcast.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            
                            <div class="mb-3">
                                <label for="title" class="form-label">Duyuru Başlığı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="message" class="form-label">Duyuru Metni <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Alıcılar <span class="text-danger">*</span></label>
                                
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Hızlı Seçim</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input recipient-group" type="radio" name="recipients[]" id="all_users" value="all">
                                                    <label class="form-check-label" for="all_users">
                                                        <i class="bi bi-people-fill"></i> Tüm Kullanıcılar
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input recipient-group" type="radio" name="recipients[]" id="admin_users" value="admins">
                                                    <label class="form-check-label" for="admin_users">
                                                        <i class="bi bi-person-fill-gear"></i> Sadece Yöneticiler
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input recipient-group" type="radio" name="recipients[]" id="normal_users" value="users">
                                                    <label class="form-check-label" for="normal_users">
                                                        <i class="bi bi-person-fill"></i> Sadece Normal Kullanıcılar
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">Özel Seçim</h6>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="select_all_users">
                                            <label class="form-check-label" for="select_all_users">
                                                Tümünü Seç/Kaldır
                                            </label>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <?php foreach ($users as $u): ?>
                                                <div class="col-md-4 mb-2">
                                                    <div class="form-check">
                                                        <input class="form-check-input user-checkbox" type="checkbox" name="recipients[]" id="user_<?php echo $u['id']; ?>" value="<?php echo $u['id']; ?>">
                                                        <label class="form-check-label" for="user_<?php echo $u['id']; ?>">
                                                            <?php echo htmlspecialchars($u['full_name']); ?>
                                                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                                                <span class="badge bg-secondary">Siz</span>
                                                            <?php endif; ?>
                                                            <?php if ($u['role'] === 'admin'): ?>
                                                                <span class="badge bg-danger">Yönetici</span>
                                                            <?php endif; ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Duyuruyu Gönder</button>
                                <a href="index.php" class="btn btn-secondary">İptal</a>
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
            // Tümünü seç/kaldır
            const selectAllCheckbox = document.getElementById('select_all_users');
            const userCheckboxes = document.querySelectorAll('.user-checkbox');
            
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const isChecked = this.checked;
                    userCheckboxes.forEach(checkbox => {
                        checkbox.checked = isChecked;
                    });
                    
                    // Grup seçimi kaldır
                    document.querySelectorAll('.recipient-group').forEach(radio => {
                        radio.checked = false;
                    });
                });
            }
            
            // Grup seçenekleri
            document.querySelectorAll('.recipient-group').forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.checked) {
                        // Özel seçimleri kaldır
                        userCheckboxes.forEach(checkbox => {
                            checkbox.checked = false;
                        });
                        selectAllCheckbox.checked = false;
                    }
                });
            });
            
            // Herhangi bir özel seçenek seçildiğinde, grup seçimini kaldır
            userCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        document.querySelectorAll('.recipient-group').forEach(radio => {
                            radio.checked = false;
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>
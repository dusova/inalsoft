
<?php
// auth/register.php - Yeni kullanıcı kaydı sayfası (sadece yöneticiler kullanabilir)

session_start();
require_once '../config/database.php';

// Sadece yöneticilerin erişimine izin ver
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../dashboard.php");
    exit;
}

// Formu işle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: register.php");
        exit;
    }
    
    // Form verilerini al
    $username = sanitize_input($_POST['username']);
    $email = sanitize_input($_POST['email']);
    $full_name = sanitize_input($_POST['full_name']);
    $password = $_POST['password'];
    $role = sanitize_input($_POST['role']);
    
    // Temel doğrulamalar
    if (empty($username) || empty($email) || empty($full_name) || empty($password)) {
        $_SESSION['error'] = "Tüm alanları doldurunuz.";
        header("Location: register.php");
        exit;
    }
    
    // E-posta formatı doğrulaması
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Geçerli bir e-posta adresi giriniz.";
        header("Location: register.php");
        exit;
    }
    
    // Şifre uzunluğu kontrolü
    if (strlen($password) < 8) {
        $_SESSION['error'] = "Şifre en az 8 karakter uzunluğunda olmalıdır.";
        header("Location: register.php");
        exit;
    }
    
    // Veritabanı bağlantısı
    $db = connect_db();
    
    // Kullanıcı adı benzersiz mi kontrol et
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "Bu kullanıcı adı zaten kullanılıyor.";
        header("Location: register.php");
        exit;
    }
    
    // E-posta benzersiz mi kontrol et
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "Bu e-posta adresi zaten kullanılıyor.";
        header("Location: register.php");
        exit;
    }
    
    // Şifreyi hashle
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Kullanıcıyı veritabanına ekle
    $stmt = $db->prepare("
        INSERT INTO users (username, password, email, full_name, role, created_at) 
        VALUES (:username, :password, :email, :full_name, :role, NOW())
    ");
    
    $result = $stmt->execute([
        'username' => $username,
        'password' => $hashed_password,
        'email' => $email,
        'full_name' => $full_name,
        'role' => $role
    ]);
    
    if ($result) {
        // Başarılı kayıt
        $user_id = $db->lastInsertId();
        
        // Aktiviteyi logla
        log_activity($_SESSION['user_id'], 'user_create', 'user', $user_id, "$username kullanıcısı oluşturuldu");
        
        // Başarı mesajı
        $_SESSION['success'] = "Kullanıcı başarıyla oluşturuldu.";
        header("Location: ../admin/users.php");
        exit;
    } else {
        // Kayıt hatası
        $_SESSION['error'] = "Kullanıcı kaydı yapılırken bir hata oluştu.";
        header("Location: register.php");
        exit;
    }
}

// Tema bilgisini al
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Yeni Kullanıcı Kaydı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Navbar -->
    <?php 
        $base_path = '../';
        include '../includes/navbar.php'; 
    ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Ana içerik -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Yeni Kullanıcı Kaydı</h1>
                </div>
                
                <!-- Bildirimler -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6 mx-auto">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Yeni Kullanıcı Bilgileri</h5>
                            </div>
                            <div class="card-body">
                                <form action="register.php" method="post" class="needs-validation" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="username" name="username" required>
                                        <div class="invalid-feedback">Kullanıcı adı gerekli</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">E-posta Adresi <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" required>
                                        <div class="invalid-feedback">Geçerli bir e-posta adresi gerekli</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                                        <div class="invalid-feedback">Ad soyad gerekli</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Şifre <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="password" name="password" required minlength="8">
                                        <div class="invalid-feedback">Şifre en az 8 karakter olmalı</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Kullanıcı Rolü <span class="text-danger">*</span></label>
                                        <select class="form-select" id="role" name="role" required>
                                            <option value="user">Kullanıcı</option>
                                            <option value="admin">Yönetici</option>
                                        </select>
                                        <div class="invalid-feedback">Kullanıcı rolü gerekli</div>
                                    </div>
                                    
                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary">Kullanıcı Oluştur</button>
                                        <a href="../admin/users.php" class="btn btn-outline-secondary">İptal</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/form-validation.js"></script>
</body>
</html>


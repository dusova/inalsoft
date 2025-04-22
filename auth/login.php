<?php
// auth/login.php - Giriş işlemi

session_start();
require_once '../config/database.php';

// Giriş formundan gelen verileri al
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];
    
    // Boş alan kontrolü
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Kullanıcı adı veya parola boş olamaz.";
        header("Location: login.php");
        exit;
    }
    
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: login.php");
        exit;
    }
    
    // Veritabanı bağlantısı
    $db = connect_db();
    
    // Kullanıcı adına göre kullanıcıyı bul
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    
    // Kullanıcı var mı ve parola doğru mu kontrol et
    if ($user && password_verify($password, $user['password'])) {
        // Başarılı giriş
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        // Son giriş zamanını güncelle
        $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
        $update->execute(['id' => $user['id']]);
        
        // Aktiviteyi logla
        log_activity($user['id'], 'login', 'user', $user['id'], 'Kullanıcı giriş yaptı');
        
        // Anasayfaya yönlendir
        header("Location: ../dashboard.php");
        exit;
    } else {
        // Başarısız giriş
        $_SESSION['error'] = "Kullanıcı adı veya parola hatalı.";
        header("Location: login.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Giriş</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Tema kontrolü için veri özniteliği -->
    <div data-bs-theme="<?php echo isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light'; ?>">
        <div class="container">
            <div class="row justify-content-center mt-5">
                <div class="col-md-6 col-lg-4">
                    <div class="card shadow">
                        <div class="card-body p-5">
                            <div class="text-center mb-4">
                                <img src="../assets/img/logo.png" alt="inalsoft Logo" class="img-fluid" style="max-width: 200px;">
                            </div>
                            
                            <h3 class="text-center mb-4">Giriş Yap</h3>
                            
                            <?php if (isset($_SESSION['error'])): ?>
                                <div class="alert alert-danger">
                                    <?php 
                                        echo $_SESSION['error']; 
                                        unset($_SESSION['error']);
                                    ?>
                                </div>
                            <?php endif; ?>
                            
                            <form action="login.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                
                                <div class="mb-3">
                                    <label for="username" class="form-label">Kullanıcı Adı</label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password" class="form-label">Parola</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                    <label class="form-check-label" for="remember">Beni Hatırla</label>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">Giriş Yap</button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-3">
                                <button id="toggleTheme" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-moon-stars"></i> Tema Değiştir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
</body>
</html>
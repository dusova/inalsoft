<?php
// profile/index.php - Profil ayarları sayfası

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

// Bildirim tercihlerini al
$stmt = $db->prepare("SELECT notification_preference FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$notification_pref = $stmt->fetchColumn();
$notification_settings = $notification_pref ? json_decode($notification_pref, true) : [
    'email_notifications' => true,
    'browser_notifications' => true,
    'project_updates' => true,
    'meeting_reminders' => true,
    'task_assignments' => true,
    'system_announcements' => true
];

// Formu işle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: index.php");
        exit;
    }
    
    // Profil güncelleme
    if ($action === 'update_profile') {
        $full_name = sanitize_input($_POST['full_name']);
        $email = sanitize_input($_POST['email']);
        
        // Verileri doğrula
        if (empty($full_name) || empty($email)) {
            $_SESSION['error'] = "Ad Soyad ve E-posta alanları zorunludur.";
            header("Location: index.php");
            exit;
        }
        
        // E-posta formatını kontrol et
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Geçerli bir e-posta adresi giriniz.";
            header("Location: index.php");
            exit;
        }
        
        // E-posta adresi başkası tarafından kullanılıyor mu kontrol et
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $stmt->execute(['email' => $email, 'id' => $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "Bu e-posta adresi başka bir kullanıcı tarafından kullanılmaktadır.";
            header("Location: index.php");
            exit;
        }
        
        // Profil resmi yüklendi mi kontrol et
        $profile_image = $user['profile_image']; // Mevcut resmi varsayılan olarak kullan
        
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            $file = $_FILES['profile_image'];
            
            // Dosya tipini ve boyutunu kontrol et
            if (!in_array($file['type'], $allowed_types)) {
                $_SESSION['error'] = "Sadece JPEG, PNG ve GIF formatındaki resimler yüklenebilir.";
                header("Location: index.php");
                exit;
            }
            
            if ($file['size'] > $max_size) {
                $_SESSION['error'] = "Dosya boyutu 2MB'yi geçemez.";
                header("Location: index.php");
                exit;
            }
            
            // Dosyayı yükle
            $upload_dir = '../uploads/profile_images/';
            $filename = time() . '_' . $_SESSION['user_id'] . '_' . basename($file['name']);
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $profile_image = 'uploads/profile_images/' . $filename;
            } else {
                $_SESSION['error'] = "Dosya yüklenirken bir hata oluştu.";
                header("Location: index.php");
                exit;
            }
        }
        
        // Kullanıcı bilgilerini güncelle
        $stmt = $db->prepare("UPDATE users SET full_name = :full_name, email = :email, profile_image = :profile_image WHERE id = :id");
        $stmt->execute([
            'full_name' => $full_name,
            'email' => $email,
            'profile_image' => $profile_image,
            'id' => $_SESSION['user_id']
        ]);
        
        // Session'ı güncelle
        $_SESSION['full_name'] = $full_name;
        
        $_SESSION['success'] = "Profil bilgileriniz başarıyla güncellendi.";
        header("Location: index.php");
        exit;
    }
    
    // Şifre değiştirme
    elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Şifreleri doğrula
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['error'] = "Tüm şifre alanları zorunludur.";
            header("Location: index.php");
            exit;
        }
        
        // Yeni şifre ve onay şifresi eşleşiyor mu
        if ($new_password !== $confirm_password) {
            $_SESSION['error'] = "Yeni şifre ve onay şifresi eşleşmiyor.";
            header("Location: index.php");
            exit;
        }
        
        // Şifre uzunluğu kontrolü
        if (strlen($new_password) < 8) {
            $_SESSION['error'] = "Şifre en az 8 karakter uzunluğunda olmalıdır.";
            header("Location: index.php");
            exit;
        }
        
        // Mevcut şifreyi kontrol et
        $stmt = $db->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $db_password = $stmt->fetchColumn();
        
        if (!password_verify($current_password, $db_password)) {
            $_SESSION['error'] = "Mevcut şifre yanlış.";
            header("Location: index.php");
            exit;
        }
        
        // Yeni şifreyi hashle ve güncelle
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute([
            'password' => $hashed_password,
            'id' => $_SESSION['user_id']
        ]);
        
        $_SESSION['success'] = "Şifreniz başarıyla değiştirildi.";
        header("Location: index.php");
        exit;
    }
    
    // Tema tercihini güncelle
    elseif ($action === 'update_theme') {
        $theme_preference = sanitize_input($_POST['theme_preference']);
        
        // Tema tercihini güncelle
        $stmt = $db->prepare("UPDATE users SET theme_preference = :theme_preference WHERE id = :id");
        $stmt->execute([
            'theme_preference' => $theme_preference,
            'id' => $_SESSION['user_id']
        ]);
        
        // Çerez olarak da kaydet
        setcookie('theme', $theme_preference, time() + 30 * 24 * 60 * 60, '/');
        
        $_SESSION['success'] = "Tema tercihiniz başarıyla güncellendi.";
        header("Location: index.php");
        exit;
    }
    
    // Bildirim ayarlarını güncelle
    elseif ($action === 'update_notifications') {
        $notification_settings = [
            'email_notifications' => isset($_POST['email_notifications']),
            'browser_notifications' => isset($_POST['browser_notifications']),
            'project_updates' => isset($_POST['project_updates']),
            'meeting_reminders' => isset($_POST['meeting_reminders']),
            'task_assignments' => isset($_POST['task_assignments']),
            'system_announcements' => isset($_POST['system_announcements'])
        ];
        
        // Bildirim ayarlarını JSON olarak kaydet
        $stmt = $db->prepare("UPDATE users SET notification_preference = :notification_preference WHERE id = :id");
        $stmt->execute([
            'notification_preference' => json_encode($notification_settings),
            'id' => $_SESSION['user_id']
        ]);
        
        $_SESSION['success'] = "Bildirim ayarlarınız başarıyla güncellendi.";
        header("Location: index.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Profil Ayarları</title>
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
                    <h1 class="h2">Profil Ayarları</h1>
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
                
                <div class="row">
                    <!-- Sol Sütun: Profil Bilgileri ve Fotoğraf -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Profil Bilgileri</h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="mb-4">
                                    <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profil Resmi" class="img-fluid rounded-circle profile-image" style="width: 150px; height: 150px; object-fit: cover;">
                                </div>
                                
                                <h5 class="card-title"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                                <p class="card-text text-muted">
                                    <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                                </p>
                                <p class="card-text">
                                    <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                        <?php echo $user['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı'; ?>
                                    </span>
                                </p>
                                <p class="card-text text-muted">
                                    <small>
                                        Son giriş: <?php echo $user['last_login'] ? format_date($user['last_login']) : 'Bilgi yok'; ?>
                                    </small>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sağ Sütun: Ayarlar Tabları -->
                    <div class="col-md-8">
                        <div class="card shadow">
                            <div class="card-header">
                                <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">
                                            <i class="bi bi-person-circle"></i> Profil Düzenle
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab" aria-controls="password" aria-selected="false">
                                            <i class="bi bi-key"></i> Şifre Değiştir
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences" type="button" role="tab" aria-controls="preferences" aria-selected="false">
                                            <i class="bi bi-gear"></i> Tercihler
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button" role="tab" aria-controls="notifications" aria-selected="false">
                                            <i class="bi bi-bell"></i> Bildirimler
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="profileTabsContent">
                                    <!-- Profil Düzenleme Sekmesi -->
                                    <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                                        <form action="index.php" method="post" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="update_profile">
                                            
                                            <div class="mb-3">
                                                <label for="full_name" class="form-label">Ad Soyad</label>
                                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="email" class="form-label">E-posta Adresi</label>
                                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="profile_image" class="form-label">Profil Fotoğrafı</label>
                                                <input type="file" class="form-control" id="profile_image" name="profile_image" accept=".jpg, .jpeg, .png, .gif">
                                                <div class="form-text">Maksimum dosya boyutu: 2MB. İzin verilen formatlar: JPG, PNG, GIF</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <div class="form-text text-muted mb-2">Şu anki fotoğraf:</div>
                                                <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Mevcut Profil Resmi" class="img-thumbnail" style="max-width: 100px;">
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">Profili Güncelle</button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Şifre Değiştirme Sekmesi -->
                                    <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                                        <form action="index.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="change_password">
                                            
                                            <div class="mb-3">
                                                <label for="current_password" class="form-label">Mevcut Şifre</label>
                                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="new_password" class="form-label">Yeni Şifre</label>
                                                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                                <div class="form-text">En az 8 karakter uzunluğunda olmalıdır.</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="confirm_password" class="form-label">Yeni Şifre (Tekrar)</label>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">Şifreyi Değiştir</button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Tercihler Sekmesi -->
                                    <div class="tab-pane fade" id="preferences" role="tabpanel" aria-labelledby="preferences-tab">
                                        <form action="index.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="update_theme">
                                            
                                            <div class="mb-4">
                                                <label class="form-label">Tema Tercihi</label>
                                                <div class="form-text mb-2">Uygulamanın genel görünümünü ayarlayın.</div>
                                                
                                                <div class="d-flex">
                                                    <div class="form-check me-4">
                                                        <input class="form-check-input" type="radio" name="theme_preference" id="theme_light" value="light" <?php echo $theme === 'light' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="theme_light">
                                                            <i class="bi bi-sun"></i> Açık Tema
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="theme_preference" id="theme_dark" value="dark" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="theme_dark">
                                                            <i class="bi bi-moon-stars"></i> Koyu Tema
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-4">
                                                <label class="form-label">Tema Önizleme</label>
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <div class="card theme-preview light-theme">
                                                            <div class="card-header">Açık Tema</div>
                                                            <div class="card-body">
                                                                <div class="theme-preview-content">
                                                                    <button class="btn btn-primary btn-sm">Buton</button>
                                                                    <span class="badge bg-success ms-2">Etiket</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <div class="card theme-preview dark-theme">
                                                            <div class="card-header">Koyu Tema</div>
                                                            <div class="card-body">
                                                                <div class="theme-preview-content">
                                                                    <button class="btn btn-primary btn-sm">Buton</button>
                                                                    <span class="badge bg-success ms-2">Etiket</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">Tercihleri Kaydet</button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Bildirimler Sekmesi -->
                                    <div class="tab-pane fade" id="notifications" role="tabpanel" aria-labelledby="notifications-tab">
                                        <form action="index.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="update_notifications">
                                            
                                            <div class="mb-4">
                                                <label class="form-label">Bildirim Kanalları</label>
                                                <div class="form-text mb-2">Bildirimleri hangi kanallardan almak istediğinizi seçin.</div>
                                                
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="email_notifications" id="email_notifications" <?php echo $notification_settings['email_notifications'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="email_notifications">
                                                        <i class="bi bi-envelope"></i> E-posta Bildirimleri
                                                    </label>
                                                </div>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="browser_notifications" id="browser_notifications" <?php echo $notification_settings['browser_notifications'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="browser_notifications">
                                                        <i class="bi bi-browser-chrome"></i> Tarayıcı Bildirimleri
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-4">
                                                <label class="form-label">Bildirim Türleri</label>
                                                <div class="form-text mb-2">Hangi etkinlikler için bildirim almak istediğinizi seçin.</div>
                                                
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="project_updates" id="project_updates" <?php echo $notification_settings['project_updates'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="project_updates">
                                                        <i class="bi bi-folder"></i> Proje Güncellemeleri
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="meeting_reminders" id="meeting_reminders" <?php echo $notification_settings['meeting_reminders'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="meeting_reminders">
                                                        <i class="bi bi-calendar-event"></i> Toplantı Hatırlatıcıları
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="task_assignments" id="task_assignments" <?php echo $notification_settings['task_assignments'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="task_assignments">
                                                        <i class="bi bi-check2-square"></i> Görev Atamaları
                                                    </label>
                                                </div>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="system_announcements" id="system_announcements" <?php echo $notification_settings['system_announcements'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="system_announcements">
                                                        <i class="bi bi-megaphone"></i> Sistem Duyuruları
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">Bildirim Ayarlarını Kaydet</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
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
    <script src="../assets/js/notifications.js"></script>
    
    <script>
        // Şifre eşleşme kontrolü
        $('#confirm_password').on('input', function() {
            var newPassword = $('#new_password').val();
            var confirmPassword = $(this).val();
            
            if (newPassword === confirmPassword) {
                $(this).removeClass('is-invalid').addClass('is-valid');
            } else {
                $(this).removeClass('is-valid').addClass('is-invalid');
            }
        });
        
        // Profil resmi önizleme
        $('#profile_image').on('change', function() {
            if (this.files && this.files[0]) {
                var reader = new FileReader();
                
                reader.onload = function(e) {
                    $('.profile-image').attr('src', e.target.result);
                }
                
                reader.readAsDataURL(this.files[0]);
            }
        });
    </script>
</body>
</html>
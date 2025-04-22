<?php
// admin/settings.php - Sistem ayarları sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Sadece yöneticilerin erişimine izin ver
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../dashboard.php");
    exit;
}

$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Ayarları al
try {
    $stmt = $db->query("SHOW TABLES LIKE 'settings'");
    $settings_table_exists = $stmt->rowCount() > 0;
    
    if (!$settings_table_exists) {
        // Tablo yoksa oluştur
        $db->exec("
            CREATE TABLE `settings` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `setting_key` varchar(255) NOT NULL,
              `setting_value` text NOT NULL,
              `description` text,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `setting_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        // Varsayılan ayarları ekle
        $default_settings = [
            ['site_name', 'İnalsoft Yönetim Sistemi', 'Site adı'],
            ['site_description', 'Şirket içi yönetim sistemi', 'Site açıklaması'],
            ['admin_email', 'admin@inalsoft.com', 'Yönetici e-posta adresi'],
            ['items_per_page', '10', 'Sayfa başına öğe sayısı'],
            ['allow_registration', '1', 'Kullanıcı kaydına izin ver (0: Kapalı, 1: Açık)'],
            ['maintenance_mode', '0', 'Bakım modu (0: Kapalı, 1: Açık)'],
            ['default_theme', 'light', 'Varsayılan tema (light, dark, auto)'],
            ['footer_text', '© 2023 İnalsoft Tüm Hakları Saklıdır.', 'Alt bilgi metni']
        ];
        
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        foreach ($default_settings as $setting) {
            $stmt->execute($setting);
        }
    }
    
    // Tüm ayarları al
    $stmt = $db->query("SELECT * FROM settings ORDER BY setting_key ASC");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ayarları bir dizi haline getir
    $settings_array = [];
    foreach ($settings as $setting) {
        $settings_array[$setting['setting_key']] = $setting['setting_value'];
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Ayarlar alınırken bir hata oluştu: " . $e->getMessage();
    $settings = [];
    $settings_array = [];
}

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        header("Location: settings.php");
        exit;
    }
    
    $errors = [];
    
    // Genel ayarlar
    if (isset($_POST['general_settings'])) {
        try {
            $db->beginTransaction();
            
            // Ayarları güncelle
            $stmt = $db->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            
            $stmt->execute([$_POST['site_name'], 'site_name']);
            $stmt->execute([$_POST['site_description'], 'site_description']);
            $stmt->execute([$_POST['admin_email'], 'admin_email']);
            $stmt->execute([$_POST['items_per_page'], 'items_per_page']);
            $stmt->execute([$_POST['footer_text'], 'footer_text']);
            
            // Allow registration
            $allow_registration = isset($_POST['allow_registration']) ? '1' : '0';
            $stmt->execute([$allow_registration, 'allow_registration']);
            
            // Maintenance mode
            $maintenance_mode = isset($_POST['maintenance_mode']) ? '1' : '0';
            $stmt->execute([$maintenance_mode, 'maintenance_mode']);
            
            // Default theme
            $stmt->execute([$_POST['default_theme'], 'default_theme']);
            
            $db->commit();
            $_SESSION['success'] = "Genel ayarlar başarıyla güncellendi.";
            header("Location: settings.php");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Ayarlar güncellenirken bir hata oluştu: " . $e->getMessage();
        }
    }
    
    // Güvenlik ayarları
    if (isset($_POST['security_settings'])) {
        try {
            $db->beginTransaction();
            
            // Şifre politikaları
            $min_password_length = intval($_POST['min_password_length']);
            if ($min_password_length < 6) {
                $min_password_length = 6;
            }
            
            // Şifre politikası ayarlarını güncelle
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) 
                                 VALUES (?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            
            $stmt->execute(['min_password_length', $min_password_length, 'Minimum şifre uzunluğu', $min_password_length]);
            
            // Şifre karmaşıklığı
            $require_uppercase = isset($_POST['require_uppercase']) ? '1' : '0';
            $require_lowercase = isset($_POST['require_lowercase']) ? '1' : '0';
            $require_number = isset($_POST['require_number']) ? '1' : '0';
            $require_special = isset($_POST['require_special']) ? '1' : '0';
            
            $stmt->execute(['require_uppercase', $require_uppercase, 'Şifrede büyük harf zorunluluğu', $require_uppercase]);
            $stmt->execute(['require_lowercase', $require_lowercase, 'Şifrede küçük harf zorunluluğu', $require_lowercase]);
            $stmt->execute(['require_number', $require_number, 'Şifrede rakam zorunluluğu', $require_number]);
            $stmt->execute(['require_special', $require_special, 'Şifrede özel karakter zorunluluğu', $require_special]);
            
            // Şifre yenileme süresi
            $password_expiry_days = intval($_POST['password_expiry_days']);
            $stmt->execute(['password_expiry_days', $password_expiry_days, 'Şifre yenileme süresi (gün)', $password_expiry_days]);
            
            // Session timeout
            $session_timeout = intval($_POST['session_timeout']);
            $stmt->execute(['session_timeout', $session_timeout, 'Oturum zaman aşımı (dakika)', $session_timeout]);
            
            $db->commit();
            $_SESSION['success'] = "Güvenlik ayarları başarıyla güncellendi.";
            header("Location: settings.php?tab=security");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Güvenlik ayarları güncellenirken bir hata oluştu: " . $e->getMessage();
        }
    }
    
    // E-posta ayarları
    if (isset($_POST['email_settings'])) {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) 
                                 VALUES (?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
            
            $smtp_host = trim($_POST['smtp_host']);
            $smtp_port = intval($_POST['smtp_port']);
            $smtp_username = trim($_POST['smtp_username']);
            $smtp_password = trim($_POST['smtp_password']);
            $smtp_encryption = $_POST['smtp_encryption'];
            $mail_from_email = trim($_POST['mail_from_email']);
            $mail_from_name = trim($_POST['mail_from_name']);
            
            $stmt->execute(['smtp_host', $smtp_host, 'SMTP sunucu adresi', $smtp_host]);
            $stmt->execute(['smtp_port', $smtp_port, 'SMTP port', $smtp_port]);
            $stmt->execute(['smtp_username', $smtp_username, 'SMTP kullanıcı adı', $smtp_username]);
            
            // Şifre sadece girilmişse güncelle
            if (!empty($smtp_password)) {
                $stmt->execute(['smtp_password', $smtp_password, 'SMTP şifre', $smtp_password]);
            }
            
            $stmt->execute(['smtp_encryption', $smtp_encryption, 'SMTP şifreleme (ssl, tls)', $smtp_encryption]);
            $stmt->execute(['mail_from_email', $mail_from_email, 'Gönderen e-posta adresi', $mail_from_email]);
            $stmt->execute(['mail_from_name', $mail_from_name, 'Gönderen adı', $mail_from_name]);
            
            $db->commit();
            $_SESSION['success'] = "E-posta ayarları başarıyla güncellendi.";
            header("Location: settings.php?tab=email");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "E-posta ayarları güncellenirken bir hata oluştu: " . $e->getMessage();
        }
    }
}

// Get settings for the security tab
$security_settings = [
    'min_password_length' => $settings_array['min_password_length'] ?? '8',
    'require_uppercase' => $settings_array['require_uppercase'] ?? '1',
    'require_lowercase' => $settings_array['require_lowercase'] ?? '1',
    'require_number' => $settings_array['require_number'] ?? '1',
    'require_special' => $settings_array['require_special'] ?? '1',
    'password_expiry_days' => $settings_array['password_expiry_days'] ?? '90',
    'session_timeout' => $settings_array['session_timeout'] ?? '30'
];

// Get settings for the email tab
$email_settings = [
    'smtp_host' => $settings_array['smtp_host'] ?? '',
    'smtp_port' => $settings_array['smtp_port'] ?? '587',
    'smtp_username' => $settings_array['smtp_username'] ?? '',
    'smtp_password' => $settings_array['smtp_password'] ?? '',
    'smtp_encryption' => $settings_array['smtp_encryption'] ?? 'tls',
    'mail_from_email' => $settings_array['mail_from_email'] ?? $settings_array['admin_email'] ?? '',
    'mail_from_name' => $settings_array['mail_from_name'] ?? $settings_array['site_name'] ?? ''
];

// Aktif sekme
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

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
    <title>İnalsoft - Sistem Ayarları</title>
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
                        <li class="breadcrumb-item"><a href="../index.php">Ana Sayfa</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Yönetim</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Sistem Ayarları</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Sistem Ayarları</h1>
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
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Ayarlar Sekmeleri -->
                <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $active_tab === 'general' ? 'active' : ''; ?>" id="general-tab" data-bs-toggle="tab" href="#general" role="tab" aria-controls="general" aria-selected="<?php echo $active_tab === 'general' ? 'true' : 'false'; ?>">
                            <i class="bi bi-gear"></i> Genel Ayarlar
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $active_tab === 'security' ? 'active' : ''; ?>" id="security-tab" data-bs-toggle="tab" href="#security" role="tab" aria-controls="security" aria-selected="<?php echo $active_tab === 'security' ? 'true' : 'false'; ?>">
                            <i class="bi bi-shield-lock"></i> Güvenlik Ayarları
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $active_tab === 'email' ? 'active' : ''; ?>" id="email-tab" data-bs-toggle="tab" href="#email" role="tab" aria-controls="email" aria-selected="<?php echo $active_tab === 'email' ? 'true' : 'false'; ?>">
                            <i class="bi bi-envelope"></i> E-posta Ayarları
                        </a>
                    </li>
                </ul>
                
                <!-- Sekmeler İçeriği -->
                <div class="tab-content" id="settingsTabsContent">
                    <!-- Genel Ayarlar -->
                    <div class="tab-pane fade <?php echo $active_tab === 'general' ? 'show active' : ''; ?>" id="general" role="tabpanel" aria-labelledby="general-tab">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Genel Ayarlar</h5>
                            </div>
                            <div class="card-body">
                                <form action="settings.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="general_settings" value="1">
                                    
                                    <div class="mb-3">
                                        <label for="site_name" class="form-label">Site Adı</label>
                                        <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo htmlspecialchars($settings_array['site_name'] ?? ''); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="site_description" class="form-label">Site Açıklaması</label>
                                        <textarea class="form-control" id="site_description" name="site_description" rows="2"><?php echo htmlspecialchars($settings_array['site_description'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_email" class="form-label">Yönetici E-posta Adresi</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($settings_array['admin_email'] ?? ''); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="items_per_page" class="form-label">Sayfa Başına Öğe Sayısı</label>
                                        <input type="number" class="form-control" id="items_per_page" name="items_per_page" min="5" max="100" value="<?php echo htmlspecialchars($settings_array['items_per_page'] ?? '10'); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="default_theme" class="form-label">Varsayılan Tema</label>
                                        <select class="form-select" id="default_theme" name="default_theme">
                                            <option value="light" <?php echo ($settings_array['default_theme'] ?? '') === 'light' ? 'selected' : ''; ?>>Açık Tema</option>
                                            <option value="dark" <?php echo ($settings_array['default_theme'] ?? '') === 'dark' ? 'selected' : ''; ?>>Koyu Tema</option>
                                            <option value="auto" <?php echo ($settings_array['default_theme'] ?? '') === 'auto' ? 'selected' : ''; ?>>Otomatik (Sistem temasına göre)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="footer_text" class="form-label">Alt Bilgi Metni</label>
                                        <input type="text" class="form-control" id="footer_text" name="footer_text" value="<?php echo htmlspecialchars($settings_array['footer_text'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="allow_registration" name="allow_registration" <?php echo ($settings_array['allow_registration'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_registration">Kullanıcı Kaydına İzin Ver</label>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="maintenance_mode" name="maintenance_mode" <?php echo ($settings_array['maintenance_mode'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="maintenance_mode">Bakım Modu</label>
                                        <small class="form-text text-muted">Bakım modu etkinleştirildiğinde, sadece yöneticiler siteye erişebilir.</small>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Güvenlik Ayarları -->
                    <div class="tab-pane fade <?php echo $active_tab === 'security' ? 'show active' : ''; ?>" id="security" role="tabpanel" aria-labelledby="security-tab">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Güvenlik Ayarları</h5>
                            </div>
                            <div class="card-body">
                                <form action="settings.php?tab=security" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="security_settings" value="1">
                                    
                                    <h6 class="mb-3">Şifre Politikaları</h6>
                                    
                                    <div class="mb-3">
                                        <label for="min_password_length" class="form-label">Minimum Şifre Uzunluğu</label>
                                        <input type="number" class="form-control" id="min_password_length" name="min_password_length" min="6" max="50" value="<?php echo htmlspecialchars($security_settings['min_password_length']); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="require_uppercase" name="require_uppercase" <?php echo $security_settings['require_uppercase'] == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_uppercase">En Az Bir Büyük Harf Zorunlu</label>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="require_lowercase" name="require_lowercase" <?php echo $security_settings['require_lowercase'] == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_lowercase">En Az Bir Küçük Harf Zorunlu</label>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="require_number" name="require_number" <?php echo $security_settings['require_number'] == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_number">En Az Bir Rakam Zorunlu</label>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="require_special" name="require_special" <?php echo $security_settings['require_special'] == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_special">En Az Bir Özel Karakter Zorunlu</label>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="password_expiry_days" class="form-label">Şifre Yenileme Süresi (Gün)</label>
                                        <input type="number" class="form-control" id="password_expiry_days" name="password_expiry_days" min="0" max="365" value="<?php echo htmlspecialchars($security_settings['password_expiry_days']); ?>">
                                        <small class="form-text text-muted">0 değeri, şifre süresinin dolmayacağını belirtir.</small>
                                    </div>
                                    
                                    <h6 class="mb-3 mt-4">Oturum Ayarları</h6>
                                    
                                    <div class="mb-3">
                                        <label for="session_timeout" class="form-label">Oturum Zaman Aşımı (Dakika)</label>
                                        <input type="number" class="form-control" id="session_timeout" name="session_timeout" min="5" max="1440" value="<?php echo htmlspecialchars($security_settings['session_timeout']); ?>">
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">Güvenlik Ayarlarını Kaydet</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- E-posta Ayarları -->
                    <div class="tab-pane fade <?php echo $active_tab === 'email' ? 'show active' : ''; ?>" id="email" role="tabpanel" aria-labelledby="email-tab">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">E-posta Ayarları</h5>
                            </div>
                            <div class="card-body">
                                <form action="settings.php?tab=email" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="email_settings" value="1">
                                    
                                    <div class="mb-3">
                                        <label for="smtp_host" class="form-label">SMTP Sunucu</label>
                                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($email_settings['smtp_host']); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_port" class="form-label">SMTP Port</label>
                                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" min="1" max="65535" value="<?php echo htmlspecialchars($email_settings['smtp_port']); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_username" class="form-label">SMTP Kullanıcı Adı</label>
                                        <input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($email_settings['smtp_username']); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_password" class="form-label">SMTP Şifre</label>
                                        <input type="password" class="form-control" id="smtp_password" name="smtp_password" placeholder="<?php echo empty($email_settings['smtp_password']) ? '' : '********'; ?>">
                                        <small class="form-text text-muted">Şifreyi değiştirmek istemiyorsanız boş bırakın.</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="smtp_encryption" class="form-label">SMTP Şifreleme</label>
                                        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                            <option value="" <?php echo $email_settings['smtp_encryption'] === '' ? 'selected' : ''; ?>>Yok</option>
                                            <option value="ssl" <?php echo $email_settings['smtp_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="tls" <?php echo $email_settings['smtp_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="mail_from_email" class="form-label">Gönderen E-posta Adresi</label>
                                        <input type="email" class="form-control" id="mail_from_email" name="mail_from_email" value="<?php echo htmlspecialchars($email_settings['mail_from_email']); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="mail_from_name" class="form-label">Gönderen Adı</label>
                                        <input type="text" class="form-control" id="mail_from_name" name="mail_from_name" value="<?php echo htmlspecialchars($email_settings['mail_from_name']); ?>">
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">E-posta Ayarlarını Kaydet</button>
                                        <button type="button" class="btn btn-outline-primary ms-2" id="testEmail">Test E-postası Gönder</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Test E-postası Modal -->
    <div class="modal fade" id="testEmailModal" tabindex="-1" aria-labelledby="testEmailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="testEmailModalLabel">Test E-postası Gönder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_email" class="form-label">Alıcı E-posta Adresi</label>
                        <input type="email" class="form-control" id="test_email" value="<?php echo htmlspecialchars($user['email']); ?>">
                    </div>
                    <div id="emailTestResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-primary" id="sendTestEmail">Gönder</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    
    <script>
        // Aktif sekme belirleme
        $(document).ready(function() {
            // URL'den tab parametresini al
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            
            if (tab) {
                // Sekmeyi aktifleştir
                $(`#settingsTabs a[href="#${tab}"]`).tab('show');
            }
            
            // Sekme değiştiğinde URL'yi güncelle
            $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
                const target = $(e.target).attr("href").substring(1);
                history.replaceState(null, null, `?tab=${target}`);
            });
            
            // Test e-postası modalını aç
            $('#testEmail').click(function() {
                $('#testEmailModal').modal('show');
            });
            
            // Test e-postası gönder
            $('#sendTestEmail').click(function() {
                const email = $('#test_email').val();
                if (!email) {
                    $('#emailTestResult').html('<div class="alert alert-danger">Lütfen bir e-posta adresi girin.</div>');
                    return;
                }
                
                $('#emailTestResult').html('<div class="alert alert-info">E-posta gönderiliyor...</div>');
                $('#sendTestEmail').prop('disabled', true);
                
                // E-posta gönderme isteği
                $.ajax({
                    url: 'send_test_email.php',
                    type: 'POST',
                    data: {
                        email: email,
                        csrf_token: '<?php echo generate_csrf_token(); ?>'
                    },
                    success: function(response) {
                        try {
                            const result = JSON.parse(response);
                            if (result.success) {
                                $('#emailTestResult').html('<div class="alert alert-success">Test e-postası başarıyla gönderildi.</div>');
                            } else {
                                $('#emailTestResult').html(`<div class="alert alert-danger">E-posta gönderilirken bir hata oluştu: ${result.message}</div>`);
                            }
                        } catch (e) {
                            $('#emailTestResult').html('<div class="alert alert-danger">Sunucudan geçersiz yanıt alındı.</div>');
                        }
                    },
                    error: function() {
                        $('#emailTestResult').html('<div class="alert alert-danger">Sunucu ile iletişim kurulurken bir hata oluştu.</div>');
                    },
                    complete: function() {
                        $('#sendTestEmail').prop('disabled', false);
                    }
                });
            });
        });
    </script>
</body>
</html>
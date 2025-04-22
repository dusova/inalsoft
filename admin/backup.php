<?php
// admin/backup.php - Veritabanı yedekleme sistemi

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı ve admin yetkisi var mı kontrol et
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "Bu sayfaya erişim yetkiniz bulunmamaktadır.";
    header("Location: ../dashboard.php");
    exit;
}

$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Basitleştirilmiş veritabanı yedekleme versiyonu - dış komutlara bağımlı değil
$backup_dir = __DIR__ . '/../backups';
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Yedek listesini al
$backups = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'sql') {
            $file_path = $backup_dir . '/' . $file;
            $backups[] = [
                'filename' => $file,
                'size' => filesize($file_path),
                'date' => filemtime($file_path)
            ];
        }
    }
    
    // En yeni yedekler üstte olacak şekilde sırala
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// Manuel yedekleme
if (isset($_POST['create_backup'])) {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        header("Location: backup.php");
        exit;
    }
    
    $backup_filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $backup_file = $backup_dir . '/' . $backup_filename;
    
    // Yedekleme açıklaması
    $description = isset($_POST['backup_description']) ? trim($_POST['backup_description']) : '';
    
    try {
        // PHP ile yedekleme yap
        if (backup_database_php($db, $backup_file, $description)) {
            $_SESSION['success'] = "Veritabanı yedeklemesi başarıyla oluşturuldu.";
        } else {
            $_SESSION['error'] = "Yedekleme oluşturulurken bir hata oluştu.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Yedekleme oluşturulurken bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: backup.php");
    exit;
}

// Yedek silme
if (isset($_POST['delete_backup'])) {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        header("Location: backup.php");
        exit;
    }
    
    $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
    
    // Dosya adı güvenlik kontrolü
    if (empty($filename) || !preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename)) {
        $_SESSION['error'] = "Geçersiz dosya adı.";
        header("Location: backup.php");
        exit;
    }
    
    $file_path = $backup_dir . '/' . $filename;
    
    if (file_exists($file_path)) {
        if (unlink($file_path)) {
            $_SESSION['success'] = "Yedek dosyası başarıyla silindi.";
        } else {
            $_SESSION['error'] = "Yedek dosyası silinirken bir hata oluştu.";
        }
    } else {
        $_SESSION['error'] = "Belirtilen yedek dosyası bulunamadı.";
    }
    
    header("Location: backup.php");
    exit;
}

// Yedekten geri yükleme
if (isset($_POST['restore_backup'])) {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        header("Location: backup.php");
        exit;
    }
    
    $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
    
    // Dosya adı güvenlik kontrolü
    if (empty($filename) || !preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename)) {
        $_SESSION['error'] = "Geçersiz dosya adı.";
        header("Location: backup.php");
        exit;
    }
    
    $file_path = $backup_dir . '/' . $filename;
    
    if (file_exists($file_path)) {
        try {
            // Önce mevcut durumun yedeğini al
            $current_backup_filename = 'backup_before_restore_' . date('Y-m-d_H-i-s') . '.sql';
            $current_backup_file = $backup_dir . '/' . $current_backup_filename;
            
            // Mevcut durumu PHP ile yedekle
            backup_database_php($db, $current_backup_file, "Geri yükleme öncesi otomatik yedek");
            
            // Dosyadan SQL'i oku ve çalıştır
            $sql = file_get_contents($file_path);
            $statements = explode(';', $sql);
            
            $db->beginTransaction();
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $db->exec($statement);
                }
            }
            
            $db->commit();
            $_SESSION['success'] = "Veritabanı başarıyla geri yüklendi.";
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Geri yükleme sırasında bir hata oluştu: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Belirtilen yedek dosyası bulunamadı.";
    }
    
    header("Location: backup.php");
    exit;
}

// Yedekleme ayarları
$backup_settings = [
    'auto_backup_enabled' => true,
    'backup_frequency' => 'daily', // daily, weekly, monthly
    'backup_time' => '02:00', // Saat
    'max_backups' => 10, // Maksimum yedek sayısı
    'backup_retention_days' => 30 // Yedekleri tutma süresi (gün)
];

// Bildirimler
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

// Formatlar
function format_file_size($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    
    return round($size, 2) . ' ' . $units[$index];
}

// PHP ile veritabanı yedekleme fonksiyonu
function backup_database_php($db, $backup_file, $description = '') {
    try {
        // Başlık satırları
        $output = "-- inalsoft Veritabanı Yedeklemesi\n";
        if (!empty($description)) {
            $output .= "-- Backup Description: " . str_replace("\n", " ", $description) . "\n";
        }
        $output .= "-- Backup Date: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Server Version: " . $db->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET AUTOCOMMIT = 0;\n";
        $output .= "START TRANSACTION;\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        // Tablo listesini al
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Her tabloyu işle
        foreach ($tables as $table) {
            // Tablo yapısını al
            $result = $db->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_NUM);
            
            $output .= "\n--\n-- Tablo yapısı: `$table`\n--\n\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $row[1] . ";\n\n";
            
            // Tablo verilerini al
            $result = $db->query("SELECT * FROM `$table`");
            $num_fields = $result->columnCount();
            $num_rows = $result->rowCount();
            
            if ($num_rows > 0) {
                $output .= "--\n-- Tablo verisi: `$table`\n--\n\n";
                
                $field_names = [];
                for ($i = 0; $i < $num_fields; $i++) {
                    $meta = $result->getColumnMeta($i);
                    $field_names[] = "`" . $meta['name'] . "`";
                }
                
                while ($row = $result->fetch(PDO::FETCH_NUM)) {
                    $output .= "INSERT INTO `$table` (" . implode(', ', $field_names) . ") VALUES (";
                    
                    for ($i = 0; $i < $num_fields; $i++) {
                        if (is_null($row[$i])) {
                            $output .= "NULL";
                        } else {
                            $output .= "'" . addslashes($row[$i]) . "'";
                        }
                        
                        if ($i < $num_fields - 1) {
                            $output .= ", ";
                        }
                    }
                    
                    $output .= ");\n";
                }
            }
            
            $output .= "\n";
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $output .= "COMMIT;\n";
        
        // Dosyaya yaz
        if (file_put_contents($backup_file, $output)) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        // Hata durumunda false döndür
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İnalsoft - Veritabanı Yedekleme</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .backup-file {
            transition: background-color 0.2s ease;
        }
        .backup-file:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
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
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../index.php">Ana Sayfa</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Yönetim</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Veritabanı Yedekleme</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Veritabanı Yedekleme</h1>
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
                
                <div class="row mb-4">
                    <!-- Yedekleme Bilgileri -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Yedekleme Bilgileri</h5>
                            </div>
                            <div class="card-body">
                                <p><i class="bi bi-info-circle"></i> Veritabanı yedekleme, sisteminizin veri kaybına karşı korunmasını sağlar. Düzenli yedekler almak önemlidir.</p>
                                
                                <div class="mt-4">
                                    <h6><i class="bi bi-database"></i> Veritabanı</h6>
                                    <ul class="list-unstyled ms-3">
                                        <li><strong>Sunucu:</strong> <?php echo htmlspecialchars($db->getAttribute(PDO::ATTR_CONNECTION_STATUS)); ?></li>
                                        <li><strong>Veritabanı:</strong> <?php echo htmlspecialchars("Etkin Bağlantı"); ?></li>
                                    </ul>
                                </div>
                                
                                <div class="mt-3">
                                    <h6><i class="bi bi-clock-history"></i> Son Yedekleme</h6>
                                    <?php if (count($backups) > 0): ?>
                                        <ul class="list-unstyled ms-3">
                                            <li><strong>Tarih:</strong> <?php echo date('d.m.Y H:i', $backups[0]['date']); ?></li>
                                            <li><strong>Boyut:</strong> <?php echo format_file_size($backups[0]['size']); ?></li>
                                            <li><strong>Dosya:</strong> <?php echo htmlspecialchars($backups[0]['filename']); ?></li>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-muted ms-3">Henüz yedekleme yapılmamış.</p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#createBackupModal">
                                        <i class="bi bi-plus-circle"></i> Yeni Yedek Oluştur
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yedekleme Ayarları -->
                    <div class="col-md-8 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Yedek Dosyaları</h5>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="refreshBackups">
                                        <i class="bi bi-arrow-clockwise"></i> Yenile
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#scheduleBackupModal">
                                        <i class="bi bi-gear"></i> Zamanlanmış Yedekleme
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (count($backups) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Dosya Adı</th>
                                                    <th>Tarih</th>
                                                    <th>Boyut</th>
                                                    <th class="text-end">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($backups as $backup): ?>
                                                    <tr class="backup-file">
                                                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                                        <td><?php echo date('d.m.Y H:i', $backup['date']); ?></td>
                                                        <td><?php echo format_file_size($backup['size']); ?></td>
                                                        <td class="text-end">
                                                            <div class="btn-group btn-group-sm">
                                                                <a href="download_backup.php?file=<?php echo urlencode($backup['filename']); ?>&csrf_token=<?php echo generate_csrf_token(); ?>" class="btn btn-outline-primary" title="İndir">
                                                                    <i class="bi bi-download"></i>
                                                                </a>
                                                                <button type="button" class="btn btn-outline-info view-backup" data-filename="<?php echo htmlspecialchars($backup['filename']); ?>" title="Bilgileri Görüntüle">
                                                                    <i class="bi bi-info-circle"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-outline-success restore-backup" data-filename="<?php echo htmlspecialchars($backup['filename']); ?>" title="Geri Yükle">
                                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-outline-danger delete-backup" data-filename="<?php echo htmlspecialchars($backup['filename']); ?>" title="Sil">
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
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i> Henüz yedekleme yapılmamış. "Yeni Yedek Oluştur" düğmesine tıklayarak manuel yedekleme yapabilirsiniz.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Yedekleme ve Geri Yükleme Talimatları -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Yedekleme ve Geri Yükleme Talimatları</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="bi bi-save"></i> Yedekleme Hakkında</h6>
                                <ul>
                                    <li><strong>Manuel Yedekleme:</strong> "Yeni Yedek Oluştur" düğmesine tıklayarak istediğiniz zaman yedek alabilirsiniz.</li>
                                    <li><strong>Otomatik Yedekleme:</strong> Zamanlanmış yedekleme ayarlarını yapılandırarak otomatik yedekler oluşturabilirsiniz.</li>
                                    <li><strong>Yedek İndirme:</strong> İndirme simgesine tıklayarak yedek dosyasını bilgisayarınıza kaydedebilirsiniz.</li>
                                    <li><strong>Güvenlik:</strong> İndirdiğiniz yedekleri güvenli bir yerde saklamanız önerilir.</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-arrow-repeat"></i> Geri Yükleme Hakkında</h6>
                                <ul>
                                    <li><strong>Dikkat:</strong> Geri yükleme işlemi mevcut veritabanını tamamen değiştirir.</li>
                                    <li><strong>Önlem:</strong> Geri yükleme öncesinde otomatik olarak mevcut durumun yedeği alınır.</li>
                                    <li><strong>İşlem:</strong> Geri yükleme simgesine tıklayarak yedeği geri yükleyebilirsiniz.</li>
                                    <li><strong>Öneri:</strong> Geri yükleme işlemini sistem boşken yapmanız önerilir.</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Uyarı:</strong> Veritabanı geri yükleme işlemi geri alınamaz. Devam etmeden önce mevcut verilerin yedeğinin alındığından emin olun.
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Yeni Yedek Oluşturma Modalı -->
    <div class="modal fade" id="createBackupModal" tabindex="-1" aria-labelledby="createBackupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="backup.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="create_backup" value="1">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="createBackupModalLabel">Yeni Yedek Oluştur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="backup_description" class="form-label">Yedekleme Açıklaması (İsteğe Bağlı)</label>
                            <textarea class="form-control" id="backup_description" name="backup_description" rows="3" placeholder="Bu yedekleme hakkında kısa bir açıklama..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Yedekleme işlemi, veritabanının boyutuna bağlı olarak biraz zaman alabilir. İşlem tamamlanana kadar lütfen bekleyin.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Yedekle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Zamanlanmış Yedekleme Modalı -->
    <div class="modal fade" id="scheduleBackupModal" tabindex="-1" aria-labelledby="scheduleBackupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="schedule_backup.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="scheduleBackupModalLabel">Zamanlanmış Yedekleme Ayarları</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="auto_backup_enabled" name="auto_backup_enabled" <?php echo $backup_settings['auto_backup_enabled'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="auto_backup_enabled">Otomatik Yedeklemeyi Etkinleştir</label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="backup_frequency" class="form-label">Yedekleme Sıklığı</label>
                            <select class="form-select" id="backup_frequency" name="backup_frequency">
                                <option value="daily" <?php echo $backup_settings['backup_frequency'] === 'daily' ? 'selected' : ''; ?>>Günlük</option>
                                <option value="weekly" <?php echo $backup_settings['backup_frequency'] === 'weekly' ? 'selected' : ''; ?>>Haftalık</option>
                                <option value="monthly" <?php echo $backup_settings['backup_frequency'] === 'monthly' ? 'selected' : ''; ?>>Aylık</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="backup_time" class="form-label">Yedekleme Saati</label>
                            <input type="time" class="form-control" id="backup_time" name="backup_time" value="<?php echo $backup_settings['backup_time']; ?>">
                            <small class="form-text text-muted">Sunucu saatine göre (şu anki sunucu saati: <?php echo date('H:i'); ?>).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="max_backups" class="form-label">Maksimum Yedek Sayısı</label>
                            <input type="number" class="form-control" id="max_backups" name="max_backups" min="1" max="100" value="<?php echo $backup_settings['max_backups']; ?>">
                            <small class="form-text text-muted">Bu sayıyı aştığında, en eski yedekler otomatik olarak silinir.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="backup_retention_days" class="form-label">Yedekleri Tutma Süresi (Gün)</label>
                            <input type="number" class="form-control" id="backup_retention_days" name="backup_retention_days" min="1" max="365" value="<?php echo $backup_settings['backup_retention_days']; ?>">
                            <small class="form-text text-muted">Bu süreden eski yedekler otomatik olarak silinir.</small>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Not:</strong> Otomatik yedekleme için sunucu üzerinde bir cron job yapılandırılması gereklidir.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Yedek Bilgileri Modalı -->
    <div class="modal fade" id="viewBackupModal" tabindex="-1" aria-labelledby="viewBackupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBackupModalLabel">Yedek Bilgileri</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div id="backupInfo">
                        <div class="d-flex justify-content-center">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Yükleniyor...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Yedek Geri Yükleme Modalı -->
    <div class="modal fade" id="restoreBackupModal" tabindex="-1" aria-labelledby="restoreBackupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="backup.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="restore_backup" value="1">
                    <input type="hidden" name="filename" id="restore_filename" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="restoreBackupModalLabel">Yedekten Geri Yükle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Uyarı!</strong> Bu işlem mevcut veritabanını tamamen değiştirecek ve geri alınamaz.
                        </div>
                        
                        <p>Seçilen yedek: <strong id="restore_filename_display"></strong></p>
                        
                        <p>Geri yükleme öncesinde otomatik olarak mevcut durumun yedeği alınacaktır.</p>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            Geri yükleme işlemi, veritabanının boyutuna bağlı olarak biraz zaman alabilir. İşlem tamamlanana kadar lütfen bekleyin.
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirm_restore" required>
                            <label class="form-check-label" for="confirm_restore">
                                Bu işlemin geri alınamayacağını anlıyorum ve devam etmek istiyorum.
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger" id="confirmRestoreBtn" disabled>Geri Yükle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Yedek Silme Modalı -->
    <div class="modal fade" id="deleteBackupModal" tabindex="-1" aria-labelledby="deleteBackupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="backup.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="delete_backup" value="1">
                    <input type="hidden" name="filename" id="delete_filename" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteBackupModalLabel">Yedek Silme Onayı</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p>Aşağıdaki yedek dosyasını silmek istediğinizden emin misiniz?</p>
                        <p><strong id="delete_filename_display"></strong></p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            Bu işlem geri alınamaz ve dosya kalıcı olarak silinecektir.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Sil</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    
    <script>
        $(document).ready(function() {
            // Sayfa yenileme
            $('#refreshBackups').click(function() {
                location.reload();
            });
            
            // Yedek bilgileri görüntüleme
            $('.view-backup').click(function() {
                const filename = $(this).data('filename');
                
                $('#backupInfo').html('<div class="d-flex justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Yükleniyor...</span></div></div>');
                $('#viewBackupModal').modal('show');
                
                // AJAX ile yedek bilgilerini al
                $.ajax({
                    url: 'get_backup_info.php',
                    type: 'GET',
                    data: {
                        filename: filename,
                        csrf_token: '<?php echo generate_csrf_token(); ?>'
                    },
                    success: function(response) {
                        $('#backupInfo').html(response);
                    },
                    error: function() {
                        $('#backupInfo').html('<div class="alert alert-danger">Yedek bilgileri alınırken bir hata oluştu.</div>');
                    }
                });
            });
            
            // Geri yükleme modalı
            $('.restore-backup').click(function() {
                const filename = $(this).data('filename');
                
                $('#restore_filename').val(filename);
                $('#restore_filename_display').text(filename);
                $('#restoreBackupModal').modal('show');
            });
            
            // Geri yükleme onay kutusu kontrolü
            $('#confirm_restore').change(function() {
                $('#confirmRestoreBtn').prop('disabled', !this.checked);
            });
            
            // Silme modalı
            $('.delete-backup').click(function() {
                const filename = $(this).data('filename');
                
                $('#delete_filename').val(filename);
                $('#delete_filename_display').text(filename);
                $('#deleteBackupModal').modal('show');
            });
            
            // Otomatik yedekleme ayarları toggle
            $('#auto_backup_enabled').change(function() {
                const isChecked = $(this).prop('checked');
                $('#backup_frequency, #backup_time, #max_backups, #backup_retention_days').prop('disabled', !isChecked);
            });
            
            // Mevcut duruma göre form alanlarını aktif/pasif yap
            const isAutoBackupEnabled = $('#auto_backup_enabled').prop('checked');
            $('#backup_frequency, #backup_time, #max_backups, #backup_retention_days').prop('disabled', !isAutoBackupEnabled);
        });
    </script>
</body>
</html>
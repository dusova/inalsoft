
<?php
// meetings/notes.php - Toplantı notları ekleme/görüntüleme sayfası

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

// Toplantı ID'sini kontrol et
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Geçersiz toplantı ID'si.";
    header("Location: index.php");
    exit;
}

$meeting_id = intval($_GET['id']);

// Veritabanı bağlantısı
$db = connect_db();

// Toplantıyı getir
$stmt = $db->prepare("
    SELECT m.*, u.full_name as created_by_name
    FROM meetings m
    LEFT JOIN users u ON m.created_by = u.id
    WHERE m.id = :id
");
$stmt->execute(['id' => $meeting_id]);
$meeting = $stmt->fetch();

// Toplantı bulunamadıysa
if (!$meeting) {
    $_SESSION['error'] = "Toplantı bulunamadı.";
    header("Location: index.php");
    exit;
}

// Katılımcı listesini getir
$stmt = $db->prepare("
    SELECT mp.*, u.full_name, u.profile_image
    FROM meeting_participants mp
    JOIN users u ON mp.user_id = u.id
    WHERE mp.meeting_id = :meeting_id
    ORDER BY u.full_name
");
$stmt->execute(['meeting_id' => $meeting_id]);
$participants = $stmt->fetchAll();

// Toplantıya ait notları getir
$stmt = $db->prepare("
    SELECT mn.*, u.full_name, u.profile_image
    FROM meeting_notes mn
    JOIN users u ON mn.created_by = u.id
    WHERE mn.meeting_id = :meeting_id
    ORDER BY mn.created_at DESC
");
$stmt->execute(['meeting_id' => $meeting_id]);
$notes = $stmt->fetchAll();

// Not ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: notes.php?id=$meeting_id");
        exit;
    }
    
    $note_content = sanitize_input($_POST['note_content']);
    $note_type = sanitize_input($_POST['note_type']);
    
    if (empty($note_content)) {
        $_SESSION['error'] = "Not içeriği boş olamaz.";
        header("Location: notes.php?id=$meeting_id");
        exit;
    }
    
    try {
        // Notu ekle
        $stmt = $db->prepare("
            INSERT INTO meeting_notes (meeting_id, content, note_type, created_by, created_at) 
            VALUES (:meeting_id, :content, :note_type, :created_by, NOW())
        ");
        
        $result = $stmt->execute([
            'meeting_id' => $meeting_id,
            'content' => $note_content,
            'note_type' => $note_type,
            'created_by' => $_SESSION['user_id']
        ]);
        
        if ($result) {
            $note_id = $db->lastInsertId();
            
            // Aktiviteyi logla
            log_activity(
                $_SESSION['user_id'], 
                'create', 
                'meeting_note', 
                $note_id, 
                "Toplantı notu eklendi: " . substr($note_content, 0, 50) . (strlen($note_content) > 50 ? '...' : '')
            );
            
            // Başarılı mesajı
            $_SESSION['success'] = "Not başarıyla eklendi.";
        } else {
            throw new Exception("Not eklenirken bir hata oluştu.");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Not eklenirken bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: notes.php?id=$meeting_id");
    exit;
}

// Not silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_note') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: notes.php?id=$meeting_id");
        exit;
    }
    
    $note_id = intval($_POST['note_id']);
    
    // Notun sahibi mi kontrol et
    $stmt = $db->prepare("SELECT created_by FROM meeting_notes WHERE id = :id");
    $stmt->execute(['id' => $note_id]);
    $note_owner = $stmt->fetchColumn();
    
    if ($note_owner != $_SESSION['user_id'] && $_SESSION['role'] != 'admin') {
        $_SESSION['error'] = "Bu notu silme yetkiniz yok.";
        header("Location: notes.php?id=$meeting_id");
        exit;
    }
    
    try {
        // Notu sil
        $stmt = $db->prepare("DELETE FROM meeting_notes WHERE id = :id");
        $result = $stmt->execute(['id' => $note_id]);
        
        if ($result) {
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'delete', 'meeting_note', $note_id, "Toplantı notu silindi");
            
            // Başarılı mesajı
            $_SESSION['success'] = "Not başarıyla silindi.";
        } else {
            throw new Exception("Not silinirken bir hata oluştu.");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Not silinirken bir hata oluştu: " . $e->getMessage();
    }
    
    header("Location: notes.php?id=$meeting_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Toplantı Notları</title>
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
                        <li class="breadcrumb-item"><a href="index.php">Toplantılar</a></li>
                        <li class="breadcrumb-item"><a href="view.php?id=<?php echo $meeting_id; ?>"><?php echo htmlspecialchars($meeting['title']); ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Notlar</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Toplantı Notları: <?php echo htmlspecialchars($meeting['title']); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="view.php?id=<?php echo $meeting_id; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Toplantıya Dön
                            </a>
                        </div>
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
                
                <div class="row">
                    <!-- Sol sütun: Toplantı bilgileri -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Toplantı Bilgileri</h5>
                            </div>
                            <div class="card-body">
                                <p><strong>Tarih:</strong> <?php echo format_date($meeting['meeting_date'], 'd.m.Y'); ?></p>
                                <p><strong>Saat:</strong> <?php echo format_date($meeting['meeting_date'], 'H:i'); ?></p>
                                <p><strong>Süre:</strong> <?php echo $meeting['duration']; ?> dakika</p>
                                
                                <?php if (!empty($meeting['location'])): ?>
                                    <p><strong>Konum:</strong> <?php echo htmlspecialchars($meeting['location']); ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($meeting['meeting_link'])): ?>
                                    <p>
                                        <strong>Toplantı Linki:</strong> 
                                        <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank" class="text-truncate d-inline-block" style="max-width: 200px;">
                                            <?php echo htmlspecialchars($meeting['meeting_link']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                                
                                <p><strong>Oluşturan:</strong> <?php echo htmlspecialchars($meeting['created_by_name']); ?></p>
                            </div>
                        </div>
                        
                        <!-- Katılımcılar -->
                        <div class="card shadow mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Katılımcılar (<?php echo count($participants); ?>)</h5>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($participants as $participant): ?>
                                        <li class="list-group-item d-flex align-items-center">
                                            <img src="../<?php echo htmlspecialchars($participant['profile_image']); ?>" class="rounded-circle me-2" width="32" height="32" alt="Profil">
                                            <div>
                                                <?php echo htmlspecialchars($participant['full_name']); ?>
                                                <?php if ($participant['user_id'] == $meeting['created_by']): ?>
                                                    <span class="badge bg-primary">Oluşturan</span>
                                                <?php endif; ?>
                                                <?php if ($participant['user_id'] == $_SESSION['user_id']): ?>
                                                    <span class="badge bg-secondary">Siz</span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?php 
                                                        switch ($participant['status']):
                                                            case 'accepted': echo '<span class="text-success">Katılıyor</span>'; break;
                                                            case 'declined': echo '<span class="text-danger">Katılmıyor</span>'; break;
                                                            case 'tentative': echo '<span class="text-warning">Belki</span>'; break;
                                                            default: echo '<span class="text-muted">Davetli</span>';
                                                        endswitch;
                                                    ?>
                                                </small>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Toplantı gündemi -->
                        <?php if (!empty($meeting['agenda'])): ?>
                            <div class="card shadow mt-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Toplantı Gündemi</h5>
                                </div>
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($meeting['agenda'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Sağ sütun: Notlar -->
                    <div class="col-md-8">
                        <!-- Not ekleme formu -->
                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Not Ekle</h5>
                            </div>
                            <div class="card-body">
                                <form action="notes.php?id=<?php echo $meeting_id; ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="add_note">
                                    
                                    <div class="mb-3">
                                        <label for="note_type" class="form-label">Not Türü</label>
                                        <select class="form-select" id="note_type" name="note_type">
                                            <option value="agenda">Toplantı Öncesi (Gündem, Hazırlık)</option>
                                            <option value="minutes">Toplantı Sırası (Tutanak)</option>
                                            <option value="followup">Toplantı Sonrası (Takip, Görevler)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="note_content" class="form-label">Not İçeriği</label>
                                        <textarea class="form-control" id="note_content" name="note_content" rows="5" required></textarea>
                                        <div class="form-text">Markdown formatı desteklenir.</div>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">Not Ekle</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Mevcut notlar -->
                        <h4 class="mb-3">Toplantı Notları</h4>
                        
                        <?php if (count($notes) > 0): ?>
                            <?php foreach ($notes as $note): ?>
                                <div class="card shadow mb-3">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-<?php 
                                                switch ($note['note_type']) {
                                                    case 'agenda': echo 'info'; break;
                                                    case 'minutes': echo 'primary'; break;
                                                    case 'followup': echo 'success'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php 
                                                    switch ($note['note_type']) {
                                                        case 'agenda': echo 'Toplantı Öncesi'; break;
                                                        case 'minutes': echo 'Toplantı Sırası'; break;
                                                        case 'followup': echo 'Toplantı Sonrası'; break;
                                                        default: echo 'Diğer';
                                                    }
                                                ?>
                                            </span>
                                            <span class="ms-2">
                                                <img src="../<?php echo htmlspecialchars($note['profile_image']); ?>" class="rounded-circle" width="24" height="24" alt="Profil">
                                                <?php echo htmlspecialchars($note['full_name']); ?>
                                            </span>
                                            <small class="text-muted ms-2"><?php echo format_date($note['created_at']); ?></small>
                                        </div>
                                        
                                        <?php if ($note['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] == 'admin'): ?>
                                            <div>
                                                <form action="notes.php?id=<?php echo $meeting_id; ?>" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="delete_note">
                                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Bu notu silmek istediğinizden emin misiniz?')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <div class="note-content">
                                            <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i> Bu toplantı için henüz not eklenmemiş. İlk notu ekleyen siz olun!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>
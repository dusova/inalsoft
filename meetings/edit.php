<?php
// meetings/edit.php - Toplantı düzenleme sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// ID parametresini kontrol et
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Geçersiz toplantı ID'si.";
    header("Location: index.php");
    exit;
}

$meeting_id = intval($_GET['id']);

// Kullanıcı verisini al
$user = get_authenticated_user();
$theme = get_user_theme();

// Veritabanı bağlantısı
$db = connect_db();

// Toplantı bilgilerini al
$stmt = $db->prepare("
    SELECT m.*, u.full_name as created_by_name
    FROM meetings m
    JOIN users u ON m.created_by = u.id
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

// Toplantı geçmişse ve kullanıcı toplantı sahibi değilse düzenlemeye izin verme
$is_past = strtotime($meeting['meeting_date']) < time();
if ($is_past && $meeting['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = "Geçmiş toplantıları düzenleyemezsiniz.";
    header("Location: view.php?id=$meeting_id");
    exit;
}

// Kullanıcının toplantıyı düzenleme yetkisi var mı?
if ($meeting['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = "Bu toplantıyı düzenleme yetkiniz yok.";
    header("Location: view.php?id=$meeting_id");
    exit;
}

// Toplantıya katılımcıları al
$stmt = $db->prepare("
    SELECT mp.*, u.full_name, u.email
    FROM meeting_participants mp
    JOIN users u ON mp.user_id = u.id
    WHERE mp.meeting_id = :meeting_id
    ORDER BY u.full_name ASC
");
$stmt->execute(['meeting_id' => $meeting_id]);
$participants = $stmt->fetchAll();

// Katılımcı ID'lerini bir diziye topla
$participant_ids = [];
foreach ($participants as $participant) {
    $participant_ids[] = $participant['user_id'];
}

// Tüm kullanıcıları al
$stmt = $db->prepare("SELECT id, full_name FROM users ORDER BY full_name ASC");
$stmt->execute();
$users = $stmt->fetchAll();

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        header("Location: edit.php?id=$meeting_id");
        exit;
    }
    
    // Form verilerini al
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $meeting_date = isset($_POST['meeting_date']) ? $_POST['meeting_date'] : '';
    $meeting_time = isset($_POST['meeting_time']) ? $_POST['meeting_time'] : '';
    $duration = isset($_POST['duration']) ? intval($_POST['duration']) : 60;
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $meeting_link = isset($_POST['meeting_link']) ? trim($_POST['meeting_link']) : '';
    $agenda = isset($_POST['agenda']) ? trim($_POST['agenda']) : '';
    $new_participants = isset($_POST['participants']) ? $_POST['participants'] : [];
    
    // Basit doğrulama
    $errors = [];
    
    if (empty($title)) {
        $errors[] = "Toplantı başlığı gereklidir.";
    }
    
    if (empty($meeting_date)) {
        $errors[] = "Toplantı tarihi gereklidir.";
    }
    
    if (empty($meeting_time)) {
        $errors[] = "Toplantı saati gereklidir.";
    }
    
    if ($duration < 15) {
        $errors[] = "Toplantı süresi en az 15 dakika olmalıdır.";
    }
    
    // Hata yoksa güncelle
    if (empty($errors)) {
        try {
            // Tarih ve saati birleştir
            $meeting_datetime = $meeting_date . ' ' . $meeting_time;
            
            // Toplantıyı güncelle
            $stmt = $db->prepare("
                UPDATE meetings 
                SET title = :title,
                    description = :description,
                    meeting_date = :meeting_date,
                    duration = :duration,
                    location = :location,
                    meeting_link = :meeting_link,
                    agenda = :agenda,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'meeting_date' => $meeting_datetime,
                'duration' => $duration,
                'location' => $location,
                'meeting_link' => $meeting_link,
                'agenda' => $agenda,
                'id' => $meeting_id
            ]);
            
            // Katılımcıları güncelle
            // Önce mevcut katılımcıları temizle (organizatörü hariç tut)
            $stmt = $db->prepare("
                DELETE FROM meeting_participants 
                WHERE meeting_id = :meeting_id 
                AND user_id != :organizer_id
            ");
            
            $stmt->execute([
                'meeting_id' => $meeting_id,
                'organizer_id' => $meeting['created_by']
            ]);
            
            // Organizatörü katılımcı listesine ekle (eğer yoksa)
            if (!in_array($meeting['created_by'], $new_participants)) {
                $new_participants[] = $meeting['created_by'];
            }
            
            // Yeni katılımcıları ekle
            foreach ($new_participants as $user_id) {
                if (!is_numeric($user_id)) continue;
                
                $user_id = intval($user_id);
                
                // Katılımcı zaten var mı kontrol et
                $stmt = $db->prepare("
                    SELECT * FROM meeting_participants 
                    WHERE meeting_id = :meeting_id AND user_id = :user_id
                ");
                
                $stmt->execute([
                    'meeting_id' => $meeting_id,
                    'user_id' => $user_id
                ]);
                
                if (!$stmt->fetch()) {
                    // Katılımcı yoksa ekle
                    $stmt = $db->prepare("
                        INSERT INTO meeting_participants (meeting_id, user_id)
                        VALUES (:meeting_id, :user_id)
                    ");
                    
                    $stmt->execute([
                        'meeting_id' => $meeting_id,
                        'user_id' => $user_id
                    ]);
                }
            }
            
            $_SESSION['success'] = "Toplantı başarıyla güncellendi.";
            header("Location: view.php?id=$meeting_id");
            exit;
        } catch (Exception $e) {
            $errors[] = "Toplantı güncellenirken bir hata oluştu: " . $e->getMessage();
        }
    }
}

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
    <title>İnalsoft - Toplantı Düzenle | <?php echo htmlspecialchars($meeting['title']); ?></title>
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
                        <li class="breadcrumb-item active" aria-current="page">Düzenle</li>
                    </ol>
                </nav>
                
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
                
                <?php if (isset($errors) && !empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Toplantı Düzenleme Formu -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Toplantı Düzenle</h6>
                    </div>
                    <div class="card-body">
                        <form action="edit.php?id=<?php echo $meeting_id; ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="meeting_title" class="form-label">Toplantı Başlığı <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="meeting_title" name="title" required
                                           value="<?php echo htmlspecialchars($meeting['title']); ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="meeting_description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="meeting_description" name="description" rows="2"><?php echo htmlspecialchars($meeting['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="meeting_date" class="form-label">Toplantı Tarihi <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="meeting_date" name="meeting_date" required
                                           value="<?php echo date('Y-m-d', strtotime($meeting['meeting_date'])); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="meeting_time" class="form-label">Toplantı Saati <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="meeting_time" name="meeting_time" required
                                           value="<?php echo date('H:i', strtotime($meeting['meeting_date'])); ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="meeting_duration" class="form-label">Süre (dakika) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="meeting_duration" name="duration" min="15" step="15" required
                                           value="<?php echo $meeting['duration']; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="meeting_location" class="form-label">Konum</label>
                                    <input type="text" class="form-control" id="meeting_location" name="location"
                                           value="<?php echo htmlspecialchars($meeting['location'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="meeting_link" class="form-label">Toplantı Linki (online toplantılar için)</label>
                                <input type="url" class="form-control" id="meeting_link" name="meeting_link"
                                       value="<?php echo htmlspecialchars($meeting['meeting_link'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="meeting_agenda" class="form-label">Toplantı Gündemi</label>
                                <textarea class="form-control" id="meeting_agenda" name="agenda" rows="4" placeholder="Toplantıda konuşulacak konuları buraya yazabilirsiniz..."><?php echo htmlspecialchars($meeting['agenda'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Katılımcılar</label>
                                <div class="form-text mb-2">Toplantıya katılacak kişileri seçin.</div>
                                <div class="row">
                                    <?php foreach ($users as $u): ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="participants[]" 
                                                       value="<?php echo $u['id']; ?>" id="user_<?php echo $u['id']; ?>"
                                                       <?php if (in_array($u['id'], $participant_ids) || $u['id'] == $meeting['created_by']) echo 'checked'; ?>
                                                       <?php if ($u['id'] == $meeting['created_by']) echo 'disabled'; ?>>
                                                <label class="form-check-label" for="user_<?php echo $u['id']; ?>">
                                                    <?php echo htmlspecialchars($u['full_name']); ?>
                                                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                                        <span class="badge bg-secondary">Siz</span>
                                                    <?php endif; ?>
                                                    <?php if ($u['id'] == $meeting['created_by']): ?>
                                                        <span class="badge bg-primary">Organizatör</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <!-- Organizatörü her zaman ekle -->
                                <input type="hidden" name="participants[]" value="<?php echo $meeting['created_by']; ?>">
                            </div>
                            
                            <div class="mt-4 d-flex justify-content-between">
                                <a href="view.php?id=<?php echo $meeting_id; ?>" class="btn btn-secondary">İptal</a>
                                <button type="submit" class="btn btn-primary">Toplantıyı Güncelle</button>
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
        // Minimum tarih kontrolü - geçmiş toplantılar için tarihi kilitleyelim
        $(document).ready(function() {
            <?php if ($is_past): ?>
            // Geçmiş toplantılar için tarihi ve saati devre dışı bırak
            $('#meeting_date, #meeting_time').attr('readonly', true).css('background-color', '#f8f9fa');
            <?php else: ?>
            // Bugünden önceki tarihleri seçmeyi engelle
            const today = new Date().toISOString().split('T')[0];
            $('#meeting_date').attr('min', today);
            <?php endif; ?>
        });
    </script>
</body>
</html>
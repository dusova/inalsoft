<?php
// calendar/edit_event.php - Etkinlik düzenleme sayfası

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

// Event ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Geçersiz etkinlik ID'si.";
    header("Location: events.php");
    exit;
}

$event_id = intval($_GET['id']);

// Veritabanı bağlantısı
$db = connect_db();

// Etkinlik bilgilerini al
$stmt = $db->prepare("SELECT * FROM calendar_events WHERE id = :id");
$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch();

// Etkinlik bulunamadıysa
if (!$event) {
    $_SESSION['error'] = "Etkinlik bulunamadı.";
    header("Location: events.php");
    exit;
}

// İlişkili öğeleri al (projeler, görevler, toplantılar)
$related_items = [];

// Etkinlik türleri
$event_types = [
    'meeting' => 'Toplantı',
    'deadline' => 'Son Tarih',
    'reminder' => 'Hatırlatıcı',
    'other' => 'Diğer'
];

// İlişkili öğe türleri
$related_types = [
    'project' => 'Proje',
    'task' => 'Görev',
    'meeting' => 'Toplantı'
];

// İlişkili öğe verileri (eğer varsa)
$related_item_name = '';
if (!empty($event['related_type']) && !empty($event['related_id'])) {
    switch ($event['related_type']) {
        case 'project':
            $stmt = $db->prepare("SELECT name FROM projects WHERE id = :id");
            $stmt->execute(['id' => $event['related_id']]);
            $result = $stmt->fetch();
            if ($result) {
                $related_item_name = $result['name'];
            }
            break;
        case 'task':
            $stmt = $db->prepare("SELECT title as name FROM tasks WHERE id = :id");
            $stmt->execute(['id' => $event['related_id']]);
            $result = $stmt->fetch();
            if ($result) {
                $related_item_name = $result['name'];
            }
            break;
        case 'meeting':
            $stmt = $db->prepare("SELECT title as name FROM meetings WHERE id = :id");
            $stmt->execute(['id' => $event['related_id']]);
            $result = $stmt->fetch();
            if ($result) {
                $related_item_name = $result['name'];
            }
            break;
    }
}

// Etkinlik güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.";
        header("Location: edit_event.php?id=$event_id");
        exit;
    }
    
    // Form verilerini al
    $title = sanitize_input($_POST['title']);
    $event_type = sanitize_input($_POST['event_type']);
    $description = sanitize_input($_POST['description'] ?? '');
    $start_datetime = sanitize_input($_POST['start_datetime']);
    $end_datetime = sanitize_input($_POST['end_datetime']);
    $location = sanitize_input($_POST['location'] ?? '');
    $all_day = isset($_POST['all_day']) ? 1 : 0;
    $related_type = sanitize_input($_POST['related_type'] ?? '');
    $related_id = !empty($_POST['related_id']) ? intval($_POST['related_id']) : null;
    
    // Zorunlu alanları kontrol et
    if (empty($title) || empty($event_type) || empty($start_datetime) || empty($end_datetime)) {
        $_SESSION['error'] = "Lütfen tüm zorunlu alanları doldurun.";
        header("Location: edit_event.php?id=$event_id");
        exit;
    }
    
    // Tüm gün etkinliği ise saatleri ayarla
    if ($all_day) {
        $start_date = substr($start_datetime, 0, 10);
        $end_date = substr($end_datetime, 0, 10);
        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime = $end_date . ' 23:59:59';
    }
    
    // Tarih formatını kontrol et
    if (!strtotime($start_datetime) || !strtotime($end_datetime)) {
        $_SESSION['error'] = "Geçersiz tarih formatı.";
        header("Location: edit_event.php?id=$event_id");
        exit;
    }
    
    // Bitiş tarihi başlangıç tarihinden önce olamaz
    if (strtotime($end_datetime) < strtotime($start_datetime)) {
        $_SESSION['error'] = "Bitiş tarihi başlangıç tarihinden önce olamaz.";
        header("Location: edit_event.php?id=$event_id");
        exit;
    }
    
    try {
        // Etkinliği güncelle
        $stmt = $db->prepare("
            UPDATE calendar_events SET 
                title = :title,
                event_type = :event_type,
                description = :description,
                start_datetime = :start_datetime,
                end_datetime = :end_datetime,
                location = :location,
                all_day = :all_day,
                related_type = :related_type,
                related_id = :related_id,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $params = [
            'title' => $title,
            'event_type' => $event_type,
            'description' => $description,
            'start_datetime' => $start_datetime,
            'end_datetime' => $end_datetime,
            'location' => $location,
            'all_day' => $all_day,
            'related_type' => $related_type,
            'related_id' => $related_id,
            'id' => $event_id
        ];
        
        $result = $stmt->execute($params);
        
        if ($result) {
            $_SESSION['success'] = "Etkinlik başarıyla güncellendi.";
            
            // Aktiviteyi logla
            log_activity($_SESSION['user_id'], 'update', 'event', $event_id, "Etkinlik güncellendi: $title");
            
            // Yönlendirme
            header("Location: events.php");
            exit;
        } else {
            throw new Exception("Etkinlik güncellenirken bir hata oluştu.");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Etkinlik güncellenirken bir hata oluştu: " . $e->getMessage();
        header("Location: edit_event.php?id=$event_id");
        exit;
    }
}

// Datetime-local input için format düzeltmesi
function format_datetime_local($datetime) {
    if (!$datetime) return '';
    $date = new DateTime($datetime);
    return $date->format('Y-m-d\TH:i');
}

?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Etkinlik Düzenle</title>
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
                        <li class="breadcrumb-item"><a href="index.php">Takvim</a></li>
                        <li class="breadcrumb-item"><a href="events.php">Etkinlikler</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Etkinlik Düzenle</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Etkinlik Düzenle</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="events.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Etkinliklere Dön
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
                
                <!-- Etkinlik Düzenleme Formu -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Etkinlik Bilgilerini Düzenle</h6>
                    </div>
                    <div class="card-body">
                        <form action="edit_event.php?id=<?php echo $event_id; ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="title" class="form-label">Etkinlik Başlığı <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($event['title']); ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="event_type" class="form-label">Etkinlik Türü <span class="text-danger">*</span></label>
                                    <select class="form-select" id="event_type" name="event_type" required>
                                        <?php foreach ($event_types as $key => $value): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $event['event_type'] == $key ? 'selected' : ''; ?>>
                                                <?php echo $value; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($event['description']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_datetime" class="form-label">Başlangıç Tarihi ve Saati <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="start_datetime" name="start_datetime" 
                                           value="<?php echo format_datetime_local($event['start_datetime']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="end_datetime" class="form-label">Bitiş Tarihi ve Saati <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="end_datetime" name="end_datetime" 
                                           value="<?php echo format_datetime_local($event['end_datetime']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="location" class="form-label">Konum</label>
                                    <input type="text" class="form-control" id="location" name="location" value="<?php echo htmlspecialchars($event['location']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" id="all_day" name="all_day" value="1" 
                                               <?php echo $event['all_day'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="all_day">
                                            Tüm gün sürecek etkinlik
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="related_type" class="form-label">İlişkili Öğe Türü</label>
                                    <select class="form-select" id="related_type" name="related_type">
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($related_types as $key => $value): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $event['related_type'] == $key ? 'selected' : ''; ?>>
                                                <?php echo $value; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="related_id" class="form-label">İlişkili Öğe</label>
                                    <div class="input-group">
                                        <?php if (!empty($event['related_type']) && !empty($event['related_id']) && !empty($related_item_name)): ?>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($related_item_name); ?>" readonly>
                                            <input type="hidden" name="related_id" value="<?php echo $event['related_id']; ?>">
                                            <button class="btn btn-outline-secondary change-related-item" type="button">Değiştir</button>
                                        <?php else: ?>
                                            <select class="form-select" id="related_id" name="related_id" <?php echo empty($event['related_type']) ? 'disabled' : ''; ?>>
                                                <option value="">Önce öğe türü seçin</option>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Oluşturulma Tarihi</label>
                                        <input type="text" class="form-control" value="<?php echo format_date($event['created_at'], 'd.m.Y H:i'); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Son Güncelleme</label>
                                        <input type="text" class="form-control" value="<?php echo $event['updated_at'] ? format_date($event['updated_at'], 'd.m.Y H:i') : '-'; ?>" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="events.php" class="btn btn-secondary me-md-2">İptal</a>
                                <button type="submit" class="btn btn-primary">Kaydet</button>
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
            // Tüm gün etkinliği seçeneği
            const allDayCheckbox = document.getElementById('all_day');
            const startDatetimeInput = document.getElementById('start_datetime');
            const endDatetimeInput = document.getElementById('end_datetime');
            
            // Tüm gün etkinliği seçiliyse input alanlarını devre dışı bırak
            if (allDayCheckbox.checked) {
                startDatetimeInput.disabled = true;
                endDatetimeInput.disabled = true;
            }
            
            allDayCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    // Başlangıç saatini 00:00 yap
                    var startDate = startDatetimeInput.value.split('T')[0];
                    startDatetimeInput.value = startDate + 'T00:00';
                    
                    // Bitiş saatini 23:59 yap
                    var endDate = endDatetimeInput.value.split('T')[0];
                    endDatetimeInput.value = endDate + 'T23:59';
                    
                    // Zaman giriş alanlarını devre dışı bırak
                    startDatetimeInput.disabled = true;
                    endDatetimeInput.disabled = true;
                } else {
                    // Zaman giriş alanlarını etkinleştir
                    startDatetimeInput.disabled = false;
                    endDatetimeInput.disabled = false;
                }
            });
            
            // Bitiş tarihi başlangıç tarihinden önce seçilemesin
            startDatetimeInput.addEventListener('change', function() {
                if (endDatetimeInput.value < this.value) {
                    endDatetimeInput.value = this.value;
                }
            });
            
            // İlişkili öğe değiştirme butonu
            const changeRelatedItemBtn = document.querySelector('.change-related-item');
            if (changeRelatedItemBtn) {
                changeRelatedItemBtn.addEventListener('click', function() {
                    const parentDiv = this.parentElement;
                    const relatedType = document.getElementById('related_type').value;
                    
                    // Input alanını kaldır, select oluştur
                    parentDiv.innerHTML = `
                        <select class="form-select" id="related_id" name="related_id">
                            <option value="">Yükleniyor...</option>
                        </select>
                    `;
                    
                    // İlgili öğeleri getir
                    loadRelatedItems(relatedType);
                });
            }
            
            // İlişkili öğe türü değiştiğinde öğeleri getir
            document.getElementById('related_type').addEventListener('change', function() {
                var relatedType = this.value;
                
                // Mevcut ilişkili öğe varsa ve gösteriliyorsa
                const relatedItemInput = document.querySelector('.input-group input[readonly]');
                if (relatedItemInput) {
                    // Input alanını kaldır, select oluştur
                    const parentDiv = relatedItemInput.parentElement;
                    parentDiv.innerHTML = `
                        <select class="form-select" id="related_id" name="related_id">
                            <option value="">Yükleniyor...</option>
                        </select>
                    `;
                }
                
                loadRelatedItems(relatedType);
            });
            
            // İlişkili öğeleri yükle
            function loadRelatedItems(relatedType) {
                var relatedIdSelect = document.getElementById('related_id');
                
                if (relatedType) {
                    // AJAX ile ilgili öğeleri getir
                    fetch('../api/get_related_items.php?type=' + relatedType)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Select alanını doldur
                                var options = '<option value="">Seçiniz</option>';
                                data.items.forEach(item => {
                                    options += '<option value="' + item.id + '">' + item.name + '</option>';
                                });
                                
                                relatedIdSelect.innerHTML = options;
                                relatedIdSelect.disabled = false;
                            } else {
                                relatedIdSelect.innerHTML = '<option value="">Öğeler alınamadı</option>';
                                relatedIdSelect.disabled = true;
                            }
                        })
                        .catch(error => {
                            relatedIdSelect.innerHTML = '<option value="">Bir hata oluştu</option>';
                            relatedIdSelect.disabled = true;
                        });
                } else {
                    relatedIdSelect.innerHTML = '<option value="">Önce öğe türü seçin</option>';
                    relatedIdSelect.disabled = true;
                }
            }
        });
    </script>
</body>
</html>
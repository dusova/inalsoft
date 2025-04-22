<?php
// meetings/index.php - Toplantılar sayfası

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

// Filtreler
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Toplantıları al
$sql = "SELECT m.*, u.full_name as created_by_name,
        (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) as participant_count
        FROM meetings m
        JOIN users u ON m.created_by = u.id";

$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    if ($status_filter === 'upcoming') {
        $where_conditions[] = "m.meeting_date >= NOW()";
    } elseif ($status_filter === 'past') {
        $where_conditions[] = "m.meeting_date < NOW()";
    }
}

if (!empty($date_filter)) {
    if ($date_filter === 'today') {
        $where_conditions[] = "DATE(m.meeting_date) = CURDATE()";
    } elseif ($date_filter === 'week') {
        $where_conditions[] = "YEARWEEK(m.meeting_date, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($date_filter === 'month') {
        $where_conditions[] = "MONTH(m.meeting_date) = MONTH(CURDATE()) AND YEAR(m.meeting_date) = YEAR(CURDATE())";
    }
}

if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $where_conditions);
}

$sql .= " ORDER BY m.meeting_date ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$meetings = $stmt->fetchAll();

// Kullanıcıları al (toplantıya eklemek için)
$stmt = $db->prepare("SELECT id, full_name FROM users ORDER BY full_name ASC");
$stmt->execute();
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inalsoft - Toplantılar</title>
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
                    <h1 class="h2">Toplantılar</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMeetingModal">
                            <i class="bi bi-plus-lg"></i> Yeni Toplantı
                        </button>
                    </div>
                </div>
                
                <!-- Filtreler -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Filtreler</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <div class="btn-group" role="group" aria-label="Durum Filtresi">
                                            <a href="index.php<?php echo $date_filter ? "?date=$date_filter" : ''; ?>" class="btn btn-outline-primary <?php echo empty($status_filter) ? 'active' : ''; ?>">
                                                Tümü
                                            </a>
                                            <a href="index.php?status=upcoming<?php echo $date_filter ? "&date=$date_filter" : ''; ?>" class="btn btn-outline-primary <?php echo $status_filter === 'upcoming' ? 'active' : ''; ?>">
                                                Yaklaşan
                                            </a>
                                            <a href="index.php?status=past<?php echo $date_filter ? "&date=$date_filter" : ''; ?>" class="btn btn-outline-primary <?php echo $status_filter === 'past' ? 'active' : ''; ?>">
                                                Geçmiş
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <div class="btn-group" role="group" aria-label="Tarih Filtresi">
                                            <a href="index.php<?php echo $status_filter ? "?status=$status_filter" : ''; ?>" class="btn btn-outline-primary <?php echo empty($date_filter) ? 'active' : ''; ?>">
                                                Tüm Tarihler
                                            </a>
                                            <a href="index.php?date=today<?php echo $status_filter ? "&status=$status_filter" : ''; ?>" class="btn btn-outline-primary <?php echo $date_filter === 'today' ? 'active' : ''; ?>">
                                                Bugün
                                            </a>
                                            <a href="index.php?date=week<?php echo $status_filter ? "&status=$status_filter" : ''; ?>" class="btn btn-outline-primary <?php echo $date_filter === 'week' ? 'active' : ''; ?>">
                                                Bu Hafta
                                            </a>
                                            <a href="index.php?date=month<?php echo $status_filter ? "&status=$status_filter" : ''; ?>" class="btn btn-outline-primary <?php echo $date_filter === 'month' ? 'active' : ''; ?>">
                                                Bu Ay
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Toplantılar Listesi -->
                <div class="row">
                    <?php if (count($meetings) > 0): ?>
                        <?php foreach ($meetings as $meeting): ?>
                            <?php 
                                $is_past = strtotime($meeting['meeting_date']) < time();
                                $card_class = $is_past ? 'border-secondary' : 'border-primary';
                                $badge_class = $is_past ? 'bg-secondary' : 'bg-primary';
                            ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card shadow h-100 <?php echo $card_class; ?>">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="card-title mb-0"><?php echo htmlspecialchars($meeting['title']); ?></h5>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $is_past ? 'Geçmiş' : 'Yaklaşan'; ?>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <i class="bi bi-calendar-event"></i> 
                                            <strong>Tarih:</strong> <?php echo format_date($meeting['meeting_date'], 'd.m.Y'); ?>
                                        </div>
                                        <div class="mb-3">
                                            <i class="bi bi-clock"></i> 
                                            <strong>Saat:</strong> <?php echo format_date($meeting['meeting_date'], 'H:i'); ?>
                                        </div>
                                        <div class="mb-3">
                                            <i class="bi bi-hourglass-split"></i> 
                                            <strong>Süre:</strong> <?php echo $meeting['duration']; ?> dakika
                                        </div>
                                        <?php if (!empty($meeting['location'])): ?>
                                            <div class="mb-3">
                                                <i class="bi bi-geo-alt"></i> 
                                                <strong>Konum:</strong> <?php echo htmlspecialchars($meeting['location']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($meeting['meeting_link'])): ?>
                                            <div class="mb-3">
                                                <i class="bi bi-link-45deg"></i> 
                                                <strong>Toplantı Linki:</strong> 
                                                <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank">Bağlantı</a>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mb-3">
                                            <i class="bi bi-people"></i> 
                                            <strong>Katılımcılar:</strong> <?php echo $meeting['participant_count']; ?> kişi
                                        </div>
                                        <div class="mb-3">
                                            <i class="bi bi-person"></i> 
                                            <strong>Oluşturan:</strong> <?php echo htmlspecialchars($meeting['created_by_name']); ?>
                                        </div>
                                        
                                        <?php if (!empty($meeting['agenda'])): ?>
                                            <div class="mt-3">
                                                <h6 class="fw-bold">Toplantı Gündemi:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($meeting['agenda'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-footer">
                                        <a href="view.php?id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> Detaylar
                                        </a>
                                        <?php if (!$is_past): ?>
                                            <a href="edit.php?id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-warning">
                                                <i class="bi bi-pencil"></i> Düzenle
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addNotesModal" data-meeting-id="<?php echo $meeting['id']; ?>">
                                                <i class="bi bi-journal-text"></i> Not Ekle
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info text-center">
                                <i class="bi bi-info-circle me-2"></i> Belirtilen kriterlere uygun toplantı bulunmuyor.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Yaklaşan Toplantılar Takvimi -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Yaklaşan Toplantılar Takvimi</h6>
                    </div>
                    <div class="card-body">
                        <div id="meetingsTimeline" class="timeline">
                            <?php 
                                // Yaklaşan toplantıları al
                                $upcoming_meetings = array_filter($meetings, function($meeting) {
                                    return strtotime($meeting['meeting_date']) >= time();
                                });
                                
                                // Tarihe göre grupla
                                $meetings_by_date = [];
                                foreach ($upcoming_meetings as $meeting) {
                                    $date = date('Y-m-d', strtotime($meeting['meeting_date']));
                                    if (!isset($meetings_by_date[$date])) {
                                        $meetings_by_date[$date] = [];
                                    }
                                    $meetings_by_date[$date][] = $meeting;
                                }
                                
                                // Tarihleri sırala
                                ksort($meetings_by_date);
                                
                                // İlk 7 günü göster
                                $count = 0;
                                foreach ($meetings_by_date as $date => $day_meetings):
                                    if ($count++ >= 7) break;
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?php echo format_date($date, 'd M Y'); ?>
                                    </div>
                                    <div class="timeline-content">
                                        <?php foreach ($day_meetings as $meeting): ?>
                                            <div class="timeline-event">
                                                <div class="timeline-event-time">
                                                    <?php echo format_date($meeting['meeting_date'], 'H:i'); ?>
                                                </div>
                                                <div class="timeline-event-content">
                                                    <h6><?php echo htmlspecialchars($meeting['title']); ?></h6>
                                                    <p>
                                                        <?php if (!empty($meeting['location'])): ?>
                                                            <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($meeting['location']); ?><br>
                                                        <?php endif; ?>
                                                        <i class="bi bi-people"></i> <?php echo $meeting['participant_count']; ?> katılımcı
                                                    </p>
                                                    <a href="view.php?id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-outline-primary">Detaylar</a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($meetings_by_date) === 0): ?>
                                <div class="text-center py-3">
                                    <p>Yaklaşan toplantı bulunmuyor.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Yeni Toplantı Modalı -->
    <div class="modal fade" id="newMeetingModal" tabindex="-1" aria-labelledby="newMeetingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="create_meeting.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="newMeetingModalLabel">Yeni Toplantı Oluştur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="meeting_title" class="form-label">Toplantı Başlığı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="meeting_title" name="title" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="meeting_description" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="meeting_description" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="meeting_date" class="form-label">Toplantı Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="meeting_date" name="meeting_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="meeting_time" class="form-label">Toplantı Saati <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="meeting_time" name="meeting_time" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="meeting_duration" class="form-label">Süre (dakika) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="meeting_duration" name="duration" min="15" step="15" value="60" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="meeting_location" class="form-label">Konum</label>
                                <input type="text" class="form-control" id="meeting_location" name="location">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="meeting_link" class="form-label">Toplantı Linki (online toplantılar için)</label>
                            <input type="url" class="form-control" id="meeting_link" name="meeting_link">
                        </div>
                        
                        <div class="mb-3">
                            <label for="meeting_agenda" class="form-label">Toplantı Gündemi</label>
                            <textarea class="form-control" id="meeting_agenda" name="agenda" rows="4" placeholder="Toplantıda konuşulacak konuları buraya yazabilirsiniz..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Katılımcılar</label>
                            <div class="form-text mb-2">Toplantıya katılacak kişileri seçin.</div>
                            <div class="row">
                                <?php foreach ($users as $u): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="participants[]" value="<?php echo $u['id']; ?>" id="user_<?php echo $u['id']; ?>" <?php echo $u['id'] == $_SESSION['user_id'] ? 'checked disabled' : ''; ?>>
                                            <label class="form-check-label" for="user_<?php echo $u['id']; ?>">
                                                <?php echo htmlspecialchars($u['full_name']); ?>
                                                <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                                    <span class="badge bg-secondary">Siz</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="participants[]" value="<?php echo $_SESSION['user_id']; ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Toplantıyı Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Toplantı Notu Ekleme Modalı -->
    <div class="modal fade" id="addNotesModal" tabindex="-1" aria-labelledby="addNotesModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="add_notes.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" id="meeting_id" name="meeting_id" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="addNotesModalLabel">Toplantı Notları Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="meeting_notes" class="form-label">Toplantı Notları</label>
                            <textarea class="form-control" id="meeting_notes" name="notes" rows="5" placeholder="Toplantıda konuşulan konuları ve alınan kararları yazın..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Notları Kaydet</button>
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
        // Not ekleme modalına toplantı ID'si gönderme
        $('#addNotesModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var meetingId = button.data('meeting-id');
            $('#meeting_id').val(meetingId);
        });
        
        // Bugünün tarihini varsayılan olarak ayarla
        $(document).ready(function() {
            const today = new Date().toISOString().split('T')[0];
            $('#meeting_date').val(today).attr('min', today);
            
            // Şu anki saati ayarla (en yakın 15 dakikaya yuvarlayarak)
            const now = new Date();
            const hours = now.getHours();
            const minutes = Math.ceil(now.getMinutes() / 15) * 15;
            const currentTime = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
            $('#meeting_time').val(currentTime);
        });
    </script>
</body>
</html>
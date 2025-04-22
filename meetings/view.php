<?php
// meetings/view.php - Toplantı detay sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı kontrol et
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Toplantı ID kontrol et
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

// Toplantı notlarını al - Tablo yoksa hata alınabilir, bu nedenle try-catch bloğuna alıyoruz
$notes = [];
try {
    // Önce tablo var mı kontrol edelim
    $stmt = $db->query("SHOW TABLES LIKE 'meeting_notes'");
    if ($stmt->rowCount() > 0) {
        $stmt = $db->prepare("
            SELECT mn.*, u.full_name as created_by_name
            FROM meeting_notes mn
            JOIN users u ON mn.created_by = u.id
            WHERE mn.meeting_id = :meeting_id
            ORDER BY mn.created_at DESC
        ");
        $stmt->execute(['meeting_id' => $meeting_id]);
        $notes = $stmt->fetchAll();
    }
} catch (Exception $e) {
    // Tablo yok veya başka bir hata durumunda boş dizi kullan
    $notes = [];
}

// Toplantı güncellendiğinde ve geçmişse
$is_past = strtotime($meeting['meeting_date']) < time();
$can_edit = !$is_past || ($is_past && $meeting['created_by'] == $_SESSION['user_id']);

// Kullanıcı bu toplantıya katılımcı mı?
$is_participant = false;
foreach ($participants as $participant) {
    if ($participant['user_id'] == $_SESSION['user_id']) {
        $is_participant = true;
        break;
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
    <title>inalsoft - Toplantı Detayı | <?php echo htmlspecialchars($meeting['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .meeting-status {
            position: absolute;
            top: 0;
            right: 0;
            padding: 0.5rem;
            border-radius: 0 0.25rem 0 0.25rem;
        }
        
        .participant-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .note-item {
            border-left: 3px solid var(--bs-primary);
            padding-left: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .note-meta {
            color: var(--bs-gray-600);
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        
        .action-buttons .btn {
            margin-right: 0.25rem;
        }
        
        .meeting-info-item {
            margin-bottom: 0.75rem;
        }
        
        .meeting-info-icon {
            display: inline-block;
            width: 1.5rem;
            text-align: center;
            margin-right: 0.5rem;
        }
        
        .attend-status {
            margin-top: 1rem;
            padding: 0.5rem;
            border-radius: 0.25rem;
        }
        
        .agenda-section {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
            border-radius: 0.25rem;
            padding: 1rem;
            margin-top: 1.5rem;
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
                        <li class="breadcrumb-item"><a href="index.php">Toplantılar</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($meeting['title']); ?></li>
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
                
                <!-- Toplantı Başlığı -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?php echo htmlspecialchars($meeting['title']); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if ($can_edit): ?>
                            <a href="edit.php?id=<?php echo $meeting_id; ?>" class="btn btn-warning me-2">
                                <i class="bi bi-pencil"></i> Düzenle
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($is_past): ?>
                            <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addNotesModal">
                                <i class="bi bi-journal-text"></i> Not Ekle
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($meeting['created_by'] == $_SESSION['user_id']): ?>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMeetingModal">
                                <i class="bi bi-trash"></i> Sil
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Toplantı Detayları -->
                    <div class="col-md-8">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Toplantı Bilgileri</h6>
                            </div>
                            <div class="card-body">
                                <?php 
                                    $is_past = strtotime($meeting['meeting_date']) < time();
                                    $badge_class = $is_past ? 'bg-secondary' : 'bg-primary';
                                ?>
                                <div class="meeting-status">
                                    <span class="badge <?php echo $badge_class; ?> px-3 py-2">
                                        <?php echo $is_past ? 'Geçmiş' : 'Yaklaşan'; ?>
                                    </span>
                                </div>
                                
                                <?php if ($is_participant): ?>
                                    <div class="attend-status bg-success bg-opacity-10 text-success">
                                        <i class="bi bi-check-circle-fill"></i> Bu toplantıya katılımcı olarak eklendiniz.
                                        <?php if (!$is_past): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" data-bs-toggle="modal" data-bs-target="#cancelAttendanceModal">
                                                Katılımı İptal Et
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-icon"><i class="bi bi-calendar-event"></i></span>
                                            <strong>Tarih:</strong> <?php echo format_date($meeting['meeting_date'], 'd.m.Y'); ?>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-icon"><i class="bi bi-clock"></i></span>
                                            <strong>Saat:</strong> <?php echo format_date($meeting['meeting_date'], 'H:i'); ?>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-icon"><i class="bi bi-hourglass-split"></i></span>
                                            <strong>Süre:</strong> <?php echo $meeting['duration']; ?> dakika
                                        </div>
                                        <?php if (!empty($meeting['location'])): ?>
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-icon"><i class="bi bi-geo-alt"></i></span>
                                                <strong>Konum:</strong> <?php echo htmlspecialchars($meeting['location']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if (!empty($meeting['meeting_link'])): ?>
                                            <div class="meeting-info-item">
                                                <span class="meeting-info-icon"><i class="bi bi-link-45deg"></i></span>
                                                <strong>Toplantı Linki:</strong> 
                                                <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-box-arrow-up-right"></i> Toplantıya Katıl
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-icon"><i class="bi bi-person"></i></span>
                                            <strong>Oluşturan:</strong> <?php echo htmlspecialchars($meeting['created_by_name']); ?>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-icon"><i class="bi bi-calendar-plus"></i></span>
                                            <strong>Oluşturulma:</strong> <?php echo format_date($meeting['created_at']); ?>
                                        </div>
                                        <div class="meeting-info-item">
                                            <span class="meeting-info-icon"><i class="bi bi-people"></i></span>
                                            <strong>Katılımcı Sayısı:</strong> <?php echo count($participants); ?> kişi
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($meeting['description'])): ?>
                                    <div class="mt-3">
                                        <h6><i class="bi bi-info-circle"></i> Açıklama</h6>
                                        <p><?php echo nl2br(htmlspecialchars($meeting['description'])); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($meeting['agenda'])): ?>
                                    <div class="agenda-section">
                                        <h6><i class="bi bi-list-check"></i> Toplantı Gündemi</h6>
                                        <div class="mt-2">
                                            <?php echo nl2br(htmlspecialchars($meeting['agenda'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Toplantı ek bilgileri buraya eklenebilir -->
                            </div>
                        </div>
                        
                        <!-- Toplantı Notları -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Toplantı Notları</h6>
                                <?php if ($is_past): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addNotesModal">
                                        <i class="bi bi-plus-lg"></i> Not Ekle
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (count($notes) > 0): ?>
                                    <?php foreach ($notes as $note): ?>
                                        <div class="note-item">
                                            <div class="note-meta">
                                                <strong><?php echo htmlspecialchars($note['created_by_name']); ?></strong> tarafından 
                                                <?php echo format_date($note['created_at']); ?> tarihinde eklendi
                                                
                                                <?php if ($note['created_by'] == $_SESSION['user_id']): ?>
                                                    <div class="float-end">
                                                        <button type="button" class="btn btn-sm btn-link text-warning edit-note" 
                                                                data-note-id="<?php echo $note['id']; ?>"
                                                                data-note-content="<?php echo htmlspecialchars($note['content']); ?>">
                                                            <i class="bi bi-pencil"></i> Düzenle
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-link text-danger delete-note" 
                                                                data-note-id="<?php echo $note['id']; ?>"
                                                                data-bs-toggle="modal" data-bs-target="#deleteNoteModal">
                                                            <i class="bi bi-trash"></i> Sil
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="note-content">
                                                <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <p class="text-muted mb-0">Bu toplantı için henüz not eklenmemiş.</p>
                                        <?php if ($is_past): ?>
                                            <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addNotesModal">
                                                <i class="bi bi-plus-lg"></i> İlk Notu Ekle
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Not Düzenleme Modalı -->
<div class="modal fade" id="editNoteModal" tabindex="-1" aria-labelledby="editNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="edit_note.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="note_id" id="edit_note_id" value="">
                <input type="hidden" name="redirect" value="view.php?id=<?php echo $meeting_id; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="editNoteModalLabel">Not Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_note_content" class="form-label">Not İçeriği</label>
                        <textarea class="form-control" id="edit_note_content" name="note_content" rows="5" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Not Silme Modalı -->
<div class="modal fade" id="deleteNoteModal" tabindex="-1" aria-labelledby="deleteNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="delete_note.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="note_id" id="delete_note_id" value="">
                <input type="hidden" name="redirect" value="view.php?id=<?php echo $meeting_id; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteNoteModalLabel">Not Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <p>Bu notu silmek istediğinizden emin misiniz?</p>
                    <p class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Bu işlem geri alınamaz!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger">Sil</button>
                </div>
            </form>
        </div>
    </div>
</div>
                    
                    <!-- Katılımcılar ve Aksiyonlar -->
                    <div class="col-md-4">
                        <!-- Katılımcılar -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">Katılımcılar</h6>
                                <?php if ($can_edit): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addParticipantsModal">
                                        <i class="bi bi-plus-lg"></i> Ekle
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="participant-list">
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($participants as $participant): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <?php echo htmlspecialchars($participant['full_name']); ?>
                                                    <?php if ($participant['user_id'] == $_SESSION['user_id']): ?>
                                                        <span class="badge bg-secondary">Siz</span>
                                                    <?php endif; ?>
                                                    <?php if ($participant['user_id'] == $meeting['created_by']): ?>
                                                        <span class="badge bg-primary">Organizatör</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php 
                                                // Katılım durumu - meeting_participants tablosunda attended sütunu yoksa hata alınabilir
                                                $attended = isset($participant['attended']) ? $participant['attended'] : null;
                                                if ($is_past && $attended !== null): 
                                                ?>
                                                    <span class="badge <?php echo $attended ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $attended ? 'Katıldı' : 'Katılmadı'; ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if (!$is_past): ?>
                                                    <?php if ($meeting['created_by'] == $_SESSION['user_id'] && $participant['user_id'] != $_SESSION['user_id']): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-participant" 
        data-participant-id="<?php echo $participant['user_id']; ?>"
        data-participant-name="<?php echo htmlspecialchars($participant['full_name']); ?>"
        data-bs-toggle="modal" 
        data-bs-target="#removeParticipantModal">
    <i class="bi bi-x"></i>
</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Katılım Durumu (Geçmiş toplantılar için) -->
                        <?php 
                        // Katılım durumu - meeting_participants tablosunda attended sütunu yoksa gösterme
                        $has_attendance = false;
                        foreach ($participants as $p) {
                            if (isset($p['attended'])) {
                                $has_attendance = true;
                                break;
                            }
                        }
                        if ($is_past && $has_attendance && $meeting['created_by'] == $_SESSION['user_id']): 
                        ?>
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Katılım Durumu</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <p>Toplantıya katılan ve katılmayan kişileri işaretleyebilirsiniz.</p>
                                    </div>
                                    <form action="update_attendance.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="meeting_id" value="<?php echo $meeting_id; ?>">
                                        
                                        <ul class="list-group mb-3">
                                            <?php foreach ($participants as $participant): ?>
                                                <li class="list-group-item">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="attended[]" 
                                                               value="<?php echo $participant['user_id']; ?>" 
                                                               id="attend_<?php echo $participant['user_id']; ?>"
                                                               <?php echo (isset($participant['attended']) && $participant['attended']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="attend_<?php echo $participant['user_id']; ?>">
                                                            <?php echo htmlspecialchars($participant['full_name']); ?>
                                                        </label>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        
                                        <button type="submit" class="btn btn-primary">Katılım Durumunu Güncelle</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Toplantı Bağlantıları -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Aksiyon</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <?php if (!$is_past): ?>
                                        <?php if (!empty($meeting['meeting_link'])): ?>
                                            <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank" class="btn btn-primary">
                                                <i class="bi bi-camera-video"></i> Toplantıya Katıl
                                            </a>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="btn btn-outline-primary" onclick="addToCalendar()">
                                            <i class="bi bi-calendar-plus"></i> Takvime Ekle
                                        </button>
                                    <?php endif; ?>
                                    
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Toplantı Listesine Dön
                                    </a>
                                    
                                    <?php if ($meeting['created_by'] == $_SESSION['user_id']): ?>
                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteMeetingModal">
                                            <i class="bi bi-trash"></i> Toplantıyı Sil
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Not Ekleme Modalı -->
    <div class="modal fade" id="addNotesModal" tabindex="-1" aria-labelledby="addNotesModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="add_notes.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="meeting_id" value="<?php echo $meeting_id; ?>">
                    <input type="hidden" name="redirect" value="view.php?id=<?php echo $meeting_id; ?>">
                    
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


    
    <!-- Katılımcı Ekleme Modalı -->
    <div class="modal fade" id="addParticipantsModal" tabindex="-1" aria-labelledby="addParticipantsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="add_participants.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="meeting_id" value="<?php echo $meeting_id; ?>">
                    <input type="hidden" name="redirect" value="view.php?id=<?php echo $meeting_id; ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="addParticipantsModalLabel">Katılımcı Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="form-text mb-2">Toplantıya katılacak ek kişileri seçin.</div>
                            
                            <?php
                                // Mevcut katılımcıların ID'lerini bir diziye ekle
                                $existing_participants = [];
                                foreach ($participants as $participant) {
                                    $existing_participants[] = $participant['user_id'];
                                }
                                
                                // Kullanıcıları al
                                $stmt = $db->prepare("SELECT id, full_name FROM users ORDER BY full_name ASC");
                                $stmt->execute();
                                $all_users = $stmt->fetchAll();
                            ?>
                            
                            <div class="participant-list" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($all_users as $u): ?>
                                    <?php if (!in_array($u['id'], $existing_participants)): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="participants[]" 
                                                   value="<?php echo $u['id']; ?>" id="add_user_<?php echo $u['id']; ?>">
                                            <label class="form-check-label" for="add_user_<?php echo $u['id']; ?>">
                                                <?php echo htmlspecialchars($u['full_name']); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Katılımcıları Ekle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Katılımcı Çıkarma Modalı -->
    <div class="modal fade" id="removeParticipantModal" tabindex="-1" aria-labelledby="removeParticipantModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="remove_participant.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="participant_id" id="remove_participant_id" value="">
                    <input type="hidden" name="meeting_id" value="<?php echo $meeting_id; ?>">
                    <input type="hidden" name="redirect" value="view.php?id=<?php echo $meeting_id; ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="removeParticipantModalLabel">Katılımcıyı Çıkar</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><span id="remove_participant_name"></span> isimli katılımcıyı toplantıdan çıkarmak istediğinizden emin misiniz?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Çıkar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Katılımı İptal Modalı -->
    <div class="modal fade" id="cancelAttendanceModal" tabindex="-1" aria-labelledby="cancelAttendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="cancel_attendance.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="meeting_id" value="<?php echo $meeting_id; ?>">
                    <input type="hidden" name="redirect" value="view.php?id=<?php echo $meeting_id; ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="cancelAttendanceModalLabel">Katılımı İptal Et</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p>Bu toplantıya katılımınızı iptal etmek istediğinizden emin misiniz?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" class="btn btn-danger">Katılımı İptal Et</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Toplantı Silme Modalı -->
    <div class="modal fade" id="deleteMeetingModal" tabindex="-1" aria-labelledby="deleteMeetingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="delete_meeting.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="meeting_id" value="<?php echo $meeting_id; ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteMeetingModalLabel">Toplantıyı Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> <strong><?php echo htmlspecialchars($meeting['title']); ?></strong> toplantısını silmek istediğinizden emin misiniz?</p>
                        <p>Bu işlem geri alınamaz ve tüm toplantı notları, katılımcı bilgileri silinecektir.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Toplantıyı Sil</button>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Katılımcı çıkarma modalını hazırla
            const removeButtons = document.querySelectorAll('.remove-participant');
            removeButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const participantId = button.getAttribute('data-participant-id');
                    const participantName = button.getAttribute('data-participant-name');
                    
                    document.getElementById('remove_participant_id').value = participantId;
                    document.getElementById('remove_participant_name').textContent = participantName;
                });
            });
        });
        
        // Takvime ekle fonksiyonu
        function addToCalendar() {
            const title = "<?php echo addslashes($meeting['title']); ?>";
            const description = "<?php echo addslashes($meeting['description'] ?: ''); ?>";
            const location = "<?php echo addslashes($meeting['location'] ?: ''); ?>";
            const startDate = "<?php echo date('Y-m-d\TH:i:s', strtotime($meeting['meeting_date'])); ?>";
            const endDate = "<?php echo date('Y-m-d\TH:i:s', strtotime($meeting['meeting_date']) + ($meeting['duration'] * 60)); ?>";
            
            // Google Calendar link
            const googleCalendarUrl = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(title)}&dates=${encodeURIComponent(startDate.replace(/[-:]/g, ''))
                .replace('.000', 'Z')}/${encodeURIComponent(endDate.replace(/[-:]/g, '')).replace('.000', 'Z')}&details=${encodeURIComponent(description)}&location=${encodeURIComponent(location)}`;
            
            // Yeni pencerede aç
            window.open(googleCalendarUrl, '_blank');
        }
    </script>

<script>
    // Not düzenleme ve silme işlemleri
    document.addEventListener('DOMContentLoaded', function() {
        // Not düzenleme modalı
        const editButtons = document.querySelectorAll('.edit-note');
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const noteId = this.getAttribute('data-note-id');
                const noteContent = this.getAttribute('data-note-content');
                
                document.getElementById('edit_note_id').value = noteId;
                document.getElementById('edit_note_content').value = noteContent;
                
                const editNoteModal = new bootstrap.Modal(document.getElementById('editNoteModal'));
                editNoteModal.show();
            });
        });
        
        // Not silme modalı
        const deleteButtons = document.querySelectorAll('.delete-note');
        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const noteId = this.getAttribute('data-note-id');
                
                document.getElementById('delete_note_id').value = noteId;
                
                const deleteNoteModal = new bootstrap.Modal(document.getElementById('deleteNoteModal'));
                deleteNoteModal.show();
            });
        });
    });
</script>
</body>
</html>
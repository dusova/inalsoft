<?php
// admin/reports.php - Raporlar sayfası

session_start();
require_once '../config/database.php';

// Kullanıcı giriş yapmış mı ve admin yetkisi var mı kontrol et
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "Bu sayfaya erişim yetkiniz bulunmamaktadır.";
    header("Location: ../dashboard.php");
    exit;
}

$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// Veritabanı bağlantısı
$db = connect_db();

// Rapor türünü al
$report_type = isset($_GET['type']) ? $_GET['type'] : 'meetings';

// Tarih aralığı filtresi
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Rapor verisini al
$report_data = [];
$chart_data = [];

// Toplantı istatistikleri
$meeting_stats = [
    'total' => 0,
    'upcoming' => 0,
    'past' => 0,
    'this_month' => 0,
    'avg_duration' => 0,
    'most_active_day' => '',
    'most_active_hour' => '',
    'most_active_user' => ''
];

// Kullanıcı istatistikleri
$user_stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'admins' => 0,
    'last_registered' => '',
    'most_meetings' => ''
];

// Toplantılar raporu
if ($report_type === 'meetings') {
    try {
        // Toplam toplantı sayısı
        $stmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM meetings
            WHERE DATE(meeting_date) BETWEEN :start_date AND :end_date
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['total'] = $result['total'];
        
        // Yaklaşan toplantılar
        $stmt = $db->prepare("
            SELECT COUNT(*) as upcoming
            FROM meetings
            WHERE meeting_date > NOW()
            AND DATE(meeting_date) BETWEEN :start_date AND :end_date
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['upcoming'] = $result['upcoming'];
        
        // Geçmiş toplantılar
        $stmt = $db->prepare("
            SELECT COUNT(*) as past
            FROM meetings
            WHERE meeting_date <= NOW()
            AND DATE(meeting_date) BETWEEN :start_date AND :end_date
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['past'] = $result['past'];
        
        // Bu ay oluşturulan toplantılar
        $stmt = $db->prepare("
            SELECT COUNT(*) as this_month
            FROM meetings
            WHERE YEAR(meeting_date) = YEAR(CURRENT_DATE()) 
            AND MONTH(meeting_date) = MONTH(CURRENT_DATE())
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        $meeting_stats['this_month'] = $result['this_month'];
        
        // Ortalama toplantı süresi
        $stmt = $db->prepare("
            SELECT AVG(duration) as avg_duration
            FROM meetings
            WHERE DATE(meeting_date) BETWEEN :start_date AND :end_date
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['avg_duration'] = round($result['avg_duration'] ?? 0);
        
        // En aktif gün
        $stmt = $db->prepare("
            SELECT DAYNAME(meeting_date) as day_name, COUNT(*) as count
            FROM meetings
            WHERE DATE(meeting_date) BETWEEN :start_date AND :end_date
            GROUP BY day_name
            ORDER BY count DESC
            LIMIT 1
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['most_active_day'] = $result ? translate_day($result['day_name']) . ' (' . $result['count'] . ')' : 'Veri yok';
        
        // En aktif saat
        $stmt = $db->prepare("
            SELECT HOUR(meeting_date) as hour, COUNT(*) as count
            FROM meetings
            WHERE DATE(meeting_date) BETWEEN :start_date AND :end_date
            GROUP BY hour
            ORDER BY count DESC
            LIMIT 1
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['most_active_hour'] = $result ? sprintf('%02d:00', $result['hour']) . ' (' . $result['count'] . ')' : 'Veri yok';
        
        // En aktif kullanıcı (en çok toplantı oluşturan)
        $stmt = $db->prepare("
            SELECT u.full_name, COUNT(m.id) as meeting_count
            FROM meetings m
            JOIN users u ON m.created_by = u.id
            WHERE DATE(m.meeting_date) BETWEEN :start_date AND :end_date
            GROUP BY m.created_by
            ORDER BY meeting_count DESC
            LIMIT 1
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch();
        $meeting_stats['most_active_user'] = $result ? $result['full_name'] . ' (' . $result['meeting_count'] . ')' : 'Veri yok';
        
        // Aylara göre toplantı sayısı (grafik için)
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(meeting_date, '%Y-%m') as month,
                COUNT(*) as count
            FROM meetings
            WHERE DATE(meeting_date) BETWEEN :start_date AND :end_date
            GROUP BY month
            ORDER BY month ASC
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $monthly_meetings = $stmt->fetchAll();
        
        foreach ($monthly_meetings as $month) {
            $month_name = date('M Y', strtotime($month['month'] . '-01'));
            $chart_data[] = [
                'label' => $month_name,
                'value' => $month['count']
            ];
        }
        
        // Toplantı listesi
        $stmt = $db->prepare("
            SELECT 
                m.id, m.title, m.meeting_date, m.duration, m.location,
                COUNT(mp.user_id) as participant_count,
                u.full_name as created_by
            FROM meetings m
            LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
            JOIN users u ON m.created_by = u.id
            WHERE DATE(m.meeting_date) BETWEEN :start_date AND :end_date
            GROUP BY m.id
            ORDER BY m.meeting_date DESC
            LIMIT 50
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $report_data = $stmt->fetchAll();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Rapor verileri alınırken bir hata oluştu: " . $e->getMessage();
    }
}
// Kullanıcılar raporu
elseif ($report_type === 'users') {
    try {
        // Toplam kullanıcı sayısı
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM users");
        $stmt->execute();
        $result = $stmt->fetch();
        $user_stats['total'] = $result['total'];
        
        // Aktif/pasif kullanıcı sayısı (eğer status alanı varsa)
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) as active FROM users WHERE status = 1");
            $stmt->execute();
            $result = $stmt->fetch();
            $user_stats['active'] = $result['active'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as inactive FROM users WHERE status = 0");
            $stmt->execute();
            $result = $stmt->fetch();
            $user_stats['inactive'] = $result['inactive'];
        } else {
            $user_stats['active'] = $user_stats['total'];
            $user_stats['inactive'] = 0;
        }
        
        // Admin sayısı
        $stmt = $db->prepare("SELECT COUNT(*) as admins FROM users WHERE role = 'admin'");
        $stmt->execute();
        $result = $stmt->fetch();
        $user_stats['admins'] = $result['admins'];
        
        // Son kayıt olan kullanıcı
        $stmt = $db->prepare("
            SELECT full_name, created_at
            FROM users
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        $user_stats['last_registered'] = $result ? $result['full_name'] . ' (' . format_date($result['created_at']) . ')' : 'Veri yok';
        
        // En çok toplantı oluşturan kullanıcı
        $stmt = $db->prepare("
            SELECT u.full_name, COUNT(m.id) as meeting_count
            FROM users u
            LEFT JOIN meetings m ON u.id = m.created_by
            GROUP BY u.id
            ORDER BY meeting_count DESC
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        $user_stats['most_meetings'] = $result && $result['meeting_count'] > 0 ? 
            $result['full_name'] . ' (' . $result['meeting_count'] . ')' : 'Veri yok';
        
        // Aylara göre kayıt olan kullanıcı sayısı (grafik için)
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count
            FROM users
            WHERE DATE(created_at) BETWEEN :start_date AND :end_date
            GROUP BY month
            ORDER BY month ASC
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $monthly_users = $stmt->fetchAll();
        
        foreach ($monthly_users as $month) {
            $month_name = date('M Y', strtotime($month['month'] . '-01'));
            $chart_data[] = [
                'label' => $month_name,
                'value' => $month['count']
            ];
        }
        
        // Kullanıcı listesi
        $stmt = $db->prepare("
            SELECT 
                u.id, u.full_name, u.email, u.created_at,
                COUNT(DISTINCT m.id) as created_meetings,
                COUNT(DISTINCT mp.meeting_id) as participated_meetings,
                u.role
            FROM users u
            LEFT JOIN meetings m ON u.id = m.created_by
            LEFT JOIN meeting_participants mp ON u.id = mp.user_id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Rapor verileri alınırken bir hata oluştu: " . $e->getMessage();
    }
}
// Katılım raporu
elseif ($report_type === 'attendance') {
    try {
        // Toplantı katılım verilerini al
        $stmt = $db->prepare("
            SELECT 
                m.id, m.title, m.meeting_date,
                COUNT(mp.user_id) as participant_count,
                SUM(CASE WHEN mp.attended = 1 THEN 1 ELSE 0 END) as attended_count,
                u.full_name as created_by
            FROM meetings m
            LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
            JOIN users u ON m.created_by = u.id
            WHERE DATE(m.meeting_date) BETWEEN :start_date AND :end_date
            AND m.meeting_date < NOW()
            GROUP BY m.id
            ORDER BY m.meeting_date DESC
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $report_data = $stmt->fetchAll();
        
        // Grafik için katılım oranları
        foreach ($report_data as $meeting) {
            if ($meeting['participant_count'] > 0) {
                $attendance_rate = round(($meeting['attended_count'] / $meeting['participant_count']) * 100);
                $chart_data[] = [
                    'label' => mb_substr($meeting['title'], 0, 20) . (mb_strlen($meeting['title']) > 20 ? '...' : ''),
                    'value' => $attendance_rate
                ];
            }
        }
        
        // Son 10 toplantı için grafiği sınırlayalım
        $chart_data = array_slice($chart_data, 0, 10);
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Rapor verileri alınırken bir hata oluştu: " . $e->getMessage();
    }
}

// Türkçe gün adlarını çevir
function translate_day($day_name) {
    $days = [
        'Monday' => 'Pazartesi',
        'Tuesday' => 'Salı',
        'Wednesday' => 'Çarşamba',
        'Thursday' => 'Perşembe',
        'Friday' => 'Cuma',
        'Saturday' => 'Cumartesi',
        'Sunday' => 'Pazar'
    ];
    
    return $days[$day_name] ?? $day_name;
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
    <title>İnalsoft - Raporlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <li class="breadcrumb-item active" aria-current="page">Raporlar</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Raporlar</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExportPDF">
                                <i class="bi bi-file-pdf"></i> PDF
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExportExcel">
                                <i class="bi bi-file-excel"></i> Excel
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPrint">
                                <i class="bi bi-printer"></i> Yazdır
                            </button>
                        </div>
                    </div>
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
                
                <!-- Rapor Filtreler -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Rapor Filtreleri</h6>
                    </div>
                    <div class="card-body">
                        <form action="reports.php" method="get" class="row">
                            <div class="col-md-3 mb-3">
                                <label for="report_type" class="form-label">Rapor Türü</label>
                                <select class="form-select" id="report_type" name="type">
                                    <option value="meetings" <?php echo $report_type === 'meetings' ? 'selected' : ''; ?>>Toplantı Raporu</option>
                                    <option value="users" <?php echo $report_type === 'users' ? 'selected' : ''; ?>>Kullanıcı Raporu</option>
                                    <option value="attendance" <?php echo $report_type === 'attendance' ? 'selected' : ''; ?>>Katılım Raporu</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="start_date" class="form-label">Başlangıç Tarihi</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="end_date" class="form-label">Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            
                            <div class="col-md-3 mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Raporu Getir
                                </button>
                            </div>
                        </form>
                        
                        <div class="row mt-2">
                            <div class="col-auto">
                                <div class="btn-group" role="group" aria-label="Hızlı Tarih Seçimi">
                                    <a href="reports.php?type=<?php echo $report_type; ?>&start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">Son 7 Gün</a>
                                    <a href="reports.php?type=<?php echo $report_type; ?>&start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">Son 30 Gün</a>
                                    <a href="reports.php?type=<?php echo $report_type; ?>&start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">Bu Ay</a>
                                    <a href="reports.php?type=<?php echo $report_type; ?>&start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">Bu Yıl</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Rapor İçeriği -->
                <div class="row" id="printableArea">
                    <!-- Rapor Özeti -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <?php echo $report_type === 'meetings' ? 'Toplantı Özeti' : ($report_type === 'users' ? 'Kullanıcı Özeti' : 'Katılım Özeti'); ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if ($report_type === 'meetings'): ?>
                                    <div class="row mb-2">
                                        <div class="col">
                                            <div class="card bg-primary bg-opacity-10 h-100">
                                                <div class="card-body text-center">
                                                    <h1 class="display-4"><?php echo $meeting_stats['total']; ?></h1>
                                                    <p class="mb-0">Toplam Toplantı</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card bg-success bg-opacity-10 h-100">
                                                <div class="card-body text-center">
                                                    <h1 class="display-4"><?php echo $meeting_stats['upcoming']; ?></h1>
                                                    <p class="mb-0">Yaklaşan</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card bg-secondary bg-opacity-10 h-100">
                                                <div class="card-body text-center">
                                                    <h1 class="display-4"><?php echo $meeting_stats['past']; ?></h1>
                                                    <p class="mb-0">Geçmiş</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-calendar-check me-2"></i> Bu Ay Oluşturulan</span>
                                                <span class="badge bg-primary rounded-pill"><?php echo $meeting_stats['this_month']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-clock me-2"></i> Ortalama Süre</span>
                                                <span class="badge bg-info rounded-pill"><?php echo $meeting_stats['avg_duration']; ?> dk</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-calendar-day me-2"></i> En Aktif Gün</span>
                                                <span class="badge bg-success rounded-pill"><?php echo $meeting_stats['most_active_day']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-alarm me-2"></i> En Aktif Saat</span>
                                                <span class="badge bg-warning rounded-pill"><?php echo $meeting_stats['most_active_hour']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-person me-2"></i> En Aktif Kullanıcı</span>
                                                <span class="badge bg-danger rounded-pill"><?php echo $meeting_stats['most_active_user']; ?></span>
                                            </li>
                                        </ul>
                                    </div>
                                <?php elseif ($report_type === 'users'): ?>
                                    <div class="row mb-2">
                                        <div class="col">
                                            <div class="card bg-primary bg-opacity-10 h-100">
                                                <div class="card-body text-center">
                                                    <h1 class="display-4"><?php echo $user_stats['total']; ?></h1>
                                                    <p class="mb-0">Toplam Kullanıcı</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card bg-success bg-opacity-10 h-100">
                                                <div class="card-body text-center">
                                                    <h1 class="display-4"><?php echo $user_stats['active']; ?></h1>
                                                    <p class="mb-0">Aktif</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card bg-secondary bg-opacity-10 h-100">
                                                <div class="card-body text-center">
                                                    <h1 class="display-4"><?php echo $user_stats['admins']; ?></h1>
                                                    <p class="mb-0">Admin</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-person-dash me-2"></i> Pasif Kullanıcılar</span>
                                                <span class="badge bg-secondary rounded-pill"><?php echo $user_stats['inactive']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-person-check me-2"></i> Son Kayıt Olan</span>
                                                <span class="badge bg-info rounded-pill"><?php echo $user_stats['last_registered']; ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><i class="bi bi-calendar-event me-2"></i> En Çok Toplantı Oluşturan</span>
                                                <span class="badge bg-success rounded-pill"><?php echo $user_stats['most_meetings']; ?></span>
                                            </li>
                                        </ul>
                                    </div>
                                <?php elseif ($report_type === 'attendance'): ?>
                                    <div class="text-center p-4 mb-3">
                                        <h1 class="display-4">Katılım Raporu</h1>
                                        <p class="lead">
                                            <?php echo date('d.m.Y', strtotime($start_date)) . ' - ' . date('d.m.Y', strtotime($end_date)); ?>
                                        </p>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        Bu rapor, belirtilen tarih aralığındaki toplantılara katılım oranlarını gösterir.
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        Katılım durumu, toplantı sahibi tarafından işaretlenmektedir.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Grafik -->
                    <div class="col-md-8 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <?php echo $report_type === 'meetings' ? 'Aylara Göre Toplantı Sayısı' : 
                                                 ($report_type === 'users' ? 'Aylara Göre Kullanıcı Kaydı' : 'Toplantı Katılım Oranları'); ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <canvas id="reportChart" style="height: 300px;"></canvas>
                                
                                <?php if (empty($chart_data)): ?>
                                    <div class="alert alert-info mt-3">
                                        <i class="bi bi-info-circle me-2"></i>
                                        Seçilen tarih aralığında görüntülenecek veri bulunmuyor.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Detaylı Veriler -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <?php echo $report_type === 'meetings' ? 'Toplantı Listesi' : 
                                         ($report_type === 'users' ? 'Kullanıcı Listesi' : 'Toplantı Katılım Detayları'); ?>
                        </h6>
                        <div class="input-group w-25">
                            <input type="text" class="form-control form-control-sm" placeholder="Ara..." id="tableSearch">
                            <button class="btn btn-sm btn-outline-secondary" type="button">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($report_data) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped" id="dataTable">
                                    <thead>
                                        <?php if ($report_type === 'meetings'): ?>
                                            <tr>
                                                <th>Başlık</th>
                                                <th>Tarih</th>
                                                <th>Süre</th>
                                                <th>Konum</th>
                                                <th>Katılımcı Sayısı</th>
                                                <th>Oluşturan</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        <?php elseif ($report_type === 'users'): ?>
                                            <tr>
                                                <th>Kullanıcı Adı</th>
                                                <th>E-posta</th>
                                                <th>Oluşturduğu Toplantılar</th>
                                                <th>Katıldığı Toplantılar</th>
                                                <th>Kayıt Tarihi</th>
                                                <th>Yönetici</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        <?php elseif ($report_type === 'attendance'): ?>
                                            <tr>
                                                <th>Toplantı</th>
                                                <th>Tarih</th>
                                                <th>Katılımcı Sayısı</th>
                                                <th>Katılan Sayısı</th>
                                                <th>Katılım Oranı</th>
                                                <th>Oluşturan</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        <?php endif; ?>
                                    </thead>
                                    <tbody>
                                        <?php if ($report_type === 'meetings'): ?>
                                            <?php foreach ($report_data as $meeting): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($meeting['title']); ?></td>
                                                    <td><?php echo format_date($meeting['meeting_date']); ?></td>
                                                    <td><?php echo $meeting['duration']; ?> dk</td>
                                                    <td><?php echo htmlspecialchars($meeting['location'] ?: '-'); ?></td>
                                                    <td><?php echo $meeting['participant_count']; ?></td>
                                                    <td><?php echo htmlspecialchars($meeting['created_by']); ?></td>
                                                    <td>
                                                        <a href="../meetings/view.php?id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php elseif ($report_type === 'users'): ?>
                                            <?php foreach ($report_data as $user): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                    <td><?php echo $user['created_meetings']; ?></td>
                                                    <td><?php echo $user['participated_meetings']; ?></td>
                                                    <td><?php echo format_date($user['created_at']); ?></td>
                                                    <td>
                                                        <?php if ($user['role'] === 'admin'): ?>
                                                            <span class="badge bg-primary">Evet</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Hayır</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="user_details.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php elseif ($report_type === 'attendance'): ?>
                                            <?php foreach ($report_data as $meeting): ?>
                                                <?php 
                                                    $attendance_rate = $meeting['participant_count'] > 0 
                                                        ? round(($meeting['attended_count'] / $meeting['participant_count']) * 100) 
                                                        : 0;
                                                    
                                                    $badge_class = 'bg-danger';
                                                    if ($attendance_rate >= 75) {
                                                        $badge_class = 'bg-success';
                                                    } elseif ($attendance_rate >= 50) {
                                                        $badge_class = 'bg-warning';
                                                    } elseif ($attendance_rate >= 25) {
                                                        $badge_class = 'bg-info';
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($meeting['title']); ?></td>
                                                    <td><?php echo format_date($meeting['meeting_date']); ?></td>
                                                    <td><?php echo $meeting['participant_count']; ?></td>
                                                    <td><?php echo $meeting['attended_count']; ?></td>
                                                    <td>
                                                        <div class="progress">
                                                            <div class="progress-bar <?php echo $badge_class; ?>" role="progressbar" 
                                                                 style="width: <?php echo $attendance_rate; ?>%;" 
                                                                 aria-valuenow="<?php echo $attendance_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                                                <?php echo $attendance_rate; ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($meeting['created_by']); ?></td>
                                                    <td>
                                                        <a href="../meetings/view.php?id=<?php echo $meeting['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Seçilen filtreler için görüntülenecek veri bulunmuyor.
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
    
    <script>
        $(document).ready(function() {
            // Tablo araması
            $("#tableSearch").on("keyup", function() {
                var value = $(this).val().toLowerCase();
                $("#dataTable tbody tr").filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                });
            });
            
            // Yazdırma
            $("#btnPrint").click(function() {
                window.print();
            });
            
            // Grafik
            const ctx = document.getElementById('reportChart').getContext('2d');
            
            // Grafik verisi
            const labels = <?php echo json_encode(array_column($chart_data, 'label')); ?>;
            const data = <?php echo json_encode(array_column($chart_data, 'value')); ?>;
            
            <?php if ($report_type === 'meetings'): ?>
                var chartTitle = 'Aylara Göre Toplantı Sayısı';
                var chartColor = 'rgba(78, 115, 223, 0.7)';
                var chartType = 'bar';
            <?php elseif ($report_type === 'users'): ?>
                var chartTitle = 'Aylara Göre Kullanıcı Kaydı';
                var chartColor = 'rgba(28, 200, 138, 0.7)';
                var chartType = 'bar';
            <?php elseif ($report_type === 'attendance'): ?>
                var chartTitle = 'Toplantı Katılım Oranları (%)';
                var chartColor = 'rgba(246, 194, 62, 0.7)';
                var chartType = 'horizontalBar';
            <?php endif; ?>
            
            if (labels.length > 0) {
                const myChart = new Chart(ctx, {
                    type: chartType === 'horizontalBar' ? 'bar' : chartType,
                    data: {
                        labels: labels,
                        datasets: [{
                            label: chartTitle,
                            data: data,
                            backgroundColor: chartColor,
                            borderColor: chartColor.replace('0.7', '1'),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: chartType === 'horizontalBar' ? 'y' : 'x',
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
            
            // Excel ve PDF export işlemleri buraya eklenebilir.
            // ExcelJS ve jsPDF gibi kütüphaneler kullanılabilir.
            
            $('#btnExportExcel').click(function() {
                alert('Excel dışa aktarma özelliği henüz eklenmedi.');
            });
            
            $('#btnExportPDF').click(function() {
                alert('PDF dışa aktarma özelliği henüz eklenmedi.');
            });
        });
    </script>
</body>
</html>
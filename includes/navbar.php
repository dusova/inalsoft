<?php
// includes/navbar.php - Üst menü

// Kullanıcı oturum bilgilerini al
$user = get_authenticated_user();

// Kullanıcı oturum açmamışsa yönlendirme yapabilirsiniz
if (!$user) {
    header("Location: ../auth/login.php");
    exit;
}

// Okunmamış bildirim sayısını kontrol et
$db = connect_db();
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$notification_count = $stmt->fetchColumn();
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <!-- Logo ve Marka Adı -->
        <a class="navbar-brand" href="<?php echo isset($base_path) ? $base_path : '../'; ?>dashboard.php">
            <img src="<?php echo isset($base_path) ? $base_path : '../'; ?>assets/img/logo-white.png" alt="inalsoft Logo" height="30">
            <span class="ms-2">inalsoft</span>
        </a>
        
        <!-- Mobil Menü Butonu -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Menü İçeriği -->
        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo isset($base_path) ? $base_path : '../'; ?>dashboard.php">
                        <i class="bi bi-house-door"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo isset($base_path) ? $base_path : '../'; ?>projects/index.php">
                        <i class="bi bi-folder"></i> Projeler
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo isset($base_path) ? $base_path : '../'; ?>calendar/index.php">
                        <i class="bi bi-calendar3"></i> Takvim
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo isset($base_path) ? $base_path : '../'; ?>meetings/index.php">
                        <i class="bi bi-people"></i> Toplantılar
                    </a>
                </li>
            </ul>
            
            <!-- Sağ Menü -->
            <ul class="navbar-nav ms-auto">
                <!-- Tema Değiştirme -->
                <li class="nav-item">
                    <button id="toggleTheme" class="btn btn-link nav-link">
                        <i class="bi bi-circle-half"></i>
                    </button>
                </li>
                
                <!-- Bildirimler -->
                <li class="nav-item dropdown">
                    <a class="nav-link position-relative" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span id="notification-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $notification_count; ?>
                            </span>
                        <?php else: ?>
                            <span id="notification-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display: none;"></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationsDropdown">
                        <div class="dropdown-header d-flex justify-content-between align-items-center">
                            <span>Bildirimler</span>
                            <button id="markAllReadBtn" class="btn btn-sm btn-link text-decoration-none">Tümünü Okundu İşaretle</button>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div id="notificationsContainer" class="notification-list">
                            <!-- JavaScript ile doldurulacak -->
                            <div class="text-center p-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Yükleniyor...</span>
                                </div>
                                <span class="ms-2">Bildirimler yükleniyor...</span>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="<?php echo isset($base_path) ? $base_path : '../'; ?>notifications/index.php">
                            Tüm Bildirimleri Gör
                        </a>
                    </div>
                </li>
                
                <!-- Kullanıcı Profili -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo isset($base_path) ? $base_path : '../'; ?><?php echo $user['profile_image'] ?? 'assets/img/default-profile.png'; ?>" alt="Profil Resmi" class="rounded-circle me-1" width="24" height="24">
                        <span><?php echo htmlspecialchars($user['full_name'] ?? 'Misafir'); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li>
                            <a class="dropdown-item" href="<?php echo isset($base_path) ? $base_path : '../'; ?>profile/index.php">
                                <i class="bi bi-person"></i> Profil
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo isset($base_path) ? $base_path : '../'; ?>profile/index.php#preferences">
                                <i class="bi bi-gear"></i> Ayarlar
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo isset($base_path) ? $base_path : '../'; ?>auth/logout.php">
                                <i class="bi bi-box-arrow-right"></i> Çıkış Yap
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
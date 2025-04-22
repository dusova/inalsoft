<?php
// includes/sidebar.php - Yan menü

// Geçerli sayfa yolunu al
$current_page = $_SERVER['REQUEST_URI'];

// Kategori listesini dinamik olarak yükle
try {
    $sidebar_db = connect_db();
    $stmt = $sidebar_db->prepare("SELECT * FROM project_categories ORDER BY name ASC");
    $stmt->execute();
    $sidebar_categories = $stmt->fetchAll();
} catch (Exception $e) {
    // Hata durumunda boş dizi oluştur
    $sidebar_categories = [];
}
?>

<nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
    <div class="position-sticky sidebar-sticky">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'dashboard.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>dashboard.php">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </li>
            
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                <span>Projeler</span>
                <a class="link-secondary" href="<?php echo isset($base_path) ? $base_path : '../'; ?>projects/index.php" aria-label="Tüm projeler">
                    <i class="bi bi-grid"></i>
                </a>
            </h6>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'projects/index.php') !== false && !strpos($current_page, '?category=') ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>projects/index.php">
                    <i class="bi bi-folder"></i>
                    Tüm Projeler
                </a>
            </li>
            
            <?php 
            // Dinamik kategori menüsü
            if (!empty($sidebar_categories)): 
                foreach ($sidebar_categories as $cat): 
                    // Kategori için simge belirleme
                    $icon = 'folder-fill';
                    switch(strtolower($cat['name'])) {
                        case 'web tasarım':
                        case 'web tasarımları':
                            $icon = 'globe';
                            break;
                        case 'sosyal medya':
                            $icon = 'instagram';
                            break;
                        case 'uygulama':
                        case 'mobil uygulama':
                            $icon = 'phone';
                            break;
                        case 'yazılım':
                        case 'yazılım geliştirme':
                            $icon = 'code-square';
                            break;
                        case 'grafik':
                        case 'grafik tasarım':
                            $icon = 'brush';
                            break;
                        case 'pazarlama':
                        case 'dijital pazarlama':
                            $icon = 'graph-up';
                            break;
                    }
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'projects/index.php?category=' . $cat['id']) !== false ? 'active' : ''; ?>" 
                   href="<?php echo isset($base_path) ? $base_path : '../'; ?>projects/index.php?category=<?php echo $cat['id']; ?>">
                    <i class="bi bi-<?php echo $icon; ?>"></i>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
            </li>
            <?php 
                endforeach;
            endif; 
            ?>
            
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                <span>Takvim</span>
                <a class="link-secondary" href="<?php echo isset($base_path) ? $base_path : '../'; ?>calendar/index.php" aria-label="Takvim">
                    <i class="bi bi-calendar-plus"></i>
                </a>
            </h6>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'calendar/index.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>calendar/index.php">
                    <i class="bi bi-calendar3"></i>
                    Takvim Görünümü
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'calendar/events.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>calendar/events.php">
                    <i class="bi bi-calendar-event"></i>
                    Etkinlikler
                </a>
            </li>
            
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                <span>Toplantılar</span>
                <a class="link-secondary" href="<?php echo isset($base_path) ? $base_path : '../'; ?>meetings/index.php" aria-label="Toplantılar">
                    <i class="bi bi-people"></i>
                </a>
            </h6>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'meetings/index.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>meetings/index.php">
                    <i class="bi bi-people-fill"></i>
                    Tüm Toplantılar
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'meetings/index.php?status=upcoming') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>meetings/index.php?status=upcoming">
                    <i class="bi bi-calendar-check"></i>
                    Yaklaşan Toplantılar
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'meetings/notes.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>meetings/notes.php">
                    <i class="bi bi-journal-text"></i>
                    Toplantı Notları
                </a>
            </li>
            
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                <span>Diğer</span>
            </h6>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'profile/index.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>profile/index.php">
                    <i class="bi bi-person-circle"></i>
                    Profil Ayarları
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'notifications/index.php') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>notifications/index.php">
                    <i class="bi bi-bell"></i>
                    Bildirimler
                </a>
            </li>
            <?php if ($user['role'] === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_page, 'admin') !== false ? 'active' : ''; ?>" href="<?php echo isset($base_path) ? $base_path : '../'; ?>admin/index.php">
                    <i class="bi bi-shield-lock"></i>
                    Yönetim Paneli
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link" href="<?php echo isset($base_path) ? $base_path : '../'; ?>auth/logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    Çıkış Yap
                </a>
            </li>
        </ul>
    </div>
</nav>
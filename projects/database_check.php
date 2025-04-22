<?php
// projects/database_check.php
// Bu dosya, projenizin ana dizinine yerleştirin ve tarayıcıdan ziyaret edin
// Sorunları teşhis etmek için kullanılacak

session_start();
require_once '../config/database.php';

// Güvenlik kontrolü - sadece giriş yapmış kullanıcılar erişebilir
if (!isset($_SESSION['user_id'])) {
    die('Bu sayfayı görüntülemek için giriş yapmalısınız');
}

// Sadece admin kullanıcılar erişebilir (opsiyonel)
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
//     die('Bu sayfayı sadece admin kullanıcılar görüntüleyebilir');
// }

// Veritabanı bağlantısı
$db = connect_db();

// Sonuçları görüntüle
function print_table($title, $data) {
    echo "<h2>$title</h2>";
    
    if (empty($data)) {
        echo "<p>Veri bulunamadı.</p>";
        return;
    }
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    
    // Tablo başlıkları
    echo "<tr>";
    foreach (array_keys($data[0]) as $key) {
        echo "<th>$key</th>";
    }
    echo "</tr>";
    
    // Tablo verileri
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value !== null ? $value : 'NULL') . "</td>";
        }
        echo "</tr>";
    }
    
    echo "</table>";
}

// Tablo yapısını göster
function show_table_structure($db, $table) {
    $columns = $db->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
    return $columns;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Kontrol Aracı</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th { background-color: #f2f2f2; }
        td, th { padding: 8px; text-align: left; }
        tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
</head>
<body>
    <h1>Veritabanı Kontrol Aracı</h1>
    
    <hr>
    
    <?php
    try {
        // Kategori tablosu yapısı
        $categoryStructure = show_table_structure($db, 'project_categories');
        print_table('project_categories Tablo Yapısı', $categoryStructure);
        
        // Proje tablosu yapısı
        $projectStructure = show_table_structure($db, 'projects');
        print_table('projects Tablo Yapısı', $projectStructure);
        
        // Kategorileri göster
        $categories = $db->query("SELECT * FROM project_categories")->fetchAll(PDO::FETCH_ASSOC);
        print_table('Tüm Kategoriler', $categories);
        
        // Projeleri göster
        $projects = $db->query("
            SELECT p.*, pc.name as category_name 
            FROM projects p 
            LEFT JOIN project_categories pc ON p.category = pc.id
            ORDER BY p.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        print_table('Son Eklenen Projeler', $projects);
        
        // Kategori ile ilişkili projeleri kontrol et
        if (!empty($categories)) {
            foreach ($categories as $category) {
                $catProjects = $db->query("
                    SELECT * FROM projects WHERE category = {$category['id']}
                ")->fetchAll(PDO::FETCH_ASSOC);
                
                print_table("'{$category['name']}' Kategorisindeki Projeler (ID: {$category['id']})", $catProjects);
            }
        }
        
    } catch (PDOException $e) {
        echo "<div style='color: red; padding: 10px; border: 1px solid red;'>";
        echo "<h2>Veritabanı Hatası:</h2>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
    ?>
    
    <hr>
    <p><a href="index.php">&laquo; Projeler Sayfasına Dön</a></p>
</body>
</html>
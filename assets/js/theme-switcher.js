// assets/js/theme-switcher.js - Tema değiştirme işlevselliği

/**
 * Tema Değiştirme İşlevleri
 * 
 * Bu dosya, web uygulamasının açık/koyu tema değiştirme
 * özelliğini sağlayan fonksiyonları içerir.
 */

// Sayfa yüklendiğinde çalışacak kod
document.addEventListener('DOMContentLoaded', function() {
    // Mevcut temayı kontrol edelim
    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
    
    // Tema simgesini mevcut temaya göre güncelle
    updateThemeIcon(currentTheme);
    
    // Tema değiştirme butonuna tıklama olayı ekle
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function(e) {
            e.preventDefault(); // Varsayılan davranışı engelle
            toggleTheme();
        });
    }
    
    // Login sayfasındaki tema değiştirme butonu
    const loginThemeToggleBtn = document.getElementById('toggleTheme');
    if (loginThemeToggleBtn) {
        loginThemeToggleBtn.addEventListener('click', function(e) {
            e.preventDefault(); // Varsayılan davranışı engelle
            toggleTheme();
        });
    }
});

/**
 * Temayı değiştirir (açık/koyu)
 */
function toggleTheme() {
    // Mevcut temayı al
    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
    
    // Yeni temayı belirle
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    // HTML elementine tema özniteliğini ekle
    document.documentElement.setAttribute('data-bs-theme', newTheme);
    
    // Sidebar'ı da güncelle
    updateSidebarTheme(newTheme);
    
    // Tema simgesini güncelle
    updateThemeIcon(newTheme);
    
    // Temayı çerez olarak kaydet (30 gün)
    setCookie('theme', newTheme, 30);
    
    // Kullanıcı giriş yapmışsa, veritabanında da tercihini güncelleyelim
    if (isUserLoggedIn()) {
        updateUserThemePreference(newTheme);
    }
    
    console.log('Tema değiştirildi:', newTheme); // Hata ayıklama için
}

/**
 * Sidebar'ın temasını günceller
 * @param {string} theme - 'light' veya 'dark'
 */
function updateSidebarTheme(theme) {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        if (theme === 'dark') {
            sidebar.classList.add('bg-dark');
            sidebar.classList.remove('bg-light');
        } else {
            sidebar.classList.add('bg-light');
            sidebar.classList.remove('bg-dark');
        }
    }
}

/**
 * Tema simgesini günceller
 * @param {string} theme - 'light' veya 'dark'
 */
function updateThemeIcon(theme) {
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) {
        if (theme === 'dark') {
            themeIcon.classList.remove('bi-moon-stars-fill');
            themeIcon.classList.add('bi-sun-fill');
        } else {
            themeIcon.classList.remove('bi-sun-fill');
            themeIcon.classList.add('bi-moon-stars-fill');
        }
    }
}

/**
 * Çerez oluşturur
 * @param {string} name - çerez adı
 * @param {string} value - çerez değeri
 * @param {number} days - çerezin kaç gün saklanacağı
 */
function setCookie(name, value, days) {
    let expires = "";
    if (days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "") + expires + "; path=/";
}

/**
 * Çerez değerini okur
 * @param {string} name - çerez adı
 * @returns {string|null} - çerez değeri veya null
 */
function getCookie(name) {
    const nameEQ = name + "=";
    const ca = document.cookie.split(';');
    for(let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}

/**
 * Kullanıcının giriş yapmış olup olmadığını kontrol eder
 * @returns {boolean} - Kullanıcı giriş yapmışsa true, değilse false
 */
function isUserLoggedIn() {
    // Basit bir kontrol: navbar'daki kullanıcı dropdown menüsü var mı?
    return document.getElementById('userDropdown') !== null;
}

/**
 * Kullanıcı tema tercihini veritabanında günceller
 * @param {string} theme - 'light' veya 'dark'
 */
function updateUserThemePreference(theme) {
    // AJAX isteği gönder
    fetch('../api/update_theme_preference.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `theme=${theme}`
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Tema tercihi güncellenirken bir hata oluştu:', data.error);
        }
    })
    .catch(error => {
        console.error('Tema tercihi güncellenirken bir hata oluştu:', error);
    });
}

// Sayfa yüklenirken otomatik olarak temanın uygulanması
document.addEventListener('DOMContentLoaded', function() {
    // Çerezden veya local storage'dan tema tercihi al
    const savedTheme = getCookie('theme') || localStorage.getItem('theme') || 'light';
    
    // Temayı uygula
    document.documentElement.setAttribute('data-bs-theme', savedTheme);
    
    // Sidebar'ı da güncelle
    updateSidebarTheme(savedTheme);
    
    // Tema simgesini güncelle
    updateThemeIcon(savedTheme);
});
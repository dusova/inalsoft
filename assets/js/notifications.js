

// assets/js/notifications.js - Bildirim işlevselliği

/**
 * Bildirim İşlemleri
 * 
 * Bu dosya, bildirim işlemlerini yönetmek için
 * gerekli fonksiyonları içerir.
 */

// Sayfa yüklendiğinde bildirimleri kontrol et
document.addEventListener('DOMContentLoaded', function() {
    // İlk bildirim kontrolü
    checkNotifications();
    
    // Her 30 saniyede bir bildirimleri kontrol et
    setInterval(checkNotifications, 30000);
    
    // Tüm bildirimleri okundu olarak işaretleme butonu
    const markAllReadBtn = document.getElementById('markAllRead');
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', markAllNotificationsAsRead);
    }
    
    // Tarayıcı bildirim izni isteği
    requestNotificationPermission();
});

/**
 * Bildirimleri AJAX ile kontrol eder
 */
function checkNotifications() {
    fetch('../api/check_notifications.php')
        .then(response => response.json())
        .then(data => {
            updateNotificationBadge(data.count);
            updateNotificationList(data.notifications);
        })
        .catch(error => {
            console.error('Bildirim kontrolünde hata:', error);
        });
}

/**
 * Bildirim sayacını günceller
 * @param {number} count - Bildirim sayısı
 */
function updateNotificationBadge(count) {
    const badge = document.getElementById('notification-badge');
    if (badge) {
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }
}

/**
 * Bildirim listesini günceller
 * @param {Array} notifications - Bildirim nesneleri dizisi
 */
function updateNotificationList(notifications) {
    const container = document.getElementById('notificationsContainer');
    if (!container) return;
    
    // Listeyi temizle
    container.innerHTML = '';
    
    if (notifications && notifications.length > 0) {
        // Bildirimleri listeye ekle
        notifications.forEach(notification => {
            // Bildirim öğesini oluştur
            const item = document.createElement('div');
            item.className = 'notification-item';
            item.dataset.id = notification.id;
            
            // Bildirim türüne göre simge seç
            let icon = '';
            switch(notification.type) {
                case 'project': icon = 'folder'; break;
                case 'task': icon = 'check2-square'; break;
                case 'meeting': icon = 'calendar-event'; break;
                default: icon = 'bell';
            }
            
            // Bildirim içeriğini oluştur
            item.innerHTML = `
                <div class="notification-header">
                    <i class="bi bi-${icon}"></i>
                    <span class="notification-title">${notification.title}</span>
                    <small class="notification-time">${formatDate(notification.created_at)}</small>
                </div>
                <div class="notification-body">
                    ${notification.message}
                </div>
                <div class="notification-footer">
                    <button class="btn btn-sm btn-outline-primary mark-read-btn" data-id="${notification.id}">
                        <i class="bi bi-check-circle"></i> Okundu İşaretle
                    </button>
                </div>
            `;
            
            // Listeye ekle
            container.appendChild(item);
        });
        
        // Okundu işaretleme butonlarına tıklama olayı ekle
        document.querySelectorAll('.mark-read-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id;
                markNotificationAsRead(id);
            });
        });
    } else {
        // Bildirim yoksa mesaj göster
        container.innerHTML = '<div class="text-center p-3">Okunmamış bildiriminiz bulunmuyor.</div>';
    }
}

/**
 * Tarih formatını düzenler
 * @param {string} dateString - ISO tarih formatında string
 * @returns {string} - Formatlanmış tarih
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    
    // Bugün ise saat:dakika göster
    if (date.toDateString() === now.toDateString()) {
        return `Bugün ${date.getHours()}:${date.getMinutes().toString().padStart(2, '0')}`;
    }
    
    // Dün ise "Dün" yaz
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return `Dün ${date.getHours()}:${date.getMinutes().toString().padStart(2, '0')}`;
    }
    
    // Diğer durumlar için gün.ay.yıl göster
    return `${date.getDate()}.${date.getMonth() + 1}.${date.getFullYear()} ${date.getHours()}:${date.getMinutes().toString().padStart(2, '0')}`;
}

/**
 * Bildirimi okundu olarak işaretler
 * @param {number} id - Bildirim ID
 */
function markNotificationAsRead(id) {
    fetch('../api/mark_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `notification_id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Bildirimi listeden kaldır
            const item = document.querySelector(`.notification-item[data-id="${id}"]`);
            if (item) {
                item.remove();
            }
            
            // Bildirimleri yeniden kontrol et
            checkNotifications();
        }
    })
    .catch(error => {
        console.error('Bildirim işaretlenirken hata:', error);
    });
}

/**
 * Tüm bildirimleri okundu olarak işaretler
 */
function markAllNotificationsAsRead() {
    fetch('../api/mark_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: ''
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Bildirimleri yeniden kontrol et
            checkNotifications();
        }
    })
    .catch(error => {
        console.error('Bildirimler işaretlenirken hata:', error);
    });
}

/**
 * Tarayıcı bildirim izni ister
 */
function requestNotificationPermission() {
    // Browser bildirimleri destekliyor mu kontrol et
    if ('Notification' in window) {
        // İzin kontrolü
        if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            // 3 saniye sonra izin iste (kullanıcı siteyi ilk açtığında hemen istemeyelim)
            setTimeout(() => {
                Notification.requestPermission();
            }, 3000);
        }
    }
}

/**
 * Tarayıcı bildirimi gösterir
 * @param {string} title - Bildirim başlığı
 * @param {string} message - Bildirim mesajı
 */
function showBrowserNotification(title, message) {
    if (Notification.permission === 'granted') {
        const notification = new Notification('inalsoft: ' + title, {
            body: message,
            icon: '../assets/img/logo-icon.png'
        });
        
        // Bildirime tıklandığında sayfayı ön plana getir
        notification.onclick = function() {
            window.focus();
            this.close();
        };
    }
}

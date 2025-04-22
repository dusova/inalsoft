<?php
// includes/realtime_notifications.php - Gerçek zamanlı bildirim desteği

// Örnek kullanım:
// include_once 'includes/realtime_notifications.php';
// Bu dosya tüm sayfalarda dahil edilebilir
?>

<!-- Server-Sent Events (SSE) ile Gerçek Zamanlı Bildirimler -->
<script>
    // SSE desteği kontrolü
    if (typeof(EventSource) !== "undefined") {
        // EventSource bağlantısı kur
        const evtSource = new EventSource("../api/notification_stream.php");
        
        // Bildirim geldiğinde
        evtSource.addEventListener("notification", function(event) {
            try {
                const data = JSON.parse(event.data);
                
                // Bildirim sayacını güncelle
                updateNotificationCounter(data.count);
                
                // Tarayıcı bildirimi göster (bildirim ayarlarında izin verilmişse)
                if (data.browser_enabled && Notification.permission === "granted") {
                    showBrowserNotification(data.title, data.message);
                }
                
                // Sesli bildirim
                playNotificationSound();
                
                // Bildirim çekmecesini güncelle
                updateNotificationDrawer(data.notifications);
                
            } catch (e) {
                console.error("Bildirim işlenirken bir hata oluştu:", e);
            }
        });
        
        // Bağlantı hatası
        evtSource.onerror = function() {
            console.error("SSE bağlantı hatası. Yeniden bağlanılıyor...");
            // 5 saniye sonra yeniden bağlanmayı dene
            setTimeout(() => {
                evtSource.close();
                // Sayfayı yenilemeden tekrar bağlanmayı dene
                location.reload();
            }, 5000);
        };
    } else {
        console.warn("Tarayıcınız SSE'yi desteklemiyor. Gerçek zamanlı bildirimler devre dışı.");
        
        // Alternatif olarak düzenli aralıklarla AJAX ile kontrol et
        setInterval(checkNotifications, 30000); // 30 saniyede bir
    }
    
    // Bildirim sayacını güncelle
    function updateNotificationCounter(count) {
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
    
    // Tarayıcı bildirimi göster
    function showBrowserNotification(title, message) {
        // Bildirim izni kontrolü
        if (Notification.permission === "granted") {
            const notification = new Notification("inalsoft: " + title, {
                body: message,
                icon: '../assets/img/logo-icon.png'
            });
            
            // Bildirime tıklandığında
            notification.onclick = function() {
                window.focus();
                this.close();
            };
        }
        // İzin isteği göster (sayfa yüklendiğinde bir kez istenir)
        else if (Notification.permission !== "denied") {
            Notification.requestPermission();
        }
    }
    
    // Bildirim sesi çal
    function playNotificationSound() {
        const audio = new Audio('../assets/sound/notification.mp3');
        audio.play();
    }
    
    // Bildirim çekmecesini güncelle
    function updateNotificationDrawer(notifications) {
        const container = document.getElementById('notificationsContainer');
        if (!container) return;
        
        // Çekmeceyi temizle
        container.innerHTML = '';
        
        if (notifications.length > 0) {
            // Bildirimleri ekle
            notifications.forEach(notification => {
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
                        <button class="btn btn-sm btn-outline-primary mark-read" data-id="${notification.id}">
                            <i class="bi bi-check-circle"></i> Okundu İşaretle
                        </button>
                    </div>
                `;
                
                container.appendChild(item);
            });
            
            // Okundu işaretleme butonu olayını ekle
            document.querySelectorAll('.mark-read').forEach(button => {
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
    
    // Bildirim okundu olarak işaretle
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
                if (item) item.remove();
                
                // Bildirimleri yeniden kontrol et
                checkNotifications();
            }
        })
        .catch(error => {
            console.error('Bildirim işaretlenirken hata:', error);
        });
    }
    
    // AJAX ile bildirimleri kontrol et
    function checkNotifications() {
        fetch('../api/check_notifications.php')
            .then(response => response.json())
            .then(data => {
                updateNotificationCounter(data.count);
                updateNotificationDrawer(data.notifications);
            })
            .catch(error => {
                console.error('Bildirim kontrolünde hata:', error);
            });
    }
    
    // Tarih formatla
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
    
    // Sayfa yüklendiğinde bildirimleri kontrol et
    document.addEventListener('DOMContentLoaded', function() {
        checkNotifications();
        
        // Tüm bildirimleri okundu olarak işaretle butonu
        const markAllReadBtn = document.getElementById('markAllRead');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function() {
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
                        checkNotifications();
                        
                        // Bildirim modalını kapat
                        const modal = bootstrap.Modal.getInstance(document.getElementById('notificationsModal'));
                        if (modal) modal.hide();
                    }
                })
                .catch(error => {
                    console.error('Bildirimleri işaretlerken hata:', error);
                });
            });
        }
    });
</script>


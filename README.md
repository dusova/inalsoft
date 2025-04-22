# inalsoft - Proje Yönetim Sistemi

inalsoft, ekipler için tasarlanmış modern ve kullanıcı dostu bir proje yönetim sistemidir. Projeler, görevler, toplantılar ve takvim etkinliklerini tek bir platformda yönetmenize olanak tanır.

![inalsoft Introduction](https://placehold.co/1200x400/ff1616/FFFFFF?text=inalsoft+Project+Management+System)

## Özellikler

### Proje Yönetimi
- Projeler oluşturma, düzenleme ve takip etme
- Proje kategorilerine göre filtreleme
- Proje durumlarını izleme (Planlama, Devam Ediyor, İnceleme, Tamamlandı)
- Proje önceliklerini ayarlama

### Görev Yönetimi
- Projelere görev atama
- Görev durumlarını takip etme
- Görevlerin son tarihlerini belirleme
- Görevleri kullanıcılara atama

### Toplantı Yönetimi
- Toplantı planlama ve düzenleme
- Katılımcı ekleme ve çıkarma
- Toplantı gündemini belirleme
- Toplantı notları oluşturma ve paylaşma
- Online toplantı bağlantıları ekleme

### Takvim ve Etkinlikler
- Etkinlikleri görüntüleme ve oluşturma
- Toplantı ve projeleri takvim üzerinde izleme
- Günlük, haftalık ve aylık görünümler
- Etkinlik hatırlatıcıları

### Gerçek Zamanlı Bildirimler
- Tarayıcı bildirimleri
- Sesli uyarılar
- Bildirim merkezinden tüm bildirimleri görüntüleme
- Bildirim tercihlerini özelleştirme

### Kullanıcı Profili ve Tercihler
- Kişisel profil yönetimi
- Şifre değiştirme
- Tema tercihi (Açık/Koyu mod)
- Bildirim ayarları

## Teknik Özellikler

- PHP 7.4+ backend
- MySQL/MariaDB veritabanı
- Bootstrap 5 responsive tasarım
- Server-Sent Events (SSE) ile gerçek zamanlı bildirimler
- WebSocket desteği
- Ajax ile sayfa yenilenmeden içerik güncelleştirme
- Çok dilli destek altyapısı (şu anda Türkçe)
- Mobil uyumlu arayüz

## Kurulum

### Sistem Gereksinimleri

- PHP 7.4 veya üzeri
- MySQL 5.7 veya üzeri
- Apache/Nginx web sunucusu
- Composer (bağımlılıkları yönetmek için)

### Kurulum Adımları

1. Projeyi klonlayın veya indirin:
   ```
   git clone https://github.com/username/inalsoft-project-management.git
   ```

2. Composer bağımlılıklarını yükleyin:
   ```
   composer install
   ```

3. Veritabanını oluşturun ve `config/database.php` dosyasını yapılandırın:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'kullanıcı_adı');
   define('DB_PASS', 'şifre');
   define('DB_NAME', 'veritabanı_adı');
   ```

4. Veritabanı şemasını içe aktarın:
   ```
   mysql -u kullanıcı_adı -p veritabanı_adı < sql/schema.sql
   ```

5. Web sunucusunu yapılandırın (Apache için örnek):
   ```apache
   <VirtualHost *:80>
       ServerName inalsoft.local
       DocumentRoot /path/to/inalsoft-pm
       <Directory /path/to/inalsoft-pm>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

6. Uploads ve diğer yazılabilir dizinler için izinleri yapılandırın:
   ```
   chmod -R 755 uploads/
   ```

7. Gerçek zamanlı bildirimler için WebSocket sunucusunu başlatın (opsiyonel):
   ```
   php realtime.php
   ```

## Kullanım

1. Tarayıcınızda uygulamaya gidin (örn. `http://inalsoft.com/` veya kurulumunuza göre)
2. Varsayılan giriş bilgileri:
   - Kullanıcı adı: `admin`
   - Şifre: `admin123`
3. İlk girişten sonra şifrenizi değiştirmeniz önerilir.

### Dashboard

Dashboard sayfası, mevcut projelerin genel durumunu, yaklaşan toplantıları, son aktiviteleri ve bildirimleri gösterir. Buradan tüm ana bölümlere hızlıca erişebilirsiniz.

### Projeler

Projeler bölümünde yeni projeler oluşturabilir, mevcut projeleri düzenleyebilir ve durumlarını takip edebilirsiniz. Projeler kategorilere ayrılabilir ve öncelik seviyelerine göre sıralanabilir.

### Toplantılar

Toplantılar bölümünde yeni toplantılar planlayabilir, katılımcıları ekleyebilir ve toplantı notlarını yönetebilirsiniz. Toplantı bağlantıları ekleyerek online toplantıları kolayca başlatabilirsiniz.

### Takvim

Takvim görünümünde tüm etkinlikleri, toplantıları ve proje tarihlerini görselleştirebilirsiniz. Günlük, haftalık ve aylık görünümler arasında geçiş yapabilirsiniz.

### Profil Ayarları

Profil ayarları bölümünden kişisel bilgilerinizi güncelleyebilir, şifrenizi değiştirebilir, tema tercihlerinizi yapabilir ve bildirim ayarlarınızı özelleştirebilirsiniz.

## Gerçek Zamanlı Bildirimler

Uygulama, gerçek zamanlı bildirimler için Server-Sent Events (SSE) ve WebSocket teknolojilerini kullanır. Bildirimler aşağıdaki durumlarda gönderilir:

- Yeni proje oluşturulduğunda
- Görev ataması yapıldığında
- Toplantı davetleri alındığında
- Proje durumu değiştiğinde
- Ve diğer önemli etkinliklerde

Bildirim tercihlerini profil ayarlarından özelleştirebilirsiniz.

## Güvenlik

- Tüm şifreler güvenli bir şekilde hash'lenir
- CSRF koruması her formda aktiftir
- SQL Injection'a karşı koruma için prepared statements kullanılır
- XSS saldırılarına karşı input filtreleme uygulanır

## Katkıda Bulunma

1. Projeyi fork edin
2. Feature branch oluşturun (`git checkout -b yeni-ozellik`)
3. Değişikliklerinizi commit edin (`git commit -am 'Yeni özellik: özet'`)
4. Branch'inizi push edin (`git push origin yeni-ozellik`)
5. Pull Request oluşturun

## Hata Bildirimi

Hatalar ve öneriler için lütfen GitHub Issues bölümünü kullanın veya doğrudan iletişime geçin.

## Lisans

Bu proje [Apache 2.0](LICENSE) lisansı altında lisanslanmıştır.

## İletişim

- Web: [inalsoft.com](https://inalsoft.com)
- E-posta: info@inalsoft.com

---

inalsoft Proje Yönetim Sistemi - Ekibinizin verimliliğini artırmak için geliştirildi.

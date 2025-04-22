

// assets/js/form-validation.js - Form doğrulama

/**
 * Form Doğrulama İşlevleri
 * 
 * Bu dosya, formların doğrulanması için gerekli
 * fonksiyonları içerir.
 */

// Sayfa yüklendiğinde çalış
document.addEventListener('DOMContentLoaded', function() {
    // Tüm formlara doğrulama ekle
    const forms = document.querySelectorAll('form.needs-validation');
    
    // Her form için doğrulama olayı ata
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Şifre güçlülüğü kontrolü
    const passwordInputs = document.querySelectorAll('input[type="password"]:not(.skip-validation)');
    passwordInputs.forEach(input => {
        input.addEventListener('input', checkPasswordStrength);
    });
    
    // Şifre eşleşme kontrolü
    const confirmPasswordInputs = document.querySelectorAll('input[id*="confirm_password"]');
    confirmPasswordInputs.forEach(input => {
        input.addEventListener('input', function() {
            const passwordInput = document.querySelector('input[id*="new_password"]') || 
                                document.querySelector('input[id*="password"]:not([id*="confirm"])');
            
            if (passwordInput) {
                checkPasswordMatch(passwordInput, this);
            }
        });
    });
});

/**
 * Şifre güçlülüğünü kontrol eder
 */
function checkPasswordStrength() {
    const password = this.value;
    const meter = document.querySelector('#password-strength-meter');
    
    if (!meter) return;
    
    // Güçlülük seviyesini ölç
    let strength = 0;
    
    // En az 8 karakter
    if (password.length >= 8) strength += 1;
    
    // Küçük ve büyük harf içeriyor mu
    if (password.match(/([a-z].*[A-Z])|([A-Z].*[a-z])/)) strength += 1;
    
    // Rakam içeriyor mu
    if (password.match(/([0-9])/)) strength += 1;
    
    // Özel karakter içeriyor mu
    if (password.match(/([!,%,&,@,#,$,^,*,?,_,~])/)) strength += 1;
    
    // Metni ve rengi güncelle
    let strengthText = '';
    let strengthClass = '';
    
    switch (strength) {
        case 0:
        case 1:
            strengthText = 'Zayıf';
            strengthClass = 'bg-danger';
            break;
        case 2:
            strengthText = 'Orta';
            strengthClass = 'bg-warning';
            break;
        case 3:
            strengthText = 'İyi';
            strengthClass = 'bg-info';
            break;
        case 4:
            strengthText = 'Güçlü';
            strengthClass = 'bg-success';
            break;
    }
    
    // Meter'ı güncelle
    meter.className = 'progress-bar ' + strengthClass;
    meter.style.width = (strength * 25) + '%';
    meter.textContent = strengthText;
}

/**
 * Şifrelerin eşleşip eşleşmediğini kontrol eder
 * @param {HTMLElement} passwordInput - Şifre input elementi
 * @param {HTMLElement} confirmInput - Şifre onay input elementi
 */
function checkPasswordMatch(passwordInput, confirmInput) {
    const password = passwordInput.value;
    const confirmPassword = confirmInput.value;
    
    if (confirmPassword === '') {
        confirmInput.setCustomValidity('');
        return;
    }
    
    if (password === confirmPassword) {
        confirmInput.setCustomValidity('');
        
        // Bootstrap doğrulama stilini ekle
        confirmInput.classList.remove('is-invalid');
        confirmInput.classList.add('is-valid');
    } else {
        confirmInput.setCustomValidity('Şifreler eşleşmiyor.');
        
        // Bootstrap doğrulama stilini ekle
        confirmInput.classList.remove('is-valid');
        confirmInput.classList.add('is-invalid');
    }
}
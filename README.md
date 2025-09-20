# Ziyaretçi Kayıt Sistemi

Bu proje, kurum giriş çıkışlarını kaydetmek için hazırlanan basit bir PHP uygulamasıdır.

## Kurulum

1. Depoyu klonladıktan sonra kök dizinde yer alan `.env.example` dosyasını `.env` olarak kopyalayın ve değerleri güncelleyin:
   ```bash
   cp .env.example .env
   ```
2. `.env` dosyasındaki aşağıdaki ortam değişkenlerini kendi veritabanı bilgileriniz ve gizli anahtarlarınız ile doldurun:
   - `VISIT_DB_HOST`, `VISIT_DB_NAME`, `VISIT_DB_USER`, `VISIT_DB_PASS`
   - `VISIT_APP_KEY`: En az 32 karakter uzunluğunda, yüksek entropili bir anahtar kullanın.
   - `VISIT_APP_SALT`: En az 16 karakter uzunluğunda benzersiz bir salt değeri seçin.
3. Web sunucunuzda veya çalıştırdığınız terminal oturumunda bu ortam değişkenlerinin yüklendiğinden emin olun. PHP, `config.php` dosyasında bu değerler olmadan çalışmayı durdurur.
4. Veritabanı şemasını `install.php` üzerinden veya kendi tercih ettiğiniz şekilde oluşturun.

## Çalıştırma

Sunucuyu yerel olarak başlatmak için PHP'nin yerleşik sunucusunu kullanabilirsiniz:
```bash
php -S localhost:8000
```
Ardından tarayıcıdan `http://localhost:8000` adresine gidin.

## Güvenlik Notları

- Tüm yönetici arayüzlerine erişim için oturum açmak zorunludur.
- TC kimlik numaraları yalnızca yönetici yetkisine sahip kullanıcılar tarafından görüntülenebilir ve güncellenebilir.
- Yönlendirme, veritabanı bilgileri ve şifreleme anahtarları ortam değişkenleri üzerinden sağlanarak kaynak kodda gizli bilgi tutulması engellenmiştir.

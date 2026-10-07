# Katılım Takip

Excel'den yüklenen üye listesinde onay ekibinin telefondan **GELDİ** işaretlediği küçük sistem.
Roller: **Admin** (her şey), **Yönetici** (panel, kayıtlar, dışa aktarma, "Bizimle mi?"),
**Onay Ekibi** (arama + GELDİ; bölge atanırsa sadece o bölge).

PHP 8.2 + MySQL/MariaDB + PhpSpreadsheet. Framework yok. Teknik ayrıntı: `SPEC.md`.

## Yerel kurulum (XAMPP, Windows PowerShell)

Önce XAMPP Control Panel'den **Apache** ve **MySQL**'i başlat. Sonra PowerShell'de:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/hypearmaster/secim.git
cd secim
composer install
C:\xampp\php\php.exe bin\setup.php --demo
```

`setup.php` her şeyi kendisi yapar: `config.php`, veritabanı, tablolar, admin hesabı.
`--demo` ile ayrıca 240 kişilik deneme listesi ve iki test hesabı (`onay1` bölgeli onaycı,
`yonetici1`) açar. **Şifreler ekrana bir kez yazılır, not al.** Gerçek kurulumda `--demo` yazma.
Tekrar çalıştırmak güvenli: var olan veriye dokunmaz.

Aç: http://localhost/secim/public/

Güncellemek için: `git pull`, sonra yine `C:\xampp\php\php.exe bin\setup.php` (yeni tablo varsa ekler).

Notlar:
- Proje klasörün `C:\xampp\htdocs\secim` olmalı (adres `/secim/public`). Farklıysa
  `config.php` → `base_url`'i değiştir.
- `git clone`'u `secim` klasörünün **içinde** çalıştırma, iç içe `secim\secim` olur.
- `Module "mysqli" is already loaded` uyarısı XAMPP'ın `php.ini` ayarından gelir, zararsız.
- Bu proje Laravel değil: `php artisan` yok.
- Şifre unutulursa: `C:\xampp\php\php.exe bin\create-user.php admin admin "Ad Soyad"` (şifreyi sıfırlar).
- Çekirdek testleri: `C:\xampp\php\php.exe tests\core-test.php` (kendi geçici listesini açıp
  siler, gerçek veriye dokunmaz; işaretleme yapılmış listede `--force` ister)

## Kullanıcılar

Kayıt ekranı yok. Hesapları admin, **Kullanıcılar** sayfasından açar. Şifre otomatik üretilir
ve bir kez gösterilir. Kullanıcılar silinmez, pasife alınır; geçmişte kimin işaretlediği bozulmaz.

## "Bizimle mi?" seçenekleri

`config.php` → `stance_options`. Anahtar DB'ye yazılır, etiket ve renk değiştirilebilir.

## Canlıya alma notları

- Hosting'de web kökü mümkünse `public/` klasörü olmalı. Değilse kökteki `.htaccess`
  `src/`, `sql/`, `vendor/`, `config.php` vb. dosyaları web'den kapatır.
- `config.php` içinde `'debug' => false`, `base_url` sunucu yoluna göre ayarlanmalı.
- HTTPS şart (oturum çerezi HTTPS'te `Secure` olur).
- Excel dosyalarını (kişisel veri) depoya koyma.

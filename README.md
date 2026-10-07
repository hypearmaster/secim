# Katılım Takip

Excel'den yüklenen üye listesinde onay ekibinin telefondan **GELDİ** işaretlediği küçük sistem.
Roller: **Admin** (her şey), **Yönetici** (panel, kayıtlar, dışa aktarma, "Bizimle mi?"),
**Onay Ekibi** (arama + GELDİ; bölge atanırsa sadece o bölge).

PHP 8.2 + MySQL/MariaDB + PhpSpreadsheet. Framework yok. Teknik ayrıntı: `SPEC.md`.

## Yerel kurulum (XAMPP, Windows)

```bash
cd C:/xampp/htdocs
git clone https://github.com/hypearmaster/secim.git
cd secim
composer install
cp config.example.php config.php
```

`config.php` içinde DB bilgilerini kontrol et. XAMPP varsayılanı `root` / boş şifre, `base_url` = `/secim/public`.
Yerelde hata görmek için `'debug' => true` yap.

Veritabanı (XAMPP MySQL açık olmalı):

```bash
C:/xampp/mysql/bin/mysql.exe -uroot -e "CREATE DATABASE secim CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci"
C:/xampp/mysql/bin/mysql.exe -uroot secim < sql/schema.sql
```

İlk admin (şifre ekrana **bir kez** yazılır, not al):

```bash
C:/xampp/php/php.exe bin/create-user.php admin admin "Ad Soyad"
```

Aç: http://localhost/secim/public/

### Denemek için örnek veri (isteğe bağlı)

```bash
C:/xampp/php/php.exe bin/seed-demo.php
C:/xampp/php/php.exe bin/create-user.php onay1 onayci "Test Onaycı" "Merkez"
C:/xampp/php/php.exe bin/create-user.php yonetici1 yonetici "Test Yönetici"
C:/xampp/php/php.exe tests/core-test.php
```

`seed-demo.php` 240 sahte kişilik "Demo Etkinlik" açar ve aktif yapar. Gerçek liste
yüklendiğinde yeni etkinlik olarak gelir, demo arşivde kalır.

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

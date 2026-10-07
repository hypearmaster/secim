# Hediye bölgeleri

Kod güncellendikten sonra proje kökünde mevcut kurulum komutunu çalıştırın:

```sh
php bin/setup.php
```

Bu komut mevcut `config.php` dosyasını ve kayıtları korur; `gifts.region`
sütununu ekler. Tekrar çalıştırılması güvenlidir. Terminal yoksa phpMyAdmin'de
sütun henüz eklenmediyse şu sorguyu çalıştırın:

```sql
ALTER TABLE gifts ADD COLUMN region VARCHAR(120) NULL AFTER note;
```

Hediye ekranı açılınca mevcut belirsiz kayıtlar aynı etkinliğin Onay isimleri,
yetkili isimleri ve onaylı Bizimkiler bağlantıları ile eşleştirilir. Kesin olarak
tek bölgeye eşleşmeyenler belirsiz kalır. Karttaki seçim ile mevcut Onay
bölgelerinden biri kaydedilebilir. Elle seçilen bölgeler ve verildi işaretleri
otomatik eşleştirme sırasında korunur. Her kullanıcı tüm hediye listesini
görmeye devam eder; bölge filtresi görünümü daraltır.

Kontrol: Hediye ekranında bölge seçin, belirsiz bir kartın bölgesini kaydedin,
sayfayı yenileyin ve seçilen bölgenin/verildi işaretinin korunduğunu doğrulayın.

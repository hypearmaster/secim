# Katılım Takip — Teknik Şartname (elemanlar için)

Excel'den yüklenen kişi listesinde, onay ekibinin telefondan "GELDİ" işaretlediği küçük bir
sistem. Framework yok: **PHP 8.2 + PDO MySQL (MariaDB 10.4) + PhpSpreadsheet**. Vanilla JS.

## Gerçek veri (2026-10-07, grup-10.xlsx)
Liste kişi değil **oda üyesi firma** listesi. Tek sayfa, sayfa adı = grup ("grup 10").
Sütunlar: `Sno, OSicilNo, Unvan, Nace, Ilce, Tel, Gsm, Mail, Adres, ortaklar, yetkililer, Temsil`.
Eşleme: Unvan → `full_name` (kartta büyük ad), OSicilNo → `ref_no`, Ilce → `region`,
yetkililer → `authority` (**`names_only()` ile**: doğum tarihi/yeri atılır), grup → sayfa adı
(import ekranında değiştirilebilir sabit değer). Tel/Gsm/Mail/Adres/ortaklar/Temsil/Nace **alınmaz**.
Ekranlarda etiketler: "Unvan", "Yetkili", "İlçe", "Grup", "Sicil No".
Yeni alan **"Bizimle mi?"** (`people.stance`): seçenekler `config('stance_options')`,
yetki `stance` (admin+yönetici), API `POST api/stance.php` (`person_id`, `value`).

## Değişmez kurallar (ihlal = iş reddedilir)

1. **`src/` altındaki mevcut dosyalara DOKUNMA.** Çekirdek (auth, yetki, işaretleme) Claude'un.
   İhtiyacın olan bir şey yoksa raporda yaz. Yeni dosya açmana izin verilen yerler aşağıda.
2. Her sayfa ilk satırda: `require __DIR__ . '/../../src/bootstrap.php';` (public/ kökünde `/../src/...`).
3. Her sayfa **sunucuda** yetki ister: `$user = require_perm('export');` gibi. Buton gizlemek yetmez.
4. Her POST'un başında `csrf_verify();`. Her formda `<?= csrf_field() ?>`. JS fetch'te
   `X-CSRF-Token` başlığı (`<meta name="csrf-token">` içinde hazır).
5. SQL sadece `q($sql, $params)` ile, **parametreli**. Kullanıcı girdisini SQL'e birleştirme.
   Sütun/sıralama adı kullanıcıdan geliyorsa sabit bir beyaz listeden seç.
6. Ekrana basılan HER değer `e()` ile kaçırılır (Excel'den gelen veri dahil). JS'te `innerHTML`
   ile kullanıcı verisi basma; `textContent` / `createElement` kullan.
7. Kişi sorgularında kapsam **daima** `people_scope($user, 'p', $eventId)` ile kurulur.
8. Zaman DB'de UTC. Ekranda `fmt_time()` / `fmt_datetime()`. SQL'de `NOW()` değil `UTC_TIMESTAMP()`.
9. Kullanıcı ve etkinlik **silinmez**; pasife alınır.
10. Arayüz metinleri Türkçe. Mobilde rahat: dokunma hedefi ≥ 44px, yatay kaydırma yok.
11. `composer`, `git commit`, DB migrate, dosya silme yasak.

## Çekirdek API (src/ — hazır, kullan)

| Fonksiyon | Ne yapar |
|---|---|
| `config('key')`, `e($v)`, `url('admin/x.php')`, `redirect('x.php')`, `is_post()` | temel |
| `csrf_field()`, `csrf_verify()`, `csrf_token()` | CSRF |
| `flash('success'\|'error'\|'info', 'mesaj')` | sonraki sayfada gösterilir (layout basar) |
| `json_response(array, status)` | JSON çıkış + exit |
| `fmt_time($utc)`, `fmt_datetime($utc)` | UTC → İstanbul `H:i` / `d.m.Y H:i` |
| `clean_text($v, $max)` | boşluk sadeleştirir, boşsa `null` |
| `ip_to_text($bin)` | log'daki binary IP → metin |
| `q($sql, $params)` → `PDOStatement`, `db()` → `PDO` | DB (FETCH_ASSOC) |
| `current_user()`, `require_login()`, `require_perm($perm)`, `can($perm, $user)` | yetki |
| `ROLES` (`admin`,`yonetici`,`onayci` → Türkçe ad), `valid_username()`, `generate_password()` | kullanıcı |
| `active_event()` → `['id','name','created_at']` ya da `null` | etkinlik |
| `create_event($name, $userId, $activate=true)` → id, `activate_event($id)` | etkinlik |
| `people_scope($user, 'p', ?$eventId)` → `[$whereSql, $params]` | kapsam |
| `search_people($user, ['q','region','group','status'], $limit, $offset)` | arama |
| `scope_stats($user)` → `['total','came']` | sayaç |
| `people_breakdown($user, 'region'\|'group_name', ?$eventId)` → `[{value,label,total,came}]` | kırılım; `value === NONE_VALUE` boş olanlar |
| `insert_person($eventId, $batchId, ['full_name','authority','region','group_name','ref_no','extra'=>array])` | import |
| `search_key(...)` | Türkçe karaktersiz arama anahtarı |
| `names_only($text)` | "AD SOYAD-tarih-yer,AD2-..." → "AD SOYAD, AD2" |
| `stance_options()`, `stance_view($v)`, `stance_from_text($t)`, `set_stance($user,$id,$v)` | "Bizimle mi?" |
| `render_header($title, $activeKey, ['ek.css'])`, `render_footer(['ek.js'])` | sayfa iskeleti; içerik `<main class="container">` içinde |

Yetkiler (`can()`): `mark`, `unmark_any`, `view_all`, `dashboard`, `export`, `view_log`
→ admin+yönetici; `import`, `manage_events`, `manage_users` → sadece admin; onayci → `mark`.

### JSON uçları (hazır)
- `GET api/search.php?q=&region=&group=&status=all|came|pending&page=1`
  → `{ok, items:[Person], total, page, has_more, stats:{total,came}}`
- `POST api/mark.php` (form: `person_id`, `action=mark|unmark`; başlık `X-CSRF-Token`)
  → başarı 200 `{ok:true, person, stats}`; hata `{ok:false, error, person?, stats}`
  durumlar: 401 oturum yok, 403 yetki/süre/CSRF, 404 bulunamadı, 409 zaten işaretli/işaretsiz
- `Person` = `{id, full_name, authority, region, group_name, ref_no, attended, attended_time, attended_by_name, mine, can_unmark, stance}`
  (`stance` = `{value,label,color}` ya da yetkisizse `null`)
- `POST api/stance.php` (`person_id`, `value` = seçenek anahtarı ya da boş) → `{ok, person}`

### Tablolar (sql/schema.sql)
`users`, `events(is_active)`, `people(event_id, batch_id, ref_no, full_name, authority, region,
group_name, extra JSON, search_key, attended_at, attended_by)`, `attendance_log(event_id, person_id,
user_id, action mark|unmark, ip VARBINARY, created_at)`, `import_batches(event_id, filename,
row_count, skipped_count, user_id)`.

### CSS (public/assets/app.css — hazır sınıflar)
`container stack row spacer card btn btn-primary btn-success btn-danger btn-ghost btn-sm btn-lg
btn-block input field alert alert-error|success|warning|info badge badge-success|danger|warning
chip is-active stats stat stat-label stat-value progress table-wrap table num empty muted sr-only`.
Renk değişkenleri: `--success --success-soft --danger --primary --muted --border --surface --radius`.
Sayfaya özel stil kendi css dosyana.

---

## İŞ PAKETLERİ (dosya sahipliği sabit — başkasının dosyasına dokunma)

### E1 — Onay ekranı  · dosyalar: `public/app.php`, `public/assets/onay.js`, `public/assets/onay.css`
Telefonda tek elle kullanılan ana ekran. `require_perm('mark')`.
- Üstte yapışkan blok: büyük arama kutusu (`type="search"`, `inputmode="search"`, otomatik odak yok),
  sayaç "**34** / 240 geldi" + ince ilerleme çubuğu, durum seçici (Tümü / Gelmedi / Geldi).
- Bölge ve grup çipleri: sunucuda `people_breakdown()` ile al, `data-` niteliğine JSON olarak
  (`e(json_encode(...))`) koy. Bölge kısıtlı onaycıda bölge çipleri gizli. Tek seçim, tekrar dokununca kalkar.
- Liste: her kişi bir kart. Büyük ad, altında yetki · bölge · grup rozetleri, varsa ref no.
  Sağda büyük **GELDİ** butonu (min 56px yükseklik).
  İşaretliyse kart yeşil zemin, "✓ Geldi 10:42 · Ahmet Y." ve `can_unmark` ise küçük "Geri al" (confirm ile).
- Arama 250 ms debounce, önceki istek `AbortController` ile iptal. Sayfalama: altta "Daha fazla" butonu.
- İşaretlerken butonda yükleniyor durumu; yanıtta dönen `person` ile SADECE o kartı güncelle,
  `stats` ile sayacı güncelle. 409'da kartı dönen `person` ile güncelle + uyarı göster.
  401 → `index.php`'ye yönlendir. Ağ hatası → "Bağlantı yok, tekrar deneyin" uyarısı, kart değişmez.
- Uyarılar için ekran altında toast (`aria-live="polite"`), 3 sn.
- Başarıda `navigator.vibrate?.(30)`. Sekme tekrar görünür olunca (`visibilitychange`) listeyi yenile.
- Boş durum: "Sonuç yok" / aktif etkinlik yoksa "Henüz liste yüklenmedi".
- Kabul: 360px genişlikte yatay kaydırma yok; 200 kişide akıcı; hiçbir kişi verisi innerHTML ile basılmıyor.

### E2 — Liste yükleme + dışa aktarma · dosyalar: `public/admin/import.php`, `public/admin/export.php`, `src/spreadsheet.php` (YENİ dosya, sadece senin)
**Import** (`require_perm('import')`), 3 adım, tek sayfa (`?step=`):
1. Yükleme: sadece `.xlsx` ve `.csv` (uzantı + `finfo` kontrolü), ≤ 5 MB. Dosya
   `storage/imports/<bin2hex(random_bytes(16))>.<ext>` olarak taşınır (klasör yoksa oluştur);
   orijinal ad ve token `$_SESSION['import']`'a. 24 saatten eski geçici dosyaları temizle.
2. Önizleme: okuyucu tipini AÇIKÇA seç (`IOFactory::createReader('Xlsx'|'Csv')`, auto-detect yok),
   `setReadDataOnly(true)`, sadece ilk sayfa. CSV: UTF-8 (BOM'lu/BOM'suz), ayırıcıyı `;` / `,` / tab
   arasından ilk satıra göre seç. İlk boş olmayan satır = başlık. Limit: 10.000 satır, 50 sütun.
   Her sütun için `<select>`: Ad Soyad, Ad (ayrı), Soyad (ayrı), Yetki, Bölge, Grup, Referans No,
   Ek bilgi, Yok say. Başlıktan tahmin et (`search_key()` ile normalize: "ad soyad","adi soyadi",
   "isim","isim soyisim" → Ad Soyad; "ad","adi" → Ad; "soyad","soyadi" → Soyad; "yetki","gorev","unvan"
   → Yetki; "bolge","ilce","mahalle" → Bölge; "grup","grubu" → Grup; "no","sira no","uye no","sicil"
   → Referans No; diğerleri → Ek bilgi). "tc"/"kimlik"/"telefon" içeren sütunlar varsayılan **Yok say**.
   İlk 20 satırı tablo olarak göster (e() ile). Özet: toplam satır, boş satır, adı boş (atlanacak),
   dosya içinde aynı isim tekrarı (uyarı, yine eklenir), formül içeren hücre sayısı (uyarı).
   Mod seçimi: "Aktif etkinliğe ekle" ya da "Yeni etkinlik başlat" (+ ad alanı; aktif etkinlik yoksa
   sadece bu). Yeni etkinlik = eski liste silinmez, arşivde kalır.
3. Onay (POST): dosyayı session'daki token ile YENİDEN oku (istemciden satır verisi alma).
   Eşleme POST'tan gelir ama sadece izinli değerler kabul edilir; Ad Soyad ya da Ad'dan biri zorunlu.
   Tek transaction: gerekiyorsa `create_event()`, `import_batches` satırı, her satır için
   `insert_person()`, sonra batch'in `row_count/skipped_count` güncelle. Hata → rollback + flash.
   Başarı → geçici dosyayı sil, flash "N kişi eklendi, M satır atlandı", `admin/index.php`'ye yönlendir.
   Hücre değeri: formülse önbellekteki sonucu kullan, yoksa boş say; sayı `12.0` ise `12`;
   hepsi `clean_text()`'ten geçer. Ad + Soyad ayrıysa birleştir.

**Export** (`require_perm('export')`): `GET` form: etkinlik (varsayılan aktif; tüm etkinlikler listesi),
bölge, grup, durum. `?download=1` ile `.xlsx` indirir:
- Sayfa "Liste": Sıra, Ref No, Ad Soyad, Yetki, Bölge, Grup, Durum (Geldi/Gelmedi), Geliş saati
  (`fmt_datetime`), İşaretleyen. Sorgu `people_scope($user,'p',$eventId)` + filtreler, ad sıralı.
- Sayfa "Özet": bölge ve grup kırılımı (`people_breakdown`), toplam/gelen/oran.
- **Formül enjeksiyonu**: tüm metin hücreleri `setCellValueExplicit(..., DataType::TYPE_STRING)`;
  `= + - @` / tab / CR ile başlayanlara ayrıca `quotePrefix` stili.
- Başlık kalın, ilk satır dondurulmuş, otomatik filtre, sütun genişlikleri.
- Dosya adı: `katilim-<etkinlik-adı-slug>-<YmdHi>.xlsx`, doğru Content-Type/Content-Disposition, `exit`.

### E3 — Yönetim ekranları · dosyalar: `public/admin/index.php`, `public/admin/users.php`, `public/admin/events.php`, `public/admin/log.php`, `public/assets/admin.css`
- **index.php** (`dashboard`): 4 stat kartı (toplam, gelen, bekleyen, oran %). Bölge tablosu ve grup
  tablosu (`people_breakdown`): ad, toplam, gelen, oran + `.progress` çubuğu. Son 10 işlem
  (attendance_log + people + users join, aktif etkinlik). 60 sn'de bir sayfa yenilenir. Aktif
  etkinlik yoksa admin'e "Liste yükle" bağlantısı.
- **users.php** (`manage_users`): kullanıcı tablosu (kullanıcı adı, ad, rol, bölge, durum, son giriş).
  Yeni kullanıcı formu: kullanıcı adı (`valid_username`, benzersiz), ad soyad, rol, bölge
  (opsiyonel; mevcut bölgeler `<datalist>`). Şifre `generate_password()` ile üretilir ve
  flash ile **bir kez** gösterilir. Satır işlemleri (hepsi POST + CSRF): şifre sıfırla, aktif/pasif,
  düzenle (ad, rol, bölge). Admin kendini pasife alamaz ve kendi rolünü düşüremez.
  Son aktif admin pasife alınamaz/rolü düşürülemez.
- **events.php** (`manage_events`): etkinlikler (ad, oluşturma, kişi sayısı, gelen, aktif rozeti).
  "Aktif yap" (`activate_event`), yeniden adlandır, boş etkinlik oluştur. Silme yok.
- **log.php** (`view_log`): işlem kaydı, aktif etkinlik (view_all için etkinlik seçilebilir).
  Filtre: kullanıcı, işlem (işaretledi/geri aldı), bölge. 50'şer sayfalama. Sütunlar: saat
  (`fmt_datetime`), işlem rozeti, kişi, bölge, kullanıcı; IP sadece admin'e (`ip_to_text`).
- Tablolar mobilde `.table-wrap` içinde yatay kayar; formlar tek sütun.

## Rapor
Bitince: değiştirdiğin/oluşturduğun dosyalar, `php -l` sonuçları, emin olmadığın noktalar.

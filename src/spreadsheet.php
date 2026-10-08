<?php
declare(strict_types=1);

/*
 * Excel/CSV okuma (import) ve xlsx yazma (export).
 * İlke: kişisel veri (mail, ortaklar, TC, IBAN) ALINMAZ ve önizlemede ekrana bile basılmaz;
 * yetkili sütunundan doğum tarihi/yeri atılır. İki istisna:
 *  - Telefon (Gsm/Tel): kartta "ara" simgesi için "Telefon" olarak alınabilir, başka alana eşlenemez.
 *  - Adres (firma adresi): sistem sahibinin kararıyla TAM ADRES saklanır (people.address; kartta açılır +
 *    "Yol tarifi") ve ayrıca mahalle türetilir (extract_neighborhood -> people.neighborhood). Adres sütunu
 *    sadece "Adres" ya da "Alma" olabilir; başka alana (Ek bilgi vb.) eşlenemez.
 */

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const IMPORT_MAX_ROWS = 10000;
const IMPORT_MAX_COLS = 50;

/** Sütun eşleme seçenekleri (anahtar => ekrandaki ad). */
const IMPORT_FIELDS = [
    'full_name'  => 'Unvan / Ad Soyad',
    'first_name' => 'Ad (ayrı sütun)',
    'last_name'  => 'Soyad (ayrı sütun)',
    'authority'  => 'Yetkili',
    'region'     => 'İlçe / Bölge',
    'neighborhood' => 'Mahalle',
    'address'    => 'Adres (tam adres + mahalle)',
    'group_name' => 'Grup',
    'ref_no'     => 'Sicil / Referans No',
    'phone'      => 'Telefon (arama için)',
    'stance'     => 'Bizimle mi?',
    'transfer'   => 'Transfer',
    'extra'      => 'Ek bilgi (sakla)',
    'ignore'     => 'Alma',
];

/** Başlık (search_key ile normalize) -> alan. Önce kişisel veri kontrol edilir. */
/**
 * Kişisel veri sütunu mu? Bunlar KİLİTLİ: eşleme formundan bile alınamaz (sunucu zorlar),
 * önizlemede gösterilmez. "tc" sadece tam kelime (başka kelimelerin içinde geçebilir).
 */
function is_personal_header(string $header): bool
{
    $k = search_key($header);
    foreach (['tel', 'gsm', 'telefon', 'cep', 'mail', 'eposta', 'e posta', 'adres', 'ortak', 'tc', 'kimlik', 'dogum', 'iban'] as $p) {
        if ($k === $p || str_contains(' ' . $k . ' ', ' ' . $p . ' ') || ($p !== 'tc' && str_contains($k, $p))) {
            return true;
        }
    }
    return false;
}

/** Telefon sütunu mu? (Gsm, Tel, Cep, Telefon...) Kişisel veridir ama "Telefon" olarak alınabilir. */
function is_phone_header(string $header): bool
{
    $k = ' ' . search_key($header) . ' ';
    foreach (['tel', 'gsm', 'telefon', 'telefonu', 'cep', 'ceptel', 'mobil', 'phone'] as $p) {
        if (str_contains($k, " $p ")) { // tam kelime: "Gsm", "Cep Tel", "Telefon No"
            return true;
        }
    }
    return false;
}

/** Adres sütunu mu? ("Adres", "İş Adresi"; e-posta adresi değil). Sadece "Adres" ya da "Alma" olabilir. */
function is_address_header(string $header): bool
{
    $k = search_key($header);
    return str_contains($k, 'adres') && !str_contains($k, 'posta') && !str_contains($k, 'mail');
}

/** Kişisel veri sütunu bu alana eşlenebilir mi? (telefon: "Telefon"/"Alma"; adres: "Adres"/"Alma") */
function allowed_field(string $header, string $field): bool
{
    if (!isset(IMPORT_FIELDS[$field])) {
        return false;
    }
    if (is_phone_header($header)) {
        return in_array($field, ['phone', 'ignore'], true);
    }
    if (is_address_header($header)) {
        return in_array($field, ['address', 'ignore'], true);
    }
    return $field === 'ignore' || (!is_personal_header($header) && !in_array($field, ['phone', 'address'], true));
}

function guess_field(string $header): string
{
    $k = search_key($header);
    if (is_phone_header($header)) {
        return 'phone';
    }
    if ($k !== '' && is_address_header($header)) {
        return 'address'; // tam adres saklanır + mahalle türetilir
    }
    if ($k === '' || is_personal_header($header)) {
        return 'ignore';
    }
    if (in_array($k, ['nace', 'temsil', 'temsil sekli'], true)) {
        return 'ignore'; // kişisel değil ama gereksiz; admin isterse "Ek bilgi" seçebilir
    }
    $map = [
        'full_name'  => ['unvan', 'unvani', 'firma unvani', 'ticaret unvani', 'ad soyad', 'adi soyadi', 'adsoyad', 'isim', 'isim soyisim', 'ad soyadi', 'firma', 'firma adi', 'uye', 'uye adi'],
        'first_name' => ['ad', 'adi', 'isim'],
        'last_name'  => ['soyad', 'soyadi', 'soyisim'],
        'authority'  => ['yetkili', 'yetkililer', 'yetkili kisi', 'yetkili kisiler', 'yetki'],
        'region'     => ['ilce', 'bolge', 'bolgesi', 'ilcesi'],
        'neighborhood' => ['mahalle', 'mahallesi', 'mah'],
        'group_name' => ['grup', 'grubu', 'grup adi', 'meslek grubu'],
        'ref_no'     => ['osicilno', 'sicil', 'sicil no', 'sicilno', 'oda sicil no', 'uye no', 'referans', 'referans no'],
        // "durum" bilerek yok: kendi export'umuzda Durum = Geldi/Gelmedi
        'stance'     => ['bizimle', 'bizimle mi', 'egilim'],
        'transfer'   => ['transfer', 'transfer durumu', 'transfer mi'],
    ];
    foreach ($map as $field => $names) { // sıra önemli: "isim" önce full_name'de yakalanır
        if (in_array($k, $names, true)) {
            return $field;
        }
    }
    if (in_array($k, ['sno', 'sira', 'sira no', 'no', 'id'], true)) {
        return 'ignore';
    }
    return 'extra';
}

/** "grup 10" -> "Grup 10" (Türkçe i/İ doğru) */
function sheet_title_to_group(string $title): ?string
{
    $t = clean_text($title, 120);
    if ($t === null || preg_match('/^(sheet|sayfa)\s*\d*$/i', $t)) {
        return null; // varsayılan sayfa adı grup değildir
    }
    return tr_upper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
}

/**
 * Yüklenen dosyanın ilk kontrolü (import ve Bizimkiler ortak). Hata mesajı ya da null.
 * Uzantı + gerçek içerik türü (finfo) + boyut.
 */
function upload_problem(?array $f): ?string
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > IMPORT_MAX_BYTES) {
        return 'Dosya çok büyük (en fazla 5 MB).';
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'Dosya yüklenemedi, tekrar deneyin.';
    }
    $ext = upload_ext($f);
    $okMime = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'],
    ];
    if (!isset($okMime[$ext])) {
        return 'Sadece .xlsx ya da .csv yükleyin. Eski .xls dosyasını Excel’de "Farklı kaydet → .xlsx" yapın.';
    }
    if (!in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']), $okMime[$ext], true)) {
        return 'Dosyanın içeriği uzantısıyla uyuşmuyor.';
    }
    return null;
}

function upload_ext(?array $f): string
{
    return strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
}

/**
 * Zip-bomb / dev tablo koruması: xlsx AÇILMADAN önce sıkıştırılmamış boyut, oran ve
 * satır sayısı kontrol edilir (paylaşımlı hostun belleği bitmesin).
 * @throws RuntimeException
 */
function xlsx_guard(string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Dosya okunamadı. Geçerli bir .xlsx olduğundan emin olun.');
    }
    $total = 0;
    try {
        if ($zip->numFiles > 2000) {
            throw new RuntimeException('Dosya yapısı beklenmedik derecede büyük.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $total += $st['size'];
            $ratio = $st['comp_size'] > 0 ? $st['size'] / $st['comp_size'] : 0;
            if ($total > 60 * 1024 * 1024 || ($st['size'] > 5 * 1024 * 1024 && $ratio > 100)) {
                throw new RuntimeException('Dosya açıldığında çok büyük oluyor; listeyi bölün.');
            }
        }
    } finally {
        $zip->close();
    }
    try {
        $info = IOFactory::createReader('Xlsx')->listWorksheetInfo($path)[0] ?? null;
    } catch (Throwable $e) {
        throw new RuntimeException('Dosya okunamadı. Geçerli bir .xlsx olduğundan emin olun.');
    }
    if ($info && $info['totalRows'] > IMPORT_MAX_ROWS + 20) {
        throw new RuntimeException('Dosyada ' . $info['totalRows'] . ' satır var; en fazla ' . IMPORT_MAX_ROWS . ' satır yüklenebilir. Listeyi bölün.');
    }
}

/** Hücre -> metin. Formülde Excel'in kaydettiği sonucu kullanır, motorla HESAPLAMAZ. */
function cell_text(Cell $cell, int &$formulas): ?string
{
    $v = $cell->getValue();
    if ($cell->isFormula()) {
        $formulas++;
        $v = $cell->getOldCalculatedValue();
    }
    if ($v instanceof RichText) {
        $v = $v->getPlainText();
    }
    if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
        $v = (string) (int) $v;   // 133419.0 -> "133419"
    } elseif (is_bool($v)) {
        $v = $v ? '1' : '0';
    }
    $s = clean_text($v, 2000);
    return ($s === null || strtoupper($s) === 'NULL') ? null : $s;
}

/**
 * Dosyanın ilk sayfasını okur.
 * @return array{title:string, header:list<string>, rows:list<list<?string>>, empty_rows:int, formulas:int, truncated_cols:bool}
 * @throws RuntimeException kullanıcıya gösterilebilir mesajla
 */
function read_sheet(string $path, string $ext): array
{
    try {
        if ($ext === 'csv') {
            $reader = new CsvReader();
            $reader->setInputEncoding(CsvReader::GUESS_ENCODING);
            $reader->setFallbackEncoding('CP1254'); // Türkçe Excel'in CSV'si çoğu zaman Windows-1254
            $reader->setDelimiter(csv_delimiter($path));
        } else {
            xlsx_guard($path);
            $reader = IOFactory::createReader('Xlsx'); // tip AÇIKÇA seçilir, içerikten tahmin yok
            $names = $reader->listWorksheetNames($path);
            $reader->setLoadSheetsOnly([$names[0]]);
        }
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
    } catch (RuntimeException $e) {
        throw $e; // xlsx_guard mesajı kullanıcıya aynen
    } catch (Throwable $e) {
        throw new RuntimeException('Dosya okunamadı. Geçerli bir .xlsx ya da .csv olduğundan emin olun.');
    }

    $sheet = $book->getSheet(0);
    $maxRow = $sheet->getHighestDataRow();
    $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    if ($maxRow - 1 > IMPORT_MAX_ROWS) {
        throw new RuntimeException('Dosyada ' . ($maxRow - 1) . ' satır var; en fazla ' . IMPORT_MAX_ROWS . ' satır yüklenebilir. Listeyi bölün.');
    }
    $truncated = $maxCol > IMPORT_MAX_COLS;
    $maxCol = min($maxCol, IMPORT_MAX_COLS);

    $header = null;
    $rows = [];
    $empty = 0;
    $formulas = 0;
    for ($r = 1; $r <= $maxRow; $r++) {
        $row = [];
        $any = false;
        for ($c = 1; $c <= $maxCol; $c++) {
            $val = cell_text($sheet->getCell([$c, $r]), $formulas);
            $row[] = $val;
            $any = $any || $val !== null;
        }
        if (!$any) {
            if ($header !== null) {
                $empty++;
            }
            continue;
        }
        if ($header === null) {
            // Tek hücreli üst satır ("İSİM LİSTESİ" gibi tablo başlığı) sütun başlığı değildir
            if ($maxCol > 1 && count(array_filter($row, fn($v) => $v !== null)) === 1) {
                continue;
            }
            $header = array_map(fn($h, $i) => $h ?? ('Sütun ' . ($i + 1)), $row, array_keys($row));
            continue;
        }
        $rows[] = $row;
    }
    $title = $sheet->getTitle();
    $book->disconnectWorksheets();

    if ($header === null) {
        throw new RuntimeException('Dosya boş görünüyor.');
    }
    return ['title' => $title, 'header' => $header, 'rows' => $rows, 'empty_rows' => $empty,
            'formulas' => $formulas, 'truncated_cols' => $truncated];
}

/** İlk satıra bakarak ; , ya da TAB seçer. */
function csv_delimiter(string $path): string
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Dosya açılamadı.');
    }
    $first = (string) fgets($fh);
    fclose($fh);
    $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($counts);
    return (string) array_key_first($counts);
}

/**
 * Satırları eşlemeye göre kişi kayıtlarına çevirir.
 * @param list<string> $mapping sütun sırasıyla IMPORT_FIELDS anahtarı
 * @return array{people:list<array>, skipped:int, duplicates:int}
 */
/** Hem Gsm hem Tel varsa cep numarası öncelikli; hepsi geçersizse null. @param list<?string> $normalized */
function pick_phone(array $normalized): ?string
{
    $phones = array_values(array_filter($normalized));
    usort($phones, fn($a, $b) => is_mobile_phone($b) <=> is_mobile_phone($a));
    return $phones[0] ?? null;
}

/**
 * "Sadece telefonları güncelle" için satırlar: ad sütunu gerekmez (Sicil + Gsm yeter).
 * @return list<array{ref_no:?string, phone:?string}>
 */
function phone_rows(array $rows, array $mapping): array
{
    $out = [];
    foreach ($rows as $row) {
        $ref = null;
        $phones = [];
        foreach ($mapping as $i => $field) {
            $v = $row[$i] ?? null;
            if ($v === null) {
                continue;
            }
            if ($field === 'ref_no') {
                $ref = $v;
            } elseif ($field === 'phone') {
                $phones[] = normalize_phone($v);
            }
        }
        $out[] = ['ref_no' => $ref, 'phone' => pick_phone($phones)];
    }
    return $out;
}

/**
 * "Mevcut kayıtları güncelle" için satırlar: eşleşme anahtarı sicil no (varsa) ya da ad; güncellenecek
 * alanlar telefon / adres / mahalle (adresten türetilmiş ya da ayrı sütun) / transfer.
 * @return list<array{ref_no:?string, name:?string, phone:?string, neighborhood:?string, address:?string, transfer:?string}>
 */
function enrich_rows(array $rows, array $mapping): array
{
    $out = [];
    foreach ($rows as $row) {
        $d = ['ref_no' => null, 'phone' => [], 'neighborhood' => null, 'address_hood' => null, 'address' => null, 'transfer' => null];
        $full = $first = $last = null;
        foreach ($mapping as $i => $field) {
            $v = $row[$i] ?? null;
            if ($v === null) {
                continue;
            }
            match ($field) {
                'ref_no'       => $d['ref_no'] = $v,
                'phone'        => $d['phone'][] = normalize_phone($v),
                'neighborhood' => $d['neighborhood'] = normalize_neighborhood($v),
                'address'      => [$d['address'], $d['address_hood']] = [clean_text($v, 500), extract_neighborhood($v)],
                'transfer'     => $d['transfer'] = transfer_from_text($v),
                'full_name'    => $full = $v,
                'first_name'   => $first = $v,
                'last_name'    => $last = $v,
                default        => null,
            };
        }
        $out[] = [
            'ref_no'       => $d['ref_no'],
            'name'         => $full ?? clean_text(trim(($first ?? '') . ' ' . ($last ?? ''))),
            'phone'        => pick_phone($d['phone']),
            'neighborhood' => $d['neighborhood'] ?? $d['address_hood'],
            'address'      => $d['address'],
            'transfer'     => $d['transfer'],
        ];
    }
    return $out;
}

function build_people(array $header, array $rows, array $mapping, ?string $fixedGroup): array
{
    $people = [];
    $skipped = 0;
    $seen = [];
    $duplicates = 0;
    foreach ($rows as $row) {
        $d = ['extra' => []];
        $first = $last = null;
        $phones = [];
        $addressHood = null;
        foreach ($mapping as $i => $field) {
            $v = $row[$i] ?? null;
            if ($v === null || $field === 'ignore') {
                continue;
            }
            match ($field) {
                'first_name' => $first = $v,
                'last_name'  => $last = $v,
                'extra'      => $d['extra'][$header[$i]] = $v,
                'authority'  => $d['authority'] = names_only($v),
                'phone'      => $phones[] = normalize_phone($v),
                'neighborhood' => $d['neighborhood'] = normalize_neighborhood($v),
                'address'    => [$d['address'], $addressHood] = [clean_text($v, 500), extract_neighborhood($v)],
                'transfer'   => $d['transfer'] = transfer_from_text($v),
                default      => $d[$field] = $v,
            };
        }
        $d['phone'] = pick_phone($phones);
        $d['neighborhood'] = ($d['neighborhood'] ?? null) ?? $addressHood;
        $d['full_name'] ??= clean_text(trim(($first ?? '') . ' ' . ($last ?? '')));
        if ($d['full_name'] === null) {
            $skipped++;
            continue;
        }
        $d['group_name'] ??= $fixedGroup;
        $key = search_key($d['full_name']);
        if (isset($seen[$key])) {
            $duplicates++;
        }
        $seen[$key] = true;
        $people[] = $d;
    }
    return ['people' => $people, 'skipped' => $skipped, 'duplicates' => $duplicates];
}

/* ---------------- Export ---------------- */

/** Metin hücresi: asla formül olarak yorumlanmaz (formül enjeksiyonu). */
function xlsx_text(Worksheet $sheet, string $coord, mixed $value): void
{
    $s = (string) ($value ?? '');
    $sheet->setCellValueExplicit($coord, $s, DataType::TYPE_STRING);
    if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
        $sheet->getStyle($coord)->setQuotePrefix(true);
    }
}

/** Başlık satırı: kalın, gri, donuk, filtreli. */
function xlsx_header(Worksheet $sheet, array $titles, int $rows): void
{
    foreach ($titles as $i => $t) {
        xlsx_text($sheet, Coordinate::stringFromColumnIndex($i + 1) . '1', $t);
    }
    $last = Coordinate::stringFromColumnIndex(count($titles));
    $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
    $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('E8ECF2');
    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$last}" . max(1, $rows + 1));
}

/** Seçilen etkinlik + filtrelerle xlsx'i indirir ve çıkar. */
function export_xlsx(array $user, array $event, array $filters): never
{
    $withStance = can('stance', $user);
    $withTransfer = can('transfer', $user);
    [$where, $params] = people_where($user, $filters, (int) $event['id']);
    $rows = q(PERSON_SELECT . " WHERE $where ORDER BY COALESCE(p.authority, p.full_name), p.id", $params)->fetchAll();
    audit('export', $event['name'], ['satır' => count($rows), 'filtre' => array_filter($filters, fn($v) => $v !== '' && $v !== 'all')]);

    $book = new Spreadsheet();
    $book->getProperties()->setCreator((string) config('app_name', 'Katılım Takip'))->setTitle($event['name']);

    // --- Liste
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Liste');
    $titles = ['Sıra', 'Ad Soyad (Yetkili)', 'Firma (Unvan)', 'Sicil No', 'Telefon', 'İlçe', 'Mahalle', 'Adres', 'Grup'];
    if ($withStance) {
        $titles[] = 'Bizimle mi?';
    }
    if ($withTransfer) {
        $titles[] = 'Transfer';
    }
    array_push($titles, 'Durum', 'Geliş saati', 'İşaretleyen');
    xlsx_header($sheet, $titles, count($rows));

    foreach ($rows as $n => $p) {
        $r = $n + 2;
        $vals = [$n + 1, $p['authority'] ?? $p['full_name'], $p['authority'] ? $p['full_name'] : '', $p['ref_no'], phone_display($p['phone']), $p['region'], $p['neighborhood'], $p['address'], $p['group_name']];
        if ($withStance) {
            $vals[] = $p['stance'] === null ? '' : stance_view($p['stance'])['label'];
        }
        if ($withTransfer) {
            $vals[] = transfer_view($p['transfer'])['label'];
        }
        array_push($vals, $p['attended_at'] ? 'Geldi' : 'Gelmedi', fmt_datetime($p['attended_at']), $p['attended_by_name']);
        foreach ($vals as $i => $v) {
            $coord = Coordinate::stringFromColumnIndex($i + 1) . $r;
            $i === 0 ? $sheet->setCellValue($coord, $v) : xlsx_text($sheet, $coord, $v);
        }
    }
    foreach (range(1, count($titles)) as $i) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
    $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(36);
    $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(48);
    $sheet->getColumnDimension('H')->setAutoSize(false)->setWidth(48);

    // --- Özet
    $sum = $book->createSheet();
    $sum->setTitle('Özet');
    $r = 1;
    foreach (['region' => 'İlçe', 'group_name' => 'Grup'] as $col => $label) {
        foreach ([$label, 'Toplam', 'Gelen', 'Oran'] as $i => $t) {
            xlsx_text($sum, Coordinate::stringFromColumnIndex($i + 1) . $r, $t);
        }
        $sum->getStyle("A$r:D$r")->getFont()->setBold(true);
        $r++;
        foreach (people_breakdown($user, $col, (int) $event['id']) as $b) {
            xlsx_text($sum, "A$r", $b['label']);
            $sum->setCellValue("B$r", $b['total']);
            $sum->setCellValue("C$r", $b['came']);
            $sum->setCellValue("D$r", $b['total'] ? $b['came'] / $b['total'] : 0);
            $sum->getStyle("D$r")->getNumberFormat()->setFormatCode('0%');
            $r++;
        }
        $r++;
    }
    $sum->getColumnDimension('A')->setWidth(28);

    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', search_key($event['name'])), '-') ?: 'etkinlik';
    $file = 'katilim-' . $slug . '-' . (new DateTimeImmutable('now', local_tz()))->format('Ymd-Hi') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    (new XlsxWriter($book))->save('php://output');
    exit;
}

/**
 * Telefonları günceller (eski adıyla; update_people_fields'in sadece-telefon hali).
 * @return array{updated:int, unchanged:int, not_found:int, no_phone:int}
 */
function update_phones(int $eventId, array $people): array
{
    $r = update_people_fields($eventId, array_map(fn($p) => ['ref_no' => $p['ref_no'] ?? null, 'phone' => $p['phone'] ?? null], $people), ['phone']);
    return ['updated' => $r['updated'], 'unchanged' => $r['unchanged'], 'not_found' => $r['not_found'], 'no_phone' => $r['nothing']];
}

/**
 * MEVCUT kayıtları zenginleştirir: dosyadaki satır, aktif listedeki kayıtla sicil no ile (varsa; yer tutucu
 * sicil sayılmaz) ya da yoksa AD ile (tam ad eşleşmesi, tek firma) eşleşir; telefon / mahalle / transfer yazılır.
 * Kişi eklenmez/silinmez. attended_at, attended_by, stance ve diğer alanlara DOKUNULMAZ. Dosyada boş (ya da
 * tanınmayan) değer var olanı silmez. Mahalle değişince search_key de yeniden hesaplanır (aramada bulunsun).
 * Aynı dosyayı tekrar yüklemek güvenlidir (idempotent): ikinci seferde hepsi "zaten aynı" çıkar.
 * @param list<array{ref_no?:?string, name?:?string, phone?:?string, neighborhood?:?string, address?:?string, transfer?:?string}> $rows
 * @param list<string> $fields yazılacak alanlar: phone | neighborhood | address | transfer
 * @return array{updated:int, unchanged:int, not_found:int, ambiguous:int, nothing:int, phones:int, neighborhoods:int, addresses:int, transfers:int}
 */
function update_people_fields(int $eventId, array $rows, array $fields = ['phone', 'neighborhood', 'address', 'transfer']): array
{
    $placeholder = (string) config('placeholder_ref', '11111111');
    $out = ['updated' => 0, 'unchanged' => 0, 'not_found' => 0, 'ambiguous' => 0, 'nothing' => 0,
            'phones' => 0, 'neighborhoods' => 0, 'addresses' => 0, 'transfers' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $ref = clean_text($row['ref_no'] ?? null, 64);
            if ($ref === $placeholder) {
                $ref = null;
            }
            $name = clean_text($row['name'] ?? null, 160);
            if ($ref === null && $name === null) {
                $out['not_found']++;
                continue;
            }
            $want = [];
            if (in_array('phone', $fields, true) && ($v = normalize_phone($row['phone'] ?? null)) !== null) {
                $want['phone'] = $v;
            }
            if (in_array('neighborhood', $fields, true) && ($v = clean_text($row['neighborhood'] ?? null, 120)) !== null) {
                $want['neighborhood'] = $v;
            }
            if (in_array('address', $fields, true) && ($v = clean_text($row['address'] ?? null, 500)) !== null) {
                $want['address'] = $v;
            }
            if (in_array('transfer', $fields, true) && ($v = transfer_from_text($row['transfer'] ?? null)) !== null) {
                $want['transfer'] = $v;
            }
            if (!$want) {
                $out['nothing']++;
                continue;
            }

            $cols = 'id, full_name, authority, ref_no, phone, neighborhood, address, transfer, firm_key';
            if ($ref !== null) {
                $found = q("SELECT $cols FROM people WHERE event_id = ? AND ref_no = ?", [$eventId, $ref])->fetchAll();
            } else {
                // Sicil yok: tam ad eşleşmesi; aynı adlı farklı firmalar varsa tahmin yürütme, atla.
                // Eşleşme search_key ile (SQL = Türkçe i/ı ayrımı yüzünden "Isim" ile "ISIM"i eşleştirmez).
                $key = search_key($name);
                $words = array_slice(array_filter(explode(' ', $key)), 0, 6);
                $like = implode(' AND ', array_fill(0, count($words), 'search_key LIKE ?')) ?: '1 = 0';
                $found = array_values(array_filter(
                    q("SELECT $cols FROM people WHERE event_id = ? AND $like", [$eventId, ...array_map(fn($w) => '%' . like_escape($w) . '%', $words)])->fetchAll(),
                    fn($r) => search_key($r['full_name']) === $key
                ));
                if (count(array_unique(array_column($found, 'firm_key'))) > 1) {
                    $out['ambiguous']++;
                    continue;
                }
            }
            if (!$found) {
                $out['not_found']++;
                continue;
            }
            $changed = false;
            foreach ($found as $r) {
                $set = array_filter($want, fn($v, $f) => $r[$f] !== $v, ARRAY_FILTER_USE_BOTH);
                if (!$set) {
                    continue;
                }
                $changed = true;
                $sql = implode(', ', array_map(fn($f) => "$f = ?", array_keys($set)));
                $params = array_values($set);
                if (isset($set['neighborhood'])) {
                    $sql .= ', search_key = ?';
                    $params[] = search_key($r['full_name'], $r['authority'], $r['ref_no'], $set['neighborhood']);
                }
                q("UPDATE people SET $sql WHERE id = ?", [...$params, $r['id']]);
                foreach (['phone' => 'phones', 'neighborhood' => 'neighborhoods', 'address' => 'addresses', 'transfer' => 'transfers'] as $f => $counter) {
                    if (isset($set[$f])) {
                        $out[$counter]++;
                    }
                }
            }
            $out[$changed ? 'updated' : 'unchanged']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $out;
}

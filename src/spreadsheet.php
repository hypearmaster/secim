<?php
declare(strict_types=1);

/*
 * Excel/CSV okuma (import) ve xlsx yazma (export).
 * İlke: kişisel veri (telefon, mail, adres, ortaklar, TC) varsayılan olarak ALINMAZ
 * ve önizlemede ekrana bile basılmaz; yetkili sütunundan doğum tarihi/yeri atılır.
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
    'group_name' => 'Grup',
    'ref_no'     => 'Sicil / Referans No',
    'stance'     => 'Bizimle mi?',
    'extra'      => 'Ek bilgi (sakla)',
    'ignore'     => 'Alma',
];

/** Başlık (search_key ile normalize) -> alan. Önce kişisel veri kontrol edilir. */
function guess_field(string $header): string
{
    $k = search_key($header);
    if ($k === '') {
        return 'ignore';
    }
    foreach (['tel', 'gsm', 'telefon', 'cep', 'mail', 'eposta', 'e posta', 'adres', 'ortak', 'tc', 'kimlik', 'nace', 'temsil', 'dogum', 'iban'] as $p) {
        if ($k === $p || str_starts_with($k, $p . ' ') || str_contains(' ' . $k . ' ', ' ' . $p . ' ') || ($p !== 'tc' && str_contains($k, $p))) {
            return 'ignore';
        }
    }
    $map = [
        'full_name'  => ['unvan', 'unvani', 'ad soyad', 'adi soyadi', 'adsoyad', 'isim', 'isim soyisim', 'ad soyadi', 'firma', 'firma adi', 'uye', 'uye adi'],
        'first_name' => ['ad', 'adi', 'isim'],
        'last_name'  => ['soyad', 'soyadi', 'soyisim'],
        'authority'  => ['yetkili', 'yetkililer', 'yetkili kisi', 'yetkili kisiler', 'yetki'],
        'region'     => ['ilce', 'bolge', 'bolgesi', 'ilcesi', 'mahalle'],
        'group_name' => ['grup', 'grubu', 'grup adi', 'meslek grubu'],
        'ref_no'     => ['osicilno', 'sicil', 'sicil no', 'sicilno', 'oda sicil no', 'uye no', 'referans', 'referans no'],
        // "durum" bilerek yok: kendi export'umuzda Durum = Geldi/Gelmedi
        'stance'     => ['bizimle', 'bizimle mi', 'egilim'],
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
            $reader = IOFactory::createReader('Xlsx'); // tip AÇIKÇA seçilir, içerikten tahmin yok
            $names = $reader->listWorksheetNames($path);
            $reader->setLoadSheetsOnly([$names[0]]);
        }
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
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
function build_people(array $header, array $rows, array $mapping, ?string $fixedGroup): array
{
    $people = [];
    $skipped = 0;
    $seen = [];
    $duplicates = 0;
    foreach ($rows as $row) {
        $d = ['extra' => []];
        $first = $last = null;
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
                default      => $d[$field] = $v,
            };
        }
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
    [$where, $params] = people_where($user, $filters, (int) $event['id']);
    $rows = q(PERSON_SELECT . " WHERE $where ORDER BY COALESCE(p.authority, p.full_name), p.id", $params)->fetchAll();
    audit('export', $event['name'], ['satır' => count($rows), 'filtre' => array_filter($filters, fn($v) => $v !== '' && $v !== 'all')]);

    $book = new Spreadsheet();
    $book->getProperties()->setCreator((string) config('app_name', 'Katılım Takip'))->setTitle($event['name']);

    // --- Liste
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Liste');
    $titles = ['Sıra', 'Ad Soyad (Yetkili)', 'Firma (Unvan)', 'Sicil No', 'İlçe', 'Grup'];
    if ($withStance) {
        $titles[] = 'Bizimle mi?';
    }
    array_push($titles, 'Durum', 'Geliş saati', 'İşaretleyen');
    xlsx_header($sheet, $titles, count($rows));

    foreach ($rows as $n => $p) {
        $r = $n + 2;
        $vals = [$n + 1, $p['authority'] ?? $p['full_name'], $p['authority'] ? $p['full_name'] : '', $p['ref_no'], $p['region'], $p['group_name']];
        if ($withStance) {
            $vals[] = $p['stance'] === null ? '' : stance_view($p['stance'])['label'];
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

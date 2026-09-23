<?php
/**
 * Parser HTML → dokumen Word (.docx) untuk Laporan Monev.
 *
 * Dipakai oleh fitur "Edit Online": konten HTML yang telah diedit
 * pengguna di browser dikirim ke server, lalu dikonversi menjadi
 * dokumen Word asli (OOXML) via PhpWord.
 *
 * Style mapping (konsisten dengan generator docx native):
 *   - Isi teks     : Arial 11
 *   - Judul/heading: Arial 12 bold
 *   - Tabel        : Arial 9, border hitam, warna sel dipertahankan
 *   - Page break   : tiap bagian (cover/pengesahan/BAB) halaman sendiri
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Konversi HTML menjadi dokumen PhpWord.
 *
 * @param string $html Konten .page-wrapper hasil edit pengguna
 * @return \PhpOffice\PhpWord\PhpWord
 */
function laporanMonevHtmlToDocx(string $html): \PhpOffice\PhpWord\PhpWord
{
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(11);

    $section = $phpWord->addSection([
        'pageSize'      => ['width' => 11906, 'height' => 16838], // A4 portrait (twips)
        'margin_top'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
        'margin_right'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
        'margin_bottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
        'margin_left'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
    ]);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    laporanMonexSanitizeDom($doc);

    $walker = new LaporanMonevWalker($section);
    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body !== null) {
        $walker->walkChildren($body);
    }

    return $phpWord;
}

/**
 * Bersihkan DOM dari elemen/atribut berbahaya (script, event handler,
 * javascript: URL). Input pengguna tidak boleh lolos mentah ke dokumen.
 */
function laporanMonexSanitizeDom(DOMDocument $doc): void
{
    $xpath = new DOMXPath($doc);

    // Hapus <script> dan <style> blok
    foreach (iterator_to_array($xpath->query('//script | //style')) as $node) {
        $node->parentNode?->removeChild($node);
    }

    // Hapus atribut event (on*) dan href/src javascript:
    foreach ($xpath->query('//*') as $el) {
        /** @var DOMElement $el */
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name  = strtolower($attr->name);
            $value = strtolower(trim($attr->value));
            if (str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->name);
            } elseif (in_array($name, ['href', 'src'], true) && str_starts_with($value, 'javascript:')) {
                $el->removeAttribute($attr->name);
            } elseif ($name === 'contenteditable') {
                $el->removeAttribute($attr->name);
            }
        }
    }
}

/**
 * Walker DOM → elemen PhpWord.
 */
final class LaporanMonevWalker
{
    private const AREA_TWIPS = 9355; // lebar area cetak A4 setelah margin

    /** Skema lebar kolom untuk tabel laporan 12 kolom (No,Kode,Pernyataan,P,D,Nilai,Tingkat,Upaya,P,D,Nilai,Tingkat) */
    private const TABLE_LAPORAN_WIDTHS = [350, 850, 2000, 280, 280, 400, 950, 1700, 280, 280, 400, 950];

    private \PhpOffice\PhpWord\Element\Section $section;
    private int $blockIndex = 0;

    public function __construct(\PhpOffice\PhpWord\Element\Section $section)
    {
        $this->section = $section;
    }

    public function walkChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            $this->walkNode($child);
        }
    }

    private function walkNode(DOMNode $n): void
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            $t = trim(preg_replace('/\s+/', ' ', $n->textContent) ?? '');
            if ($t !== '') {
                $this->section->addText($t);
            }
            return;
        }
        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $tag   = strtolower($n->nodeName);
        $class = (string)($n->getAttribute('class') ?? '');

        switch ($tag) {
            case 'div':
                if (str_contains($class, 'no-print')) {
                    return; // toolbar UI tidak masuk dokumen
                }
                if (str_contains($class, 'ttd-space')) {
                    $this->section->addTextBreak(4);
                    return;
                }
                // Tiap bagian "kertas" (cover/pengesahan/BAB) → halaman sendiri
                if ($this->isPageSection($class) && $this->blockIndex > 0) {
                    $this->section->addPageBreak();
                }
                if ($this->isPageSection($class)) {
                    $this->blockIndex++;
                }
                $this->walkChildren($n);
                return;

            case 'h1':
            case 'h2':
                $run = $this->section->addTextRun(['alignment' => 'center', 'spaceBefore' => 240, 'spaceAfter' => 240]);
                $this->fillRuns($n, $run, ['bold' => true, 'size' => 12]);
                return;

            case 'h3':
            case 'h4':
                $run = $this->section->addTextRun(['spaceBefore' => 160, 'spaceAfter' => 80]);
                $this->fillRuns($n, $run, ['bold' => true, 'size' => 12]);
                return;

            case 'p':
                $align  = 'both';
                $isBold = false;
                $size   = 11;
                if (str_contains($class, 'tanggal-pengesahan')) {
                    $align = 'right';
                } elseif (str_contains($class, 'periode-label')) {
                    $align = 'center';
                    $size  = 12;
                }
                // Paragraf di dalam cover → center bold 12
                if ($this->insideCover($n)) {
                    $align  = 'center';
                    $isBold = true;
                    $size   = 12;
                }
                if ($this->insidePengesahanSubjudul($n)) {
                    $align  = 'center';
                    $isBold = true;
                    $size   = 11;
                }
                if ($this->insidePengesahanTtdCenter($n)) {
                    $align  = 'center';
                    $isBold = false;
                    $size   = str_contains($class, 'ttd-nip') ? 10 : 11;
                }
                $spaceAfter = ($this->insidePengesahanSubjudul($n) || $this->insidePengesahanTtdCenter($n)) ? 40 : 120;
                $run = $this->section->addTextRun(['alignment' => $align, 'spaceAfter' => $spaceAfter]);
                $this->fillRuns($n, $run, ['bold' => $isBold, 'size' => $size]);
                return;

            case 'ol':
            case 'ul':
                $this->walkList($n);
                return;

            case 'table':
                $this->walkTable($n);
                $this->section->addTextBreak(1);
                return;

            case 'hr':
                return; // divider visual — dilewati

            case 'br':
                return;

            case 'img':
                $this->addImageFromNode($n);
                return;

            case 'span':
            case 'a':
            case 'strong':
            case 'b':
            case 'em':
            case 'i':
            case 'u':
                // Inline di level blok (jarang) → treat sebagai paragraf
                $run = $this->section->addTextRun(['alignment' => 'both']);
                $this->fillRuns($n, $run, []);
                return;

            default:
                $this->walkChildren($n);
        }
    }

    private function isPageSection(string $class): bool
    {
        return str_contains($class, 'doc-page')
            || str_contains($class, 'cover')
            || str_contains($class, 'pengesahan')
            || str_contains($class, 'bab');
    }

    private function insideCover(DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof DOMElement && str_contains((string)($p->getAttribute('class') ?? ''), 'cover')) {
                return true;
            }
        }
        return false;
    }

    private function insidePengesahanSubjudul(DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof DOMElement && str_contains((string)($p->getAttribute('class') ?? ''), 'pengesahan-subjudul')) {
                return true;
            }
        }
        return false;
    }

    private function insidePengesahanTtdCenter(DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof DOMElement && str_contains((string)($p->getAttribute('class') ?? ''), 'pengesahan-ttd-center')) {
                return true;
            }
        }
        return false;
    }

    private function walkList(DOMElement $list): void
    {
        $no = 0;
        foreach ($list->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $no++;
            $run = $this->section->addTextRun([
                'alignment' => 'both',
                'indent'    => 360,
                'hanging'   => 360,
                'spaceAfter' => 40,
            ]);
            $run->addText($no . '. ', ['size' => 11]);
            $this->fillRuns($li, $run, ['size' => 11]);
        }
    }

    // ── Tabel (grid matrix: dukung colspan/rowspan) ─────────────

    private function walkTable(DOMElement $table): void
    {
        // Kumpulkan semua <tr> (thead/tbody/tfoot/direct)
        $rows = [];
        $this->collectRows($table, $rows);
        if (empty($rows)) {
            return;
        }

        // Hitung total kolom
        $totalCols = 0;
        foreach ($rows as $tr) {
            $c = 0;
            foreach ($this->cellsOf($tr) as $cell) {
                $c += $this->span($cell, 'colspan');
            }
            $totalCols = max($totalCols, $c);
        }
        if ($totalCols < 1) {
            return;
        }

        $tableClass   = (string)($table->getAttribute('class') ?? '');
        $isBorderless = str_contains($tableClass, 'borderless')
            || str_contains($tableClass, 'tabel-petugas')
            || str_contains(strtolower((string)$table->getAttribute('style')), 'border: none')
            || str_contains(strtolower((string)$table->getAttribute('style')), 'border:none');

        $widths = $this->columnWidths($totalCols, $tableClass);
        $tableOpts = [
            'width'      => 100,
            'unit'       => 'pct',
            'cellMargin' => 40,
        ];
        if (!$isBorderless) {
            $tableOpts['borderSize']  = 4;
            $tableOpts['borderColor'] = '000000';
        } else {
            $tableOpts['borderSize']  = 0;
            $tableOpts['borderColor'] = 'FFFFFF';
        }
        $phpTable = $this->section->addTable($tableOpts);

        $pending = []; // [rowIndex][colIndex] => true (dipesan oleh rowspan dari atas)

        foreach ($rows as $r => $tr) {
            $phpTable->addRow();
            $col = 0;

            foreach ($this->cellsOf($tr) as $cell) {
                // Kolom yang dipesan merge dari baris atas → cell lanjutan
                while ($col < $totalCols && isset($pending[$r][$col])) {
                    $phpTable->addCell($widths[$col], ['vMerge' => 'continue']);
                    $col++;
                }
                if ($col >= $totalCols) {
                    break;
                }

                $colspan = $this->span($cell, 'colspan');
                $rowspan = $this->span($cell, 'rowspan');
                $w = 0;
                for ($j = 0; $j < $colspan && ($col + $j) < $totalCols; $j++) {
                    $w += $widths[$col + $j];
                }

                $cellStyle = ['valign' => 'center'];
                if ($isBorderless) {
                    $cellStyle['borderSize']  = 0;
                    $cellStyle['borderColor'] = 'FFFFFF';
                }
                if ($colspan > 1) {
                    $cellStyle['gridSpan'] = $colspan;
                }
                if ($rowspan > 1) {
                    $cellStyle['vMerge'] = 'restart';
                }
                $bg = $this->inlineColor($cell, 'background-color');
                if ($bg !== null) {
                    $cellStyle['bgColor'] = $bg;
                }
                $isHeader = (strtolower($cell->nodeName) === 'th');

                $phpCell = $phpTable->addCell($w, $cellStyle);
                $font    = ['size' => $isBorderless ? 11 : 9];
                if ($isHeader) {
                    $font['bold'] = true;
                }
                $fg = $this->inlineColor($cell, 'color');
                if ($fg !== null) {
                    $font['color'] = $fg;
                }
                $cellAlign = 'center';
                if ($isBorderless) {
                    $cellClass = (string)($cell->getAttribute('class') ?? '');
                    $cellAlign = str_contains($cellClass, 'col-sep') ? 'center' : 'left';
                }
                $run = $phpCell->addTextRun(['alignment' => $cellAlign, 'spaceAfter' => ($isBorderless ? 60 : 0)]);
                $this->fillRuns($cell, $run, $font);
                if (trim($cell->textContent) === '') {
                    $phpCell->addText('');
                }

                for ($i = 1; $i < $rowspan; $i++) {
                    for ($j = 0; $j < $colspan; $j++) {
                        $pending[$r + $i][$col + $j] = true;
                    }
                }
                $col += $colspan;
            }

            // Sisa kolom yang dipesan merge (trailing vMerge continue)
            while ($col < $totalCols && isset($pending[$r][$col])) {
                $phpTable->addCell($widths[$col], ['vMerge' => 'continue']);
                $col++;
            }
        }
    }

    private function collectRows(DOMElement $table, array &$rows): void
    {
        foreach ($table->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                $this->collectRows($child, $rows);
            }
        }
    }

    /** @return DOMElement[] daftar td/th dalam satu baris */
    private function cellsOf(DOMElement $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && in_array(strtolower($c->nodeName), ['td', 'th'], true)) {
                $cells[] = $c;
            }
        }
        return $cells;
    }

    private function span(DOMElement $cell, string $attr): int
    {
        $v = (int)($cell->getAttribute($attr) ?: 1);
        return max(1, min(20, $v));
    }

    /** @return int[] lebar tiap kolom (twips) */
    private function columnWidths(int $totalCols, string $tableClass = ''): array
    {
        if ($totalCols === 12) {
            // Skema tabel laporan monev (native)
            return self::TABLE_LAPORAN_WIDTHS;
        }
        if (str_contains($tableClass, 'tabel-petugas') && $totalCols === 3) {
            return [3000, 400, 5955];
        }
        $w = (int)floor(self::AREA_TWIPS / $totalCols);
        return array_fill(0, $totalCols, max(200, $w));
    }

    /**
     * Ambil warna hex dari inline style ("background-color:#fee2e2" / "color:#991b1b").
     * Lookbehind mencegah "color" match di dalam "background-color".
     * @return string|null 6 digit hex tanpa '#', null jika tidak ada
     */
    private function inlineColor(DOMElement $el, string $prop): ?string
    {
        $style = strtolower((string)$el->getAttribute('style'));
        if ($style === '' || !preg_match('/(?<![a-z-])' . $prop . '\s*:\s*#?([0-9a-f]{6})/i', $style, $m)) {
            return null;
        }
        return strtoupper($m[1]);
    }

    // ── Inline runs (bold/italic/underline/br) ──────────────────

    private function fillRuns(DOMNode $node, \PhpOffice\PhpWord\Element\TextRun $run, array $font): void
    {
        foreach ($node->childNodes ?? [] as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $t = preg_replace('/\s+/', ' ', $child->textContent) ?? '';
                if (trim($t) !== '') {
                    $run->addText($t, $font);
                }
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            switch ($tag) {
                case 'br':
                    $run->addBreak();
                    break;
                case 'strong':
                case 'b':
                    $this->fillRuns($child, $run, array_merge($font, ['bold' => true]));
                    break;
                case 'em':
                case 'i':
                    $this->fillRuns($child, $run, array_merge($font, ['italic' => true]));
                    break;
                case 'u':
                    $this->fillRuns($child, $run, array_merge($font, ['underline' => 'single']));
                    break;
                case 'span':
                case 'a':
                case 'font':
                    $this->fillRuns($child, $run, $font);
                    break;
                default:
                    $this->fillRuns($child, $run, $font);
            }
        }
    }

    private function addImageFromNode(DOMElement $img): void
    {
        $src = (string)$img->getAttribute('src');
        if ($src === '') {
            return;
        }
        // Resolusi URL aplikasi → path file lokal
        $path = null;
        if (str_starts_with($src, '/')) {
            $path = realpath(rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/') . $src);
        } elseif (preg_match('#^https?://[^/]+(/.+)$#i', $src, $m)) {
            // URL absolut dengan host — ambil path-nya saja terhadap docroot
            $path = realpath(rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/') . $m[1]);
        }
        if ($path !== false && $path !== null && is_file($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp'], true)) {
                $this->section->addImage($path, ['width' => 90, 'height' => 90, 'alignment' => 'center']);
            }
        }
    }
}

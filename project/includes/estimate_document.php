<?php
require_once __DIR__ . '/contract_number.php';

// Кошторис → a simple .docx (WordprocessingML zipped with ZipArchive, no
// libraries). Generated once when the freelancer submits the estimate and
// stored in uploads/estimates/ — a snapshot the client downloads. The layout
// is deliberately basic for now (title, parties, «Роботи» and «Матеріали»
// tables, totals, note, signature lines); it'll be made prettier later.
//
// Items: [{kind: work|material, name, qty, unit, unit_price}]; items without
// kind (older estimates) count as materials.

const ESTIMATE_DOC_DIR = 'estimates';
const LEGACY_STAGED_TITLES_PHP = ['Поетапний контракт', 'Поэтапный контракт'];

function estimate_item_kind(array $it): string {
    return ($it['kind'] ?? 'material') === 'work' ? 'work' : 'material';
}

// ['work' => float, 'material' => float, 'total' => float]
function estimate_totals(array $items): array {
    $t = ['work' => 0.0, 'material' => 0.0];
    foreach ($items as $it) $t[estimate_item_kind($it)] += (float) $it['qty'] * (float) $it['unit_price'];
    $t['work'] = round($t['work'], 2);
    $t['material'] = round($t['material'], 2);
    $t['total'] = round($t['work'] + $t['material'], 2);
    return $t;
}

// ─── deposit gate ─────────────────────────────────────────────────────────────
// The client may put money into escrow only once the contract/stage is signed
// AND has an estimate covering BOTH «Роботи» and «Матеріали» that isn't
// rejected (submitted or accepted). Used by escrow.php (hard check) and
// contracts.php GET (deposit_ready flag for the UI).
function estimate_covers_both(array $items): bool {
    $kinds = array_unique(array_map('estimate_item_kind', $items));
    return in_array('work', $kinds, true) && in_array('material', $kinds, true);
}

// null = deposit allowed; otherwise the reason (Ukrainian, shown to the user).
function deposit_block_reason(PDO $db, int $contractId): ?string {
    $stmt = $db->prepare('SELECT status, items FROM contract_estimates WHERE contract_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$contractId]);
    $est = $stmt->fetch();
    if (!$est) {
        return 'Депозит можна внести після того, як фрилансер складе кошторис (роботи й матеріали).';
    }
    if ($est['status'] === 'rejected') {
        return 'Кошторис відхилено — депозит можна внести, коли фрилансер надішле новий.';
    }
    if (!estimate_covers_both(json_decode($est['items'], true) ?: [])) {
        return 'У кошторисі мають бути і роботи, і матеріали — попросіть фрилансера скласти його заново.';
    }
    return null;
}

// ─── tiny WordprocessingML helpers ───────────────────────────────────────────
function docx_text(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// One paragraph. $o: bold, size (pt), align (left|center|right), after (spacing, twips), italic, color
function docx_p(string $text, array $o = []): string {
    $ppr = '<w:spacing w:after="' . ($o['after'] ?? 120) . '"/>';
    if (!empty($o['align'])) $ppr .= '<w:jc w:val="' . $o['align'] . '"/>';
    $rpr = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>';
    if (!empty($o['bold']))   $rpr .= '<w:b/>';
    if (!empty($o['italic'])) $rpr .= '<w:i/>';
    if (!empty($o['color']))  $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
    $sz = (int) round(($o['size'] ?? 10) * 2);
    $rpr .= '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
    // keep line breaks of multi-line notes
    $runs = [];
    foreach (explode("\n", str_replace("\r", '', $text)) as $i => $line) {
        $runs[] = ($i ? '<w:r><w:rPr>' . $rpr . '</w:rPr><w:br/></w:r>' : '')
                . '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">' . docx_text($line) . '</w:t></w:r>';
    }
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . implode('', $runs) . '</w:p>';
}

function docx_cell(string $text, int $width, array $o = []): string {
    $tcpr = '<w:tcW w:w="' . $width . '" w:type="dxa"/>';
    if (!empty($o['fill'])) $tcpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fill'] . '"/>';
    if (!empty($o['span'])) $tcpr .= '<w:gridSpan w:val="' . (int) $o['span'] . '"/>';
    return '<w:tc><w:tcPr>' . $tcpr . '</w:tcPr>' . docx_p($text, ['bold' => $o['bold'] ?? false, 'align' => $o['align'] ?? 'left', 'after' => 0, 'size' => 9]) . '</w:tc>';
}

function docx_table(array $widths, array $rowsXml): string {
    $b = '<w:%s w:val="single" w:sz="4" w:space="0" w:color="808080"/>';
    $borders = '';
    foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) $borders .= sprintf($b, $side);
    $grid = implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $widths));
    return '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
         . '<w:tblBorders>' . $borders . '</w:tblBorders>'
         . '<w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr>'
         . '<w:tblGrid>' . $grid . '</w:tblGrid>' . implode('', $rowsXml) . '</w:tbl>';
}

function money_ua($n): string { return number_format((float) $n, 2, ',', ' '); }
function qty_ua($n): string {
    $s = rtrim(rtrim(number_format((float) $n, 3, ',', ' '), '0'), ',');
    return $s === '' ? '0' : $s;
}

// One section table («Роботи» / «Матеріали»).
function docx_items_table(array $items, string $emptyText): string {
    // A4 portrait, 2 cm margins → 9638 twips of usable width.
    $w = [500, 4138, 1000, 900, 1500, 1600];
    $head = '<w:tr>'
        . docx_cell('№', $w[0], ['bold' => true, 'fill' => 'E7E6E6', 'align' => 'center'])
        . docx_cell('Найменування', $w[1], ['bold' => true, 'fill' => 'E7E6E6'])
        . docx_cell('К-сть', $w[2], ['bold' => true, 'fill' => 'E7E6E6', 'align' => 'right'])
        . docx_cell('Од.', $w[3], ['bold' => true, 'fill' => 'E7E6E6'])
        . docx_cell('Ціна за од., ₴', $w[4], ['bold' => true, 'fill' => 'E7E6E6', 'align' => 'right'])
        . docx_cell('Сума, ₴', $w[5], ['bold' => true, 'fill' => 'E7E6E6', 'align' => 'right'])
        . '</w:tr>';
    $rows = [$head];
    if (!$items) {
        $rows[] = '<w:tr>' . docx_cell($emptyText, array_sum($w), ['span' => 6]) . '</w:tr>';
    }
    $sum = 0.0;
    foreach (array_values($items) as $i => $it) {
        $line = (float) $it['qty'] * (float) $it['unit_price'];
        $sum += $line;
        $rows[] = '<w:tr>'
            . docx_cell((string) ($i + 1), $w[0], ['align' => 'center'])
            . docx_cell((string) $it['name'], $w[1])
            . docx_cell(qty_ua($it['qty']), $w[2], ['align' => 'right'])
            . docx_cell((string) ($it['unit'] ?? ''), $w[3])
            . docx_cell(money_ua($it['unit_price']), $w[4], ['align' => 'right'])
            . docx_cell(money_ua($line), $w[5], ['align' => 'right'])
            . '</w:tr>';
    }
    $rows[] = '<w:tr>'
        . docx_cell('Разом', $w[0] + $w[1] + $w[2] + $w[3] + $w[4], ['bold' => true, 'span' => 5, 'align' => 'right', 'fill' => 'F2F2F2'])
        . docx_cell(money_ua($sum), $w[5], ['bold' => true, 'align' => 'right', 'fill' => 'F2F2F2'])
        . '</w:tr>';
    return docx_table($w, $rows);
}

// Builds the .docx binary. $d: number, contract_label, date, freelancer, client, items, note
function build_estimate_docx(array $d): ?string {
    if (!class_exists('ZipArchive')) return null;

    $works     = array_values(array_filter($d['items'], fn($it) => estimate_item_kind($it) === 'work'));
    $materials = array_values(array_filter($d['items'], fn($it) => estimate_item_kind($it) === 'material'));
    $tot       = estimate_totals($d['items']);

    $body  = docx_p('КОШТОРИС № ' . $d['number'], ['bold' => true, 'size' => 16, 'align' => 'center', 'after' => 60]);
    $body .= docx_p($d['contract_label'], ['align' => 'center', 'size' => 11, 'after' => 240]);
    $body .= docx_p('Дата складання: ' . $d['date'], ['after' => 40]);
    $body .= docx_p('Виконавець: ' . $d['freelancer'], ['after' => 40]);
    $body .= docx_p('Замовник: ' . $d['client'], ['after' => 240]);

    $body .= docx_p('1. Роботи', ['bold' => true, 'size' => 12, 'after' => 80]);
    $body .= docx_items_table($works, 'Роботи не зазначено');
    $body .= docx_p('', ['after' => 120]);
    $body .= docx_p('2. Матеріали', ['bold' => true, 'size' => 12, 'after' => 80]);
    $body .= docx_items_table($materials, 'Матеріали не зазначено');
    $body .= docx_p('Матеріали закуповує замовник самостійно.', ['italic' => true, 'size' => 9, 'color' => '595959', 'after' => 240]);

    $body .= docx_p('Роботи: ' . money_ua($tot['work']) . ' ₴', ['after' => 40]);
    $body .= docx_p('Матеріали: ' . money_ua($tot['material']) . ' ₴', ['after' => 40]);
    $body .= docx_p('Разом за кошторисом: ' . money_ua($tot['total']) . ' ₴', ['bold' => true, 'size' => 12, 'after' => 240]);

    if (trim((string) $d['note']) !== '') {
        $body .= docx_p('Примітка:', ['bold' => true, 'after' => 40]);
        $body .= docx_p((string) $d['note'], ['after' => 240]);
    }

    $body .= docx_p('Виконавець: ____________________ / ' . $d['freelancer'], ['after' => 200]);
    $body .= docx_p('Замовник: ______________________ / ' . $d['client'], ['after' => 360]);
    $body .= docx_p('Документ сформовано автоматично на платформі NovaBuild.', ['italic' => true, 'size' => 8, 'color' => '808080', 'after' => 0]);

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'est');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin ?: null;
}

// «Ім'я П.» — same peer masking as everywhere else on the site.
function estimate_person(PDO $db, int $userId): string {
    $stmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return '—';
    $name = trim(($u['first_name'] ?? '') . ($u['last_name'] ? ' ' . mb_substr($u['last_name'], 0, 1) . '.' : ''));
    return $name !== '' ? $name : $u['email'];
}

function estimate_contract_label(PDO $db, array $c): string {
    $name = function (array $row) use ($db): string {
        $t = $row['title'] ?? null;
        if ($t && !in_array($t, LEGACY_STAGED_TITLES_PHP, true)) return $t;
        if (!empty($row['offer_id'])) {
            $stmt = $db->prepare('SELECT title FROM offers WHERE id = ?');
            $stmt->execute([$row['offer_id']]);
            return (string) ($stmt->fetchColumn() ?: '');
        }
        return '';
    };
    if ($c['parent_contract_id']) {
        $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$c['parent_contract_id']]);
        $p = $stmt->fetch() ?: [];
        $pName = $p ? $name($p) : '';
        return 'до етапу ' . $c['seq'] . ($c['title'] ? ' «' . $c['title'] . '»' : '')
             . ' поетапного контракту #' . contract_number((int) $c['parent_contract_id'])
             . ($pName !== '' ? ' «' . $pName . '»' : '');
    }
    $cName = $name($c);
    return 'до контракту #' . contract_number((int) $c['id']) . ($cName !== '' ? ' «' . $cName . '»' : '');
}

// Generates and stores the document for one contract_estimates row; returns
// its path (relative to /project/) or null if generation isn't possible.
function generate_estimate_document(PDO $db, array $est): ?string {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
    $stmt->execute([$est['contract_id']]);
    $c = $stmt->fetch();
    if (!$c) return null;

    $items = is_array($est['items']) ? $est['items'] : (json_decode((string) $est['items'], true) ?: []);
    $created = DateTime::createFromFormat('Y-m-d H:i:s', (string) $est['created_at']) ?: new DateTime();

    $bin = build_estimate_docx([
        'number'         => $est['id'] . ' від ' . $created->format('d.m.Y'),
        'contract_label' => estimate_contract_label($db, $c),
        'date'           => $created->format('d.m.Y'),
        'freelancer'     => estimate_person($db, (int) $c['freelancer_user_id']),
        'client'         => estimate_person($db, (int) $c['client_user_id']),
        'items'          => $items,
        'note'           => (string) ($est['note'] ?? ''),
    ]);
    if ($bin === null) return null;

    $dir = __DIR__ . '/../uploads/' . ESTIMATE_DOC_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = bin2hex(random_bytes(16)) . '.docx';
    if (file_put_contents($dir . '/' . $file, $bin) === false) return null;

    $path = 'uploads/' . ESTIMATE_DOC_DIR . '/' . $file;
    $db->prepare('UPDATE contract_estimates SET document_path = ? WHERE id = ?')->execute([$path, $est['id']]);
    return $path;
}

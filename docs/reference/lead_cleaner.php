<?php
/**
 * Bytelogic Lead Cleaner (PHP)
 * ----------------------------
 * Pre-send cleaner for cold email lead lists (XLSX / CSV), run before Smartlead.
 * Single file, no Composer packages. Needs PHP 8.0+ with zip, simplexml, mbstring.
 *
 * Checks (free, no email is sent):
 *   1. Normalise emails (trim, lowercase, strip mailto:, spaces, <>, quotes)
 *   2. Syntax validation
 *   3. Duplicates
 *   4. Role / group / system addresses (info@, sales@, noreply@, abuse@ ...)
 *   5. Typo domains (gmial.com, outlok.com ...)
 *   6. Disposable / fake / example domains
 *   7. Optional: free mailbox providers (gmail, yahoo ...) for B2B
 *   8. DNS: domain does not exist, accepts no mail, no MX record
 *   9. Flags secure gateways (Mimecast, Proofpoint, Barracuda ...) behind most sender bounces
 *  10. Suppression lists (Smartlead bounced / unsubscribed / contacted exports, blocklists)
 *  11. Optional verifier merge (ListClean etc.): keep only valid statuses
 *  12. Optional cap of leads per company domain
 *
 * WEB:  put this file on a PHP server, open it in a browser, upload, download results.
 * CLI:  php lead_cleaner.php leads.xlsx [options]
 *       --drop-free                 remove gmail/yahoo/outlook etc.
 *       --keep-role                 keep info@ / sales@ (not recommended)
 *       --no-dns                    skip DNS checks
 *       --keep-a-only               keep domains with A record but no MX
 *       --suppress=a.csv,b.txt      suppression files (emails, or @domain.com / domain.com lines)
 *       --verifier=listclean.csv    verifier export with an email + status column
 *       --allow-status=catch-all    extra verifier statuses to keep (comma separated)
 *       --max-per-domain=3          cap per company domain (0 = off)
 *       --gateway-mode=separate     keep | separate | drop
 *       --email-col="Work Email"    force the email column name
 *
 * Output: <name>_cleaned.xlsx (Clean, Removed, Gateway_Leads, Summary) and <name>_smartlead.csv
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** Set a password to protect the web page. Empty string = no password (only use on localhost). */
const ACCESS_PASSWORD = '';
const MAX_UPLOAD_MB    = 25;
const RESULT_TTL_SEC   = 3600; // web results auto-deleted after 1 hour

// ---------------------------------------------------------------------------
// Reference lists (edit freely)
// ---------------------------------------------------------------------------

const ROLE_LOCALPARTS = [
    'info','contact','contactus','contact-us','hello','hi','admin','administrator','office','mail',
    'email','enquiry','enquiries','inquiry','inquiries','sales','support','help','helpdesk','service',
    'services','customerservice','customercare','care','team','hr','jobs','careers','career',
    'recruitment','hiring','accounts','accounting','billing','finance','invoice','invoices','marketing',
    'media','press','pr','news','newsletter','webmaster','web','website','postmaster','hostmaster',
    'abuse','spam','security','privacy','legal','compliance','noc','it','noreply','no-reply',
    'donotreply','do-not-reply','mailer-daemon','root','system','notifications','alerts','reception',
    'frontdesk','booking','bookings','reservations','orders','order','shop','store','feedback',
    'general','all','staff','everyone','management','operations','ops','purchase','purchasing',
    'procurement','tender','tenders','test','testing','demo','example','user','username','null',
    'none','na','nobody',
];

const FREE_PROVIDERS = [
    'gmail.com','googlemail.com','yahoo.com','yahoo.co.in','yahoo.co.uk','ymail.com','rocketmail.com',
    'hotmail.com','hotmail.co.uk','outlook.com','live.com','msn.com','aol.com','icloud.com','me.com',
    'mac.com','gmx.com','gmx.de','mail.com','zoho.com','zohomail.com','protonmail.com','proton.me',
    'yandex.com','yandex.ru','rediffmail.com','inbox.com','qq.com','163.com','126.com','web.de',
    'tutanota.com',
];

const KNOWN_TYPOS = [
    'gmial.com','gmal.com','gmaill.com','gmali.com','gamil.com','gnail.com','gmail.co','gmail.cm',
    'gmail.con','gmai.com','gmil.com','gmaii.com','hotmal.com','hotmial.com','hotmai.com','hotmail.co',
    'hotmail.con','hotamil.com','outlok.com','outloo.com','outlook.co','outlook.con','yahooo.com',
    'yaho.com','yahoo.co','yahoo.con','yhoo.com','iclod.com','icloud.co','aol.co',
];

const DISPOSABLE_OR_FAKE = [
    'mailinator.com','guerrillamail.com','10minutemail.com','tempmail.com','temp-mail.org','yopmail.com',
    'trashmail.com','sharklasers.com','getnada.com','dispostable.com','maildrop.cc','fakeinbox.com',
    'throwawaymail.com','mintemail.com','mohmal.com','emailondeck.com','example.com','example.org',
    'example.net','test.com','domain.com','email.com','yourdomain.com','company.com','abc.com','xyz.com',
    'sample.com','invalid.com','localhost',
];

/** MX host fragment => provider name. Order matters (first match wins). */
const MX_PROVIDERS = [
    'mimecast' => 'Mimecast', 'pphosted' => 'Proofpoint', 'ppe-hosted' => 'Proofpoint',
    'proofpoint' => 'Proofpoint', 'barracudanetworks' => 'Barracuda', 'barracuda' => 'Barracuda',
    'iphmx' => 'Cisco IronPort', 'messagelabs' => 'Symantec/Broadcom', 'trendmicro' => 'Trend Micro',
    'sophos' => 'Sophos', 'forcepoint' => 'Forcepoint', 'fortimail' => 'FortiMail',
    'spamtitan' => 'SpamTitan', 'mailcontrol' => 'Forcepoint', 'trustwave' => 'Trustwave',
    'hornetsecurity' => 'Hornetsecurity', 'antispamcloud' => 'SpamExperts',
    'protection.outlook.com' => 'Microsoft 365', 'google.com' => 'Google Workspace',
    'googlemail.com' => 'Google Workspace', 'zoho' => 'Zoho',
];

const SECURE_GATEWAYS = [
    'Mimecast','Proofpoint','Barracuda','Cisco IronPort','Symantec/Broadcom','Trend Micro','Sophos',
    'Forcepoint','FortiMail','SpamTitan','Trustwave','Hornetsecurity','SpamExperts',
];

const VALID_VERIFIER_STATUSES = ['valid','deliverable','ok','safe','verified','good'];

const EMAIL_REGEX = '/^(?=.{6,254}$)(?=.{1,64}@)[a-z0-9](?:[a-z0-9._%+\'-]*[a-z0-9])?@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/';

// ---------------------------------------------------------------------------
// File reading
// ---------------------------------------------------------------------------

/** Returns [headers[], rows[][]] where each row is an assoc array keyed by header. */
function readTable(string $path, string $originalName = ''): array
{
    $ext = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'xlsm'], true)) {
        $grid = readXlsx($path);
    } elseif (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
        $grid = readCsv($path);
    } else {
        throw new RuntimeException("Unsupported file type '.$ext'. Use .xlsx or .csv (save .xls as .xlsx first).");
    }
    // drop fully empty leading rows
    while ($grid && count(array_filter($grid[0], fn($v) => trim((string)$v) !== '')) === 0) {
        array_shift($grid);
    }
    if (!$grid) {
        return [[], []];
    }
    $headers = [];
    foreach (array_shift($grid) as $i => $h) {
        $h = trim((string)$h);
        $h = $h === '' ? 'Column ' . ($i + 1) : $h;
        while (in_array($h, $headers, true)) {
            $h .= '_';
        }
        $headers[] = $h;
    }
    $rows = [];
    foreach ($grid as $r) {
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = isset($r[$i]) ? (string)$r[$i] : '';
        }
        $rows[] = $row;
    }
    return [$headers, $rows];
}

function readCsv(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read CSV.');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($raw, "\n") ?: '';
    $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($delims);
    $delim = array_key_first($delims);
    if ($delims[$delim] === 0) {
        $delim = ',';
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $grid = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $grid[] = $r;
    }
    fclose($fh);
    return $grid;
}

function readXlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP zip extension is missing. Enable it, or upload a CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Cannot open XLSX (file may be corrupt or an old .xls).');
    }
    // first sheet path
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $wbx = simplexml_load_string($wb);
        $relx = simplexml_load_string($rels);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $wbx->xpath('//m:sheets/m:sheet');
        if ($sheets) {
            $rid = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($relx->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $t = ltrim((string)$rel['Target'], '/');
                    $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                }
            }
        }
    }
    // shared strings
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $ssx = simplexml_load_string($ss);
        foreach ($ssx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $txt = '';
                foreach ($si->r as $run) {
                    $txt .= (string)$run->t;
                }
                $shared[] = $txt;
            }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('First worksheet not found in XLSX.');
    }
    $sx = simplexml_load_string($xml);
    $grid = [];
    foreach ($sx->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = $ref !== '' ? colIndex(preg_replace('/\d+/', '', $ref)) : count($r);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($c->is->t ?? '');
                if ($val === '' && isset($c->is->r)) {
                    foreach ($c->is->r as $run) {
                        $val .= (string)$run->t;
                    }
                }
            } else {
                $val = (string)$c->v;
            }
            $r[$col] = $val;
        }
        if ($r) {
            $max = max(array_keys($r));
            $filled = [];
            for ($i = 0; $i <= $max; $i++) {
                $filled[$i] = $r[$i] ?? '';
            }
            $grid[] = $filled;
        } else {
            $grid[] = [];
        }
    }
    return $grid;
}

function colIndex(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function colLetter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - $m - 1, 26);
    }
    return $s;
}

// ---------------------------------------------------------------------------
// File writing
// ---------------------------------------------------------------------------

function writeCsv(string $path, array $headers, array $rows): void
{
    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = $r[$h] ?? '';
        }
        fputcsv($fh, $line, ',', '"', '\\');
    }
    fclose($fh);
}

/** Minimal multi-sheet XLSX writer. $sheets = ['Name' => [headers[], rows[]]] */
function writeXlsx(string $path, array $sheets): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create XLSX output.');
    }
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $names = array_keys($sheets);

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach ($names as $i => $_) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($names as $i => $n) {
        $id = $i + 1;
        $wb .= '<sheet name="' . $esc(mb_substr($n, 0, 31)) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
    }
    $sid = count($names) + 1;
    $rels .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wb .= '</sheets></workbook>';
    $rels .= '</Relationships>';
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

    // style 1 = bold header
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

    foreach ($names as $i => $n) {
        [$headers, $rows] = $sheets[$n];
        $widths = [];
        foreach ($headers as $c => $h) {
            $widths[$c] = mb_strlen((string)$h);
        }
        $data = '<row r="1">';
        foreach ($headers as $c => $h) {
            $data .= '<c r="' . colLetter($c) . '1" t="inlineStr" s="1"><is><t>' . $esc($h) . '</t></is></c>';
        }
        $data .= '</row>';
        foreach ($rows as $ri => $r) {
            $rn = $ri + 2;
            $data .= '<row r="' . $rn . '">';
            foreach ($headers as $c => $h) {
                $v = (string)($r[$h] ?? '');
                if ($ri < 300) {
                    $widths[$c] = max($widths[$c], mb_strlen($v));
                }
                if ($v === '') {
                    continue;
                }
                $data .= '<c r="' . colLetter($c) . $rn . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            }
            $data .= '</row>';
        }
        $cols = '<cols>';
        foreach ($widths as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . min(max(10, $w + 2), 60) . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($headers ? $cols : '') . '<sheetData>' . $data . '</sheetData></worksheet>');
    }
    $zip->close();
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function findEmailColumn(array $headers, array $rows, ?string $preferred = null): string
{
    if ($preferred) {
        if (in_array($preferred, $headers, true)) {
            return $preferred;
        }
        throw new RuntimeException("Column '$preferred' not found. Columns: " . implode(', ', $headers));
    }
    $exact = ['email', 'e-mail', 'email address', 'work email', 'email_address'];
    $cands = array_values(array_filter($headers, fn($h) => str_contains(strtolower($h), 'email') || str_contains(strtolower($h), 'e-mail')));
    foreach ($cands as $c) {
        if (in_array(strtolower(trim($c)), $exact, true)) {
            return $c;
        }
    }
    if ($cands) {
        return $cands[0];
    }
    $best = null;
    $bestN = 0;
    foreach ($headers as $h) {
        $n = 0;
        foreach ($rows as $r) {
            if (str_contains((string)$r[$h], '@')) {
                $n++;
            }
        }
        if ($n > $bestN) {
            $best = $h;
            $bestN = $n;
        }
    }
    if ($best === null) {
        throw new RuntimeException('Could not find an email column.');
    }
    return $best;
}

function normaliseEmail($raw): string
{
    $s = strtolower(trim((string)$raw));
    if (in_array($s, ['nan', 'none', 'null'], true)) {
        return '';
    }
    $s = str_replace('mailto:', '', $s);
    $s = preg_replace('/[\s<>"\'(),;]/u', '', $s);
    return trim($s, '.');
}

function baseLocalpart(string $local): string
{
    $local = explode('+', $local, 2)[0];
    return preg_split('/[._-]/', $local, 2)[0];
}

function typoSuggestion(string $domain): ?string
{
    $targets = FREE_PROVIDERS;
    $closest = function (string $d) use ($targets): array {
        $best = null;
        $bestD = PHP_INT_MAX;
        foreach ($targets as $t) {
            $lv = levenshtein($d, $t);
            if ($lv < $bestD) {
                $bestD = $lv;
                $best = $t;
            }
        }
        return [$best, $bestD];
    };
    if (in_array($domain, KNOWN_TYPOS, true)) {
        return $closest($domain)[0] ?? '?';
    }
    if (in_array($domain, FREE_PROVIDERS, true)) {
        return null;
    }
    $name = explode('.', $domain)[0];
    if (strlen($name) <= 10 && preg_match('/gm|hotm|outl|yah|iclo/', $name)) {
        [$best, $dist] = $closest($domain);
        if ($best !== null && $dist >= 1 && $dist <= 2) {
            return $best;
        }
    }
    return null;
}

function classifyMx(array $hosts): string
{
    $joined = strtolower(implode(' ', $hosts));
    foreach (MX_PROVIDERS as $frag => $name) {
        if (str_contains($joined, $frag)) {
            return $name;
        }
    }
    return $hosts ? 'Other' : '';
}

/**
 * Returns [status, mxHosts]. status: ok | null_mx | no_mx_a_only | no_mail | nxdomain
 * For testing you can set env LEAD_CLEANER_DNS_MOCK to a JSON file {"domain": ["status", ["mx"]]}.
 */
function dnsCheck(string $domain): array
{
    static $mock = null;
    if ($mock === null) {
        $f = getenv('LEAD_CLEANER_DNS_MOCK');
        $mock = $f && is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    if ($mock) {
        return $mock[$domain] ?? ['ok', ['mx.' . $domain]];
    }
    $mx = @dns_get_record($domain . '.', DNS_MX);
    if (is_array($mx) && $mx) {
        usort($mx, fn($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $hosts = array_map(fn($r) => strtolower(rtrim((string)($r['target'] ?? ''), '.')), $mx);
        $hosts = array_values(array_filter($hosts, fn($h) => $h !== '' && $h !== '.'));
        return $hosts ? ['ok', $hosts] : ['null_mx', []];
    }
    $a = @dns_get_record($domain . '.', DNS_A);
    if (is_array($a) && $a) {
        return ['no_mx_a_only', []];
    }
    $ns = @dns_get_record($domain . '.', DNS_NS);
    $soa = @dns_get_record($domain . '.', DNS_SOA);
    if ((is_array($ns) && $ns) || (is_array($soa) && $soa)) {
        return ['no_mail', []];
    }
    return ['nxdomain', []];
}

function loadSuppression(array $files): array
{
    $emails = [];
    $domains = [];
    foreach ($files as [$path, $name]) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'txt') {
            $values = preg_split('/\R/', (string)file_get_contents($path));
        } else {
            [$h, $rows] = readTable($path, $name);
            if (!$h) {
                continue;
            }
            try {
                $col = findEmailColumn($h, $rows);
            } catch (RuntimeException $e) {
                $col = $h[0];
            }
            $values = array_merge([$col], array_column($rows, $col)); // header may itself be a value
        }
        foreach ($values as $v) {
            $v = normaliseEmail($v);
            if ($v === '') {
                continue;
            }
            if (str_contains($v, '@')) {
                if (str_starts_with($v, '@')) {
                    $domains[substr($v, 1)] = true;
                } else {
                    $emails[$v] = true;
                }
            } elseif (str_contains($v, '.')) {
                $domains[$v] = true;
            }
        }
    }
    return [$emails, $domains];
}

function loadVerifier(string $path, string $name): array
{
    [$h, $rows] = readTable($path, $name);
    $ecol = findEmailColumn($h, $rows);
    $scol = null;
    foreach ($h as $col) {
        foreach (['status', 'result', 'verdict', 'state'] as $k) {
            if (str_contains(strtolower($col), $k)) {
                $scol = $col;
                break 2;
            }
        }
    }
    if ($scol === null) {
        throw new RuntimeException('No status/result column found in verifier file. Columns: ' . implode(', ', $h));
    }
    $map = [];
    foreach ($rows as $r) {
        $e = normaliseEmail($r[$ecol]);
        if ($e !== '') {
            $map[$e] = strtolower(trim((string)$r[$scol]));
        }
    }
    return $map;
}

// ---------------------------------------------------------------------------
// Core
// ---------------------------------------------------------------------------

/**
 * @param array $input  [path, originalName]
 * @param array $opts   see defaults below
 * @param callable|null $log function(string $msg)
 */
function cleanLeads(array $input, array $opts, ?callable $log = null): array
{
    $log ??= fn($m) => null;
    $o = array_merge([
        'email_col' => null, 'drop_free' => false, 'keep_role' => false, 'no_dns' => false,
        'keep_a_only' => false, 'suppress' => [], 'verifier' => null, 'allow_status' => [],
        'max_per_domain' => 0, 'gateway_mode' => 'keep',
    ], $opts);

    [$headers, $rows] = readTable($input[0], $input[1]);
    if (!$rows) {
        throw new RuntimeException('The file has no data rows.');
    }
    $ecol = findEmailColumn($headers, $rows, $o['email_col']);
    $total = count($rows);
    $log("$total rows, email column: '$ecol'");

    $roleSet = array_flip(ROLE_LOCALPARTS);
    $freeSet = array_flip(FREE_PROVIDERS);
    $fakeSet = array_flip(DISPOSABLE_OR_FAKE);
    $gwSet = array_flip(SECURE_GATEWAYS);

    $counts = [];
    $bump = function (string $k) use (&$counts) {
        $counts[$k] = ($counts[$k] ?? 0) + 1;
    };

    $seen = [];
    $typoCache = [];
    foreach ($rows as $i => &$r) {
        $e = normaliseEmail($r[$ecol]);
        $d = str_contains($e, '@') ? substr($e, strrpos($e, '@') + 1) : '';
        $r['_email'] = $e;
        $r['_domain'] = $d;
        $r['_reason'] = '';
        $r['_note'] = '';
        $r['mx_provider'] = '';
        $r['dns_status'] = '';

        $local = $e !== '' && str_contains($e, '@') ? substr($e, 0, strrpos($e, '@')) : '';
        if ($e === '') {
            $r['_reason'] = 'Empty email';
        } elseif (!preg_match(EMAIL_REGEX, $e)) {
            $r['_reason'] = 'Invalid syntax';
        } elseif (isset($seen[$e])) {
            $r['_reason'] = 'Duplicate';
        } elseif (!$o['keep_role'] && (isset($roleSet[$local]) || isset($roleSet[baseLocalpart($local)]))) {
            $r['_reason'] = 'Role / group address';
        } elseif (($typoCache[$d] ??= (typoSuggestion($d) ?? '')) !== '') {
            $r['_reason'] = 'Typo domain';
            $r['_note'] = 'did you mean ' . $typoCache[$d] . '?';
        } elseif (isset($fakeSet[$d])) {
            $r['_reason'] = 'Disposable / fake domain';
        } elseif ($o['drop_free'] && isset($freeSet[$d])) {
            $r['_reason'] = 'Free mailbox (B2B)';
        }
        if ($e !== '' && !isset($seen[$e])) {
            $seen[$e] = true;
        }
        if ($r['_reason'] !== '') {
            $bump($r['_reason']);
        }
    }
    unset($r);

    // Suppression
    if ($o['suppress']) {
        [$sE, $sD] = loadSuppression($o['suppress']);
        $log(count($sE) . ' emails and ' . count($sD) . ' domains in suppression lists');
        foreach ($rows as &$r) {
            if ($r['_reason'] !== '') {
                continue;
            }
            if (isset($sE[$r['_email']])) {
                $r['_reason'] = 'Suppressed email';
            } elseif (isset($sD[$r['_domain']])) {
                $r['_reason'] = 'Suppressed domain';
            }
            if ($r['_reason'] !== '') {
                $bump($r['_reason']);
            }
        }
        unset($r);
    }

    // DNS
    $dnsSkipped = false;
    if (!$o['no_dns']) {
        $canary = false;
        foreach (['gmail.com', 'microsoft.com', 'google.com'] as $c) {
            if (dnsCheck($c)[0] === 'ok') {
                $canary = true;
                break;
            }
        }
        if (!$canary) {
            $dnsSkipped = true;
            $log('WARNING: DNS is not working on this server (could not resolve gmail.com / microsoft.com). DNS checks skipped so good leads are not removed.');
        } else {
            $domains = [];
            foreach ($rows as $r) {
                if ($r['_reason'] === '' && $r['_domain'] !== '') {
                    $domains[$r['_domain']] = true;
                }
            }
            $domains = array_keys($domains);
            $log('Checking MX records for ' . count($domains) . ' unique domains (this can take a few minutes)...');
            $res = [];
            $failed = 0;
            foreach ($domains as $n => $d) {
                $res[$d] = dnsCheck($d);
                if ($res[$d][0] === 'nxdomain') {
                    $failed++;
                }
                if (($n + 1) % 100 === 0) {
                    $log('  ' . ($n + 1) . '/' . count($domains));
                }
            }
            if ($domains && $failed / count($domains) > 0.5) {
                $log("WARNING: $failed/" . count($domains) . " domains failed DNS. If the list is recent, server DNS may be unreliable. Review the Removed sheet before deleting anything.");
            }
            foreach ($rows as &$r) {
                if (!isset($res[$r['_domain']])) {
                    continue;
                }
                [$st, $hosts] = $res[$r['_domain']];
                $r['dns_status'] = $st;
                $r['mx_provider'] = classifyMx($hosts);
                if ($r['_reason'] !== '') {
                    continue;
                }
                if ($st === 'nxdomain') {
                    $r['_reason'] = 'Domain does not exist';
                } elseif (in_array($st, ['null_mx', 'no_mail'], true)) {
                    $r['_reason'] = 'Domain accepts no mail';
                } elseif ($st === 'no_mx_a_only' && !$o['keep_a_only']) {
                    $r['_reason'] = 'No MX record (A only)';
                }
                if ($r['_reason'] !== '') {
                    $bump($r['_reason']);
                }
            }
            unset($r);
        }
    }

    // Verifier
    $hasVerifier = false;
    if ($o['verifier']) {
        $hasVerifier = true;
        $vmap = loadVerifier($o['verifier'][0], $o['verifier'][1]);
        $allowed = array_flip(array_merge(VALID_VERIFIER_STATUSES, array_map('strtolower', $o['allow_status'])));
        foreach ($rows as &$r) {
            $r['verifier_status'] = $vmap[$r['_email']] ?? 'not verified';
            if ($r['_reason'] === '' && !isset($allowed[$r['verifier_status']])) {
                $r['_reason'] = 'Verifier: ' . $r['verifier_status'];
                $bump('Verifier: not valid');
            }
        }
        unset($r);
    }

    // Cap per company domain (free providers not capped)
    if ($o['max_per_domain'] > 0) {
        $perDomain = [];
        $key = 'Over ' . $o['max_per_domain'] . ' per company domain';
        foreach ($rows as &$r) {
            if ($r['_reason'] !== '' || isset($freeSet[$r['_domain']])) {
                continue;
            }
            $perDomain[$r['_domain']] = ($perDomain[$r['_domain']] ?? 0) + 1;
            if ($perDomain[$r['_domain']] > $o['max_per_domain']) {
                $r['_reason'] = $key;
                $bump($key);
            }
        }
        unset($r);
    }

    // Gateways
    if ($o['gateway_mode'] === 'drop') {
        foreach ($rows as &$r) {
            if ($r['_reason'] === '' && isset($gwSet[$r['mx_provider']])) {
                $r['_reason'] = 'Secure email gateway';
                $bump('Secure email gateway');
            }
        }
        unset($r);
    }

    // Split
    $extra = ['mx_provider', 'dns_status'];
    if ($hasVerifier) {
        $extra[] = 'verifier_status';
    }
    $outHeaders = array_merge($headers, $extra);
    $clean = $gateway = $removed = [];
    $byProvider = [];
    foreach ($rows as $r) {
        $out = $r;
        $out[$ecol] = $r['_email'] !== '' ? $r['_email'] : $r[$ecol];
        if ($r['_reason'] !== '') {
            $out['removal_reason'] = $r['_reason'];
            $out['note'] = $r['_note'];
            $removed[] = $out;
            continue;
        }
        $prov = $r['mx_provider'] !== '' ? $r['mx_provider'] : 'Unknown';
        $byProvider[$prov] = ($byProvider[$prov] ?? 0) + 1;
        if ($o['gateway_mode'] === 'separate' && isset($gwSet[$r['mx_provider']])) {
            $gateway[] = $out;
        } else {
            $clean[] = $out;
            if (isset($gwSet[$r['mx_provider']])) {
                $gateway[] = $out; // listed for reference, still in Clean
            }
        }
    }
    arsort($byProvider);

    $cleanTotal = $total - count($removed);
    $gwTotal = count(array_filter($rows, fn($r) => $r['_reason'] === '' && isset($gwSet[$r['mx_provider']])));
    $summary = [['Metric' => 'Total rows in file', 'Value' => $total]];
    foreach ($counts as $k => $v) {
        $summary[] = ['Metric' => "Removed: $k", 'Value' => $v];
    }
    $summary[] = ['Metric' => 'Total removed', 'Value' => count($removed)];
    $summary[] = ['Metric' => 'Clean leads', 'Value' => $cleanTotal];
    $summary[] = ['Metric' => '  of which behind secure gateways', 'Value' => $gwTotal];
    $summary[] = ['Metric' => 'Leads in Smartlead CSV', 'Value' => count($clean)];
    $summary[] = ['Metric' => 'Clean %', 'Value' => sprintf('%.1f%%', $total ? $cleanTotal / $total * 100 : 0)];
    if ($dnsSkipped) {
        $summary[] = ['Metric' => 'NOTE', 'Value' => 'DNS checks skipped: DNS not working on this server'];
    }
    if ($byProvider && !$o['no_dns'] && !$dnsSkipped) {
        $summary[] = ['Metric' => '', 'Value' => ''];
        $summary[] = ['Metric' => 'Clean leads by mail provider', 'Value' => ''];
        foreach ($byProvider as $p => $n) {
            $summary[] = ['Metric' => "  $p", 'Value' => $n];
        }
    }

    return [
        'email_col' => $ecol,
        'headers' => $outHeaders,
        'orig_headers' => $headers,
        'clean' => $clean,
        'gateway' => $gateway,
        'removed' => $removed,
        'summary' => $summary,
        'gateway_count' => $gwTotal,
    ];
}

function writeOutputs(array $res, string $xlsxPath, string $csvPath, string $gatewayMode): void
{
    $sheets = [
        'Clean' => [$res['headers'], $res['clean']],
        'Removed' => [array_merge($res['headers'], ['removal_reason', 'note']), $res['removed']],
    ];
    if ($gatewayMode === 'separate' || $res['gateway']) {
        $sheets['Gateway_Leads'] = [$res['headers'], $res['gateway']];
    }
    $sheets['Summary'] = [['Metric', 'Value'], $res['summary']];
    writeXlsx($xlsxPath, $sheets);
    writeCsv($csvPath, $res['orig_headers'], $res['clean']);
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli') {
    $args = array_slice($argv, 1);
    $file = null;
    $opts = [];
    foreach ($args as $a) {
        if (!str_starts_with($a, '--')) {
            $file = $a;
            continue;
        }
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, null);
        switch ($k) {
            case 'drop-free': $opts['drop_free'] = true; break;
            case 'keep-role': $opts['keep_role'] = true; break;
            case 'no-dns': $opts['no_dns'] = true; break;
            case 'keep-a-only': $opts['keep_a_only'] = true; break;
            case 'email-col': $opts['email_col'] = $v; break;
            case 'max-per-domain': $opts['max_per_domain'] = (int)$v; break;
            case 'gateway-mode': $opts['gateway_mode'] = in_array($v, ['keep', 'separate', 'drop'], true) ? $v : 'keep'; break;
            case 'allow-status': $opts['allow_status'] = array_filter(array_map('trim', explode(',', (string)$v))); break;
            case 'verifier': $opts['verifier'] = [$v, $v]; break;
            case 'suppress':
                foreach (array_filter(array_map('trim', explode(',', (string)$v))) as $s) {
                    if (is_file($s)) {
                        $opts['suppress'][] = [$s, $s];
                    } else {
                        fwrite(STDERR, "  ! suppression file not found, skipped: $s\n");
                    }
                }
                break;
            default: fwrite(STDERR, "Unknown option --$k\n"); exit(1);
        }
    }
    if (!$file || !is_file($file)) {
        fwrite(STDERR, "Usage: php lead_cleaner.php leads.xlsx [--drop-free] [--suppress=a.csv,b.txt] [--verifier=x.csv] [--max-per-domain=3] [--gateway-mode=separate]\n");
        exit(1);
    }
    set_time_limit(0);
    $t0 = microtime(true);
    try {
        $res = cleanLeads([$file, $file], $opts, fn($m) => print("  $m\n"));
        $base = preg_replace('/\.[^.]+$/', '', $file);
        $xlsx = $base . '_cleaned.xlsx';
        $csv = $base . '_smartlead.csv';
        writeOutputs($res, $xlsx, $csv, $opts['gateway_mode'] ?? 'keep');
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo "\n" . str_repeat('=', 52) . "\n";
    foreach ($res['summary'] as $s) {
        if ($s['Metric'] !== '') {
            printf("  %-40s %s\n", $s['Metric'], $s['Value']);
        }
    }
    echo str_repeat('=', 52) . "\n";
    echo "  Excel report : $xlsx\n  Smartlead CSV: $csv\n";
    printf("  Done in %.1fs\n\n", microtime(true) - $t0);
    exit(0);
}

// ---------------------------------------------------------------------------
// WEB
// ---------------------------------------------------------------------------

session_start();
$workRoot = sys_get_temp_dir() . '/lead_cleaner';
@mkdir($workRoot, 0700, true);

// purge old results
foreach (glob($workRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (filemtime($dir) < time() - RESULT_TTL_SEC) {
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$authed = ACCESS_PASSWORD === '' || ($_SESSION['lc_auth'] ?? false) === true;

if (ACCESS_PASSWORD !== '' && isset($_POST['password'])) {
    if (hash_equals(ACCESS_PASSWORD, (string)$_POST['password'])) {
        $_SESSION['lc_auth'] = true;
        $authed = true;
    }
}

// Downloads
if ($authed && isset($_GET['download'], $_GET['file'])) {
    $token = (string)$_GET['download'];
    $fileKey = (string)$_GET['file'];
    $files = ['xlsx' => 'cleaned.xlsx', 'csv' => 'smartlead.csv'];
    if (preg_match('/^[a-f0-9]{32}$/', $token) && isset($files[$fileKey]) && in_array($token, $_SESSION['lc_tokens'] ?? [], true)) {
        $path = "$workRoot/$token/" . $files[$fileKey];
        if (is_file($path)) {
            $base = $_SESSION['lc_names'][$token] ?? 'leads';
            $dlName = $fileKey === 'xlsx' ? "{$base}_cleaned.xlsx" : "{$base}_smartlead.csv";
            header('Content-Type: ' . ($fileKey === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8'));
            header('Content-Disposition: attachment; filename="' . $dlName . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    exit('File expired or not found. Please run the cleaner again.');
}

$result = null;
$error = null;
$logs = [];
$token = null;

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['leads'])) {
    set_time_limit(0);
    try {
        $f = $_FILES['leads'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (error code ' . $f['error'] . '). Check upload_max_filesize in php.ini.');
        }
        if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
            throw new RuntimeException('File too large. Max ' . MAX_UPLOAD_MB . ' MB.');
        }
        $allowedExt = ['xlsx', 'xlsm', 'csv', 'txt', 'tsv'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Upload a .xlsx or .csv file.');
        }

        $opts = [
            'drop_free' => !empty($_POST['drop_free']),
            'keep_role' => !empty($_POST['keep_role']),
            'no_dns' => !empty($_POST['no_dns']),
            'keep_a_only' => !empty($_POST['keep_a_only']),
            'max_per_domain' => max(0, (int)($_POST['max_per_domain'] ?? 0)),
            'gateway_mode' => in_array($_POST['gateway_mode'] ?? '', ['keep', 'separate', 'drop'], true) ? $_POST['gateway_mode'] : 'keep',
            'email_col' => trim((string)($_POST['email_col'] ?? '')) ?: null,
            'allow_status' => array_filter(array_map('trim', explode(',', (string)($_POST['allow_status'] ?? '')))),
            'suppress' => [],
        ];
        if (!empty($_FILES['suppress']['name'][0])) {
            foreach ($_FILES['suppress']['name'] as $i => $name) {
                if ($_FILES['suppress']['error'][$i] === UPLOAD_ERR_OK && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $allowedExt, true)) {
                    $opts['suppress'][] = [$_FILES['suppress']['tmp_name'][$i], $name];
                }
            }
        }
        if (!empty($_FILES['verifier']['name']) && $_FILES['verifier']['error'] === UPLOAD_ERR_OK) {
            $opts['verifier'] = [$_FILES['verifier']['tmp_name'], $_FILES['verifier']['name']];
        }

        $result = cleanLeads([$f['tmp_name'], $f['name']], $opts, function ($m) use (&$logs) { $logs[] = $m; });

        $token = bin2hex(random_bytes(16));
        $dir = "$workRoot/$token";
        mkdir($dir, 0700);
        writeOutputs($result, "$dir/cleaned.xlsx", "$dir/smartlead.csv", $opts['gateway_mode']);
        $_SESSION['lc_tokens'][] = $token;
        $_SESSION['lc_names'][$token] = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lead Cleaner | Bytelogic</title>
<style>
  :root { --ink:#1b1f2a; --muted:#5b6475; --line:#e3e6ec; --bg:#f6f7f9; --accent:#4b3fe0; --ok:#0f8a5f; --bad:#c2372f; --warn:#a86200; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:var(--bg); }
  main { max-width: 860px; margin: 40px auto; padding: 0 20px; }
  h1 { font-size: 24px; margin: 0 0 4px; }
  p.sub { color: var(--muted); margin: 0 0 24px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:22px; margin-bottom:18px; }
  label { display:block; font-weight:600; margin: 14px 0 6px; }
  .hint { color:var(--muted); font-weight:400; font-size:13px; }
  input[type=file], input[type=text], input[type=number], input[type=password], select { width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:8px; font:inherit; background:#fff; }
  .row { display:grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .checks label { font-weight:400; display:flex; gap:8px; align-items:flex-start; margin:8px 0; }
  button { margin-top:18px; background:var(--accent); color:#fff; border:0; padding:11px 20px; border-radius:8px; font:600 15px system-ui; cursor:pointer; }
  button:disabled { opacity:.6; cursor:wait; }
  table { width:100%; border-collapse:collapse; }
  td { padding:7px 4px; border-bottom:1px solid var(--line); }
  td.v { text-align:right; font-variant-numeric: tabular-nums; font-weight:600; }
  .err { background:#fdecea; border-color:#f5c2bd; color:var(--bad); }
  .warn { color: var(--warn); }
  .dl a { display:inline-block; margin:6px 10px 0 0; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:600; border:1px solid var(--accent); color:var(--accent); }
  .dl a.primary { background:var(--accent); color:#fff; }
  .big { display:flex; gap:14px; margin-bottom:12px; }
  .big div { flex:1; border:1px solid var(--line); border-radius:10px; padding:12px; }
  .big b { display:block; font-size:24px; }
  .big .ok b { color:var(--ok); } .big .bad b { color:var(--bad); }
  @media (max-width:640px){ .row{grid-template-columns:1fr} .big{flex-direction:column} }
</style>
</head>
<body>
<main>
  <h1>Lead Cleaner</h1>
  <p class="sub">Clean a lead list before uploading it to Smartlead. Nothing is emailed; all checks are free.</p>

<?php if (!$authed): ?>
  <form class="card" method="post">
    <label>Password</label>
    <input type="password" name="password" autofocus>
    <button>Enter</button>
  </form>
<?php else: ?>

  <?php if ($error): ?>
    <div class="card err"><strong>Error:</strong> <?= $h($error) ?></div>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="card">
      <?php
        $sumMap = [];
        foreach ($result['summary'] as $s) { $sumMap[$s['Metric']] = $s['Value']; }
      ?>
      <div class="big">
        <div><span class="hint">Total rows</span><b><?= $h($sumMap['Total rows in file'] ?? 0) ?></b></div>
        <div class="bad"><span class="hint">Removed</span><b><?= $h($sumMap['Total removed'] ?? 0) ?></b></div>
        <div class="ok"><span class="hint">Ready for Smartlead</span><b><?= $h($sumMap['Leads in Smartlead CSV'] ?? 0) ?></b></div>
      </div>
      <?php foreach ($logs as $l): if (str_starts_with($l, 'WARNING')): ?>
        <p class="warn"><?= $h($l) ?></p>
      <?php endif; endforeach; ?>
      <table>
        <?php foreach ($result['summary'] as $s): if ($s['Metric'] === '') continue; ?>
          <tr><td><?= $h($s['Metric']) ?></td><td class="v"><?= $h($s['Value']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <div class="dl">
        <a class="primary" href="?download=<?= $h($token) ?>&file=csv">Download Smartlead CSV</a>
        <a href="?download=<?= $h($token) ?>&file=xlsx">Download full report (.xlsx)</a>
      </div>
      <p class="hint">Files are deleted from the server after 1 hour.</p>
    </div>
  <?php endif; ?>

  <form class="card" method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Cleaning... (DNS checks can take a few minutes)';">
    <label>Lead file <span class="hint">.xlsx or .csv, first sheet is used</span></label>
    <input type="file" name="leads" accept=".xlsx,.xlsm,.csv,.txt" required>

    <label>Suppression files <span class="hint">optional, multiple: Smartlead bounced / unsubscribed / contacted exports, or a .txt with emails and @domain.com lines</span></label>
    <input type="file" name="suppress[]" accept=".xlsx,.csv,.txt" multiple>

    <label>Verifier export <span class="hint">optional: ListClean / ZeroBounce file with email + status columns. Only "valid" rows are kept</span></label>
    <input type="file" name="verifier" accept=".xlsx,.csv">

    <div class="row">
      <div>
        <label>Max leads per company domain <span class="hint">0 = no limit, 3 recommended</span></label>
        <input type="number" name="max_per_domain" min="0" value="3">
      </div>
      <div>
        <label>Secure gateway leads <span class="hint">Mimecast, Proofpoint, Barracuda...</span></label>
        <select name="gateway_mode">
          <option value="separate">Separate sheet (recommended)</option>
          <option value="keep">Keep in clean list</option>
          <option value="drop">Remove</option>
        </select>
      </div>
    </div>

    <div class="row">
      <div>
        <label>Email column name <span class="hint">leave empty to auto-detect</span></label>
        <input type="text" name="email_col" placeholder="e.g. Work Email">
      </div>
      <div>
        <label>Extra verifier statuses to keep <span class="hint">comma separated</span></label>
        <input type="text" name="allow_status" placeholder="e.g. catch-all">
      </div>
    </div>

    <div class="checks">
      <label><input type="checkbox" name="drop_free" value="1" checked> Remove free mailboxes (gmail, yahoo, outlook.com...) for B2B lists</label>
      <label><input type="checkbox" name="keep_role" value="1"> Keep role addresses like info@ / sales@ <span class="hint">(not recommended)</span></label>
      <label><input type="checkbox" name="keep_a_only" value="1"> Keep domains with no MX record <span class="hint">(risky)</span></label>
      <label><input type="checkbox" name="no_dns" value="1"> Skip DNS checks <span class="hint">(faster, but dead domains stay in)</span></label>
    </div>

    <button type="submit">Clean leads</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>

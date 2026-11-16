<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Reads the first sheet of a spreadsheet into rows of strings, with no extra packages:
 * old Excel .xls (BIFF8, what the old POS exports, even when it is named .csv), .xlsx and real CSV.
 * The file type is told from its first bytes, not its name.
 */
class SpreadsheetReader
{
    /** @return list<list<string>> */
    public static function rows(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return self::xls($path);
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            return self::xlsx($path);
        }

        return self::csv($path);
    }

    // ---- CSV ---------------------------------------------------------------------------------

    private static function csv(string $path): array
    {
        $text = (string) file_get_contents($path);
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $first = strtok($text, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => trim((string) $v), $row);
        }
        fclose($handle);

        return $rows;
    }

    // ---- XLSX --------------------------------------------------------------------------------

    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The .xlsx file could not be opened.');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('The .xlsx file has no first sheet.');
        }

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::columnIndex(preg_replace('/\d+/', '', (string) $c['r']));
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => self::number((string) $c->v),
                };
                $cells[$col] = trim($value);
            }
            $rows[(int) $row['r'] - 1] = self::fill($cells);
        }
        ksort($rows);

        return array_values($rows);
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    // ---- XLS (OLE2 compound file + BIFF8 records) --------------------------------------------

    private static function xls(string $path): array
    {
        $book = self::oleStream((string) file_get_contents($path), ['Workbook', 'Book']);

        $sst = [];
        $cells = [];
        $pos = 0;
        $len = strlen($book);
        $sheet = -1; // -1: workbook globals, 0: the first worksheet
        $sstData = null;
        $sstBreaks = [];
        $lastFormula = null;

        while ($pos + 4 <= $len) {
            ['id' => $id, 'n' => $n] = unpack('vid/vn', $book, $pos);
            $data = substr($book, $pos + 4, $n);
            $pos += 4 + $n;

            // The shared strings arrive in one SST record plus CONTINUE records
            if ($sstData !== null && $id !== 0x003C) {
                $sst = self::parseSst($sstData, $sstBreaks);
                $sstData = null;
            }

            switch ($id) {
                case 0x0809: // BOF
                    $sheet++;
                    if ($sheet > 1) {
                        break 2; // only the first sheet
                    }
                    break;
                case 0x00FC: // SST
                    $sstData = $data;
                    $sstBreaks = [];
                    break;
                case 0x003C: // CONTINUE
                    if ($sstData !== null) {
                        $sstBreaks[] = strlen($sstData);
                        $sstData .= $data;
                    }
                    break;
                case 0x00FD: // LABELSST
                    if ($sheet === 1) {
                        $c = unpack('vrow/vcol/vxf/Vidx', $data);
                        $cells[$c['row']][$c['col']] = $sst[$c['idx']] ?? '';
                    }
                    break;
                case 0x0203: // NUMBER
                    if ($sheet === 1) {
                        $c = unpack('vrow/vcol/vxf/evalue', $data);
                        $cells[$c['row']][$c['col']] = self::number($c['value']);
                    }
                    break;
                case 0x027E: // RK
                    if ($sheet === 1) {
                        $c = unpack('vrow/vcol/vxf/Vrk', $data);
                        $cells[$c['row']][$c['col']] = self::number(self::rk($c['rk']));
                    }
                    break;
                case 0x00BD: // MULRK
                    if ($sheet === 1) {
                        ['row' => $row, 'col' => $col] = unpack('vrow/vcol', $data);
                        for ($o = 4; $o + 6 <= $n - 2; $o += 6, $col++) {
                            $cells[$row][$col] = self::number(self::rk(unpack('V', $data, $o + 2)[1]));
                        }
                    }
                    break;
                case 0x0204: // LABEL (older files)
                    if ($sheet === 1) {
                        $c = unpack('vrow/vcol/vxf/vcch/Cflags', $data);
                        $chars = substr($data, 9, ($c['flags'] & 1) ? $c['cch'] * 2 : $c['cch']);
                        $cells[$c['row']][$c['col']] = self::text($chars, $c['flags'] & 1);
                    }
                    break;
                case 0x0006: // FORMULA: keep the cached result
                    if ($sheet === 1) {
                        $c = unpack('vrow/vcol/vxf', $data);
                        $result = substr($data, 6, 8);
                        if (substr($result, 6, 2) === "\xFF\xFF") {
                            $lastFormula = [$c['row'], $c['col']]; // a text result follows in a STRING record
                            if (ord($result[0]) !== 0) {
                                $lastFormula = null; // boolean or error
                            }
                        } else {
                            $cells[$c['row']][$c['col']] = self::number(unpack('e', $result)[1]);
                        }
                    }
                    break;
                case 0x0207: // STRING (text result of the formula before it)
                    if ($sheet === 1 && $lastFormula) {
                        $c = unpack('vcch/Cflags', $data);
                        $cells[$lastFormula[0]][$lastFormula[1]] = self::text(substr($data, 3), $c['flags'] & 1);
                        $lastFormula = null;
                    }
                    break;
            }
        }

        if ($cells === []) {
            return [];
        }
        $rows = [];
        for ($r = 0, $max = max(array_keys($cells)); $r <= $max; $r++) {
            $rows[] = self::fill(array_map('trim', $cells[$r] ?? []));
        }

        return $rows;
    }

    /** Pulls one named stream out of an OLE2 compound file (the container .xls files live in). */
    private static function oleStream(string $file, array $names): string
    {
        if (strlen($file) < 512) {
            throw new RuntimeException('The .xls file is too short.');
        }
        $h = unpack('vminor/vmajor/vorder/vsectorShift/vminiShift', $file, 0x18);
        $sectorSize = 1 << $h['sectorShift'];
        $miniSize = 1 << $h['miniShift'];
        $u = fn (int $offset) => unpack('V', $file, $offset)[1];
        $sector = fn (int $sid) => substr($file, ($sid + 1) * $sectorSize, $sectorSize);

        // Where the FAT sectors are: 109 in the header, then a chain of DIFAT sectors
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $u(0x4C + $i * 4);
            if ($sid < 0xFFFFFFFA) {
                $fatSectors[] = $sid;
            }
        }
        for ($sid = $u(0x44), $guard = 0; $sid < 0xFFFFFFFA && $guard++ < 10000;) {
            $block = $sector($sid);
            $per = $sectorSize / 4 - 1;
            for ($i = 0; $i < $per; $i++) {
                $s = unpack('V', $block, $i * 4)[1];
                if ($s < 0xFFFFFFFA) {
                    $fatSectors[] = $s;
                }
            }
            $sid = unpack('V', $block, $per * 4)[1];
        }
        $fat = [];
        foreach ($fatSectors as $s) {
            $fat = array_merge($fat, array_values(unpack('V*', $sector($s))));
        }

        $chain = function (int $sid, array $table, callable $read) {
            $out = '';
            for ($guard = 0; $sid < 0xFFFFFFFA && isset($table[$sid]) && $guard++ < 1000000; $sid = $table[$sid]) {
                $out .= $read($sid);
            }

            return $out;
        };

        $dir = $chain($u(0x30), $fat, $sector);
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLen = unpack('v', $dir, $o + 64)[1];
            $entries[] = [
                'name' => $nameLen > 2 ? mb_convert_encoding(substr($dir, $o, $nameLen - 2), 'UTF-8', 'UTF-16LE') : '',
                'type' => ord($dir[$o + 66]),
                'start' => unpack('V', $dir, $o + 116)[1],
                'size' => unpack('V', $dir, $o + 120)[1],
            ];
        }

        $entry = collect($entries)->first(fn ($e) => $e['type'] === 2 && in_array($e['name'], $names, true));
        if (! $entry) {
            throw new RuntimeException('This .xls file has no workbook inside.');
        }

        if ($entry['size'] >= $u(0x38)) {
            return substr($chain($entry['start'], $fat, $sector), 0, $entry['size']);
        }

        // Small streams live in the mini stream, which hangs off the root entry
        $miniStream = $chain($entries[0]['start'], $fat, $sector);
        $miniFat = array_values(unpack('V*', $chain($u(0x3C), $fat, $sector) ?: "\xFE\xFF\xFF\xFF"));

        return substr($chain($entry['start'], $miniFat, fn ($sid) => substr($miniStream, $sid * $miniSize, $miniSize)), 0, $entry['size']);
    }

    /** The shared string table. A string may run on into the next CONTINUE record, which starts with a fresh width flag. */
    private static function parseSst(string $data, array $breaks): array
    {
        $strings = [];
        $count = unpack('V', $data, 4)[1];
        $pos = 8;
        $len = strlen($data);
        $nextBreak = function (int $at) use ($breaks) {
            foreach ($breaks as $b) {
                if ($b > $at) {
                    return $b;
                }
            }

            return PHP_INT_MAX;
        };

        for ($i = 0; $i < $count && $pos + 3 <= $len; $i++) {
            $cch = unpack('v', $data, $pos)[1];
            $flags = ord($data[$pos + 2]);
            $pos += 3;
            $runs = 0;
            $ext = 0;
            if ($flags & 0x08) {
                $runs = unpack('v', $data, $pos)[1];
                $pos += 2;
            }
            if ($flags & 0x04) {
                $ext = unpack('V', $data, $pos)[1];
                $pos += 4;
            }

            $wide = $flags & 0x01;
            $text = '';
            $left = $cch;
            while ($left > 0 && $pos < $len) {
                $room = $nextBreak($pos) - $pos;
                $take = min($left, intdiv($room, $wide ? 2 : 1));
                if ($take > 0) {
                    $text .= self::text(substr($data, $pos, $take * ($wide ? 2 : 1)), $wide);
                    $pos += $take * ($wide ? 2 : 1);
                    $left -= $take;
                }
                if ($left > 0) {
                    $wide = ord($data[$pos]) & 0x01; // the CONTINUE record's own width flag
                    $pos++;
                }
            }
            $pos += $runs * 4 + $ext;
            $strings[] = $text;
        }

        return $strings;
    }

    private static function rk(int $rk): float
    {
        if ($rk & 0x02) {
            $value = $rk >> 2;
            if ($value & 0x20000000) {
                $value -= 0x40000000; // negative 30-bit number
            }
        } else {
            $value = unpack('e', "\0\0\0\0".pack('V', $rk & 0xFFFFFFFC))[1];
        }

        return ($rk & 0x01) ? $value / 100 : $value;
    }

    private static function text(string $bytes, int $wide): string
    {
        return $wide ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /** 4800016644405.0 -> "4800016644405", 12.5 -> "12.5". Barcodes stored as numbers stay whole. */
    private static function number(string|float|int $value): string
    {
        if ($value === '' || ! is_numeric($value)) {
            return (string) $value;
        }
        $f = (float) $value;
        if (floor($f) == $f && abs($f) < 1e15) {
            return sprintf('%.0f', $f);
        }

        return rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
    }

    /** Sparse cells to a list, with blanks for the gaps. */
    private static function fill(array $cells): array
    {
        if ($cells === []) {
            return [];
        }
        $row = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) {
            $row[$i] = (string) $v;
        }

        return $row;
    }
}

<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Classeur Excel (Office Open XML) minimal, sans dépendance : une feuille par section, ligne d'en-tête en gras.
 * Toutes les cellules sont des chaînes en ligne (`inlineStr`) : aucune n'est jamais interprétée comme formule (TAB-02).
 */
class XlsxWriter
{
    /**
     * @param  list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>  $sheets
     */
    public static function build(array $sheets, string $creator = 'SAIE-CEEAC'): string
    {
        if ($sheets === []) {
            $sheets = [['title' => 'Export', 'headers' => [], 'rows' => []]];
        }
        $names = self::sheetNames(array_column($sheets, 'title'));
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Création du classeur impossible.');
        }
        $count = count($sheets);
        $zip->addFromString('[Content_Types].xml', self::contentTypes($count));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>'.self::escape($creator).'</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>');
        $sheetEntries = '';
        $relations = '';
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $sheetEntries .= '<sheet name="'.self::escape($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relations .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString("xl/worksheets/sheet$n.xml", self::sheet($sheets[$i]['headers'], $sheets[$i]['rows']));
        }
        $relations .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetEntries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * Noms de feuilles valides pour Excel : 31 caractères au plus, sans `[]:*?/\`, uniques.
     *
     * @param  list<string>  $titles
     * @return list<string>
     */
    public static function sheetNames(array $titles): array
    {
        $names = [];
        foreach ($titles as $i => $title) {
            $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $title) ?? '') ?: 'Feuille '.($i + 1), 0, 28);
            $name = $base;
            for ($k = 2; in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true); $k++) {
                $name = $base.' '.$k;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private static function sheet(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $all = $headers === [] ? $rows : [$headers, ...$rows];
        foreach ($all as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $value) {
                $style = $headers !== [] && $r === 0 ? ' s="1"' : '';
                $xml .= '<c r="'.self::column($c).($r + 1).'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::escape((string) $value).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function contentTypes(int $sheets): string
    {
        $overrides = '';
        for ($n = 1; $n <= $sheets; $n++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'.$overrides.'</Types>';
    }

    /** Lettre(s) de colonne Excel : 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    /** Échappement XML et retrait des caractères de contrôle interdits en XML 1.0. */
    private static function escape(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

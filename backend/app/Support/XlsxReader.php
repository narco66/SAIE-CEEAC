<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lecture de la première feuille d'un classeur XLSX (import d'écrans, §5.5), sans dépendance externe.
 * Garde-fous : taille décompressée bornée (archive piégée), XML sans entités externes, 2 000 lignes au plus.
 */
class XlsxReader
{
    private const MAX_ENTRY_BYTES = 20 * 1024 * 1024;

    /**
     * @return list<list<string>> lignes de cellules (texte), dans l'ordre des colonnes A, B, C…
     */
    public static function rows(string $path, int $maxRows = 2000): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Classeur illisible.');
        }
        try {
            $shared = [];
            if (($xml = self::entry($zip, 'xl/sharedStrings.xml')) !== null) {
                foreach (self::xml($xml)->si as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
                }
            }
            $sheet = self::entry($zip, self::firstSheetPath($zip)) ?? throw new RuntimeException('Feuille introuvable dans le classeur.');
            $rows = [];
            foreach (self::xml($sheet)->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $index = self::columnIndex((string) $c['r']);
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        'b' => (string) $c->v === '1' ? '1' : '0',
                        default => (string) $c->v,
                    };
                    $cells[$index] = $value;
                }
                if ($cells !== []) {
                    $line = array_fill(0, max(array_keys($cells)) + 1, '');
                    foreach ($cells as $i => $v) {
                        $line[$i] = $v;
                    }
                    $rows[] = $line;
                }
                if (count($rows) > $maxRows) {
                    break;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** Date Excel (numéro de série) convertie en AAAA-MM-JJ ; une valeur déjà textuelle est rendue telle quelle. */
    public static function date(string $value): string
    {
        return is_numeric($value) && (float) $value > 59 ? gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400)) : $value;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::entry($zip, 'xl/workbook.xml');
        $rels = self::entry($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook && $rels) {
            $first = self::xml($workbook)->sheets->sheet[0] ?? null;
            $id = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;
            foreach (self::xml($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $id) {
                    return 'xl/'.ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('Classeur trop volumineux une fois décompressé.');
        }

        return $zip->getFromName($name) ?: null;
    }

    private static function xml(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) {
            throw new RuntimeException('Classeur mal formé.');
        }

        return $xml;
    }

    private static function columnIndex(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', $reference) ?: 'A';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}

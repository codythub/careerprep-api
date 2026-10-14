<?php

namespace Tests\Support;

/**
 * Builds small but structurally valid PDFs for extraction tests.
 */
class PdfFixture
{
    /**
     * Text drawn with a standard Type1 font and literal strings.
     *
     * @param  list<string>  $lines
     */
    public static function simple(array $lines): string
    {
        $content = "BT\n/F1 11 Tf\n72 740 Td\n";

        foreach ($lines as $line) {
            $winAnsi = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
            $escaped = strtr($winAnsi, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
            $content .= "({$escaped}) Tj\n0 -14 Td\n";
        }

        $content .= "ET\n";

        return self::build([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            self::stream($content),
        ]);
    }

    /**
     * Text drawn the way Google Docs and Word export it: a Type0 font with Identity-H
     * encoding, 2-byte glyph IDs and a ToUnicode CMap, all in Flate-compressed streams.
     *
     * @param  list<string>  $lines
     */
    public static function identityH(array $lines): string
    {
        $glyphs = [];
        $content = "BT\n/F1 11 Tf\n72 740 Td\n";

        foreach ($lines as $line) {
            $hex = '';

            foreach (mb_str_split($line) as $character) {
                // Glyph IDs deliberately bear no relation to the character codes.
                $glyphs[$character] ??= 0x0080 + count($glyphs);
                $hex .= sprintf('%04X', $glyphs[$character]);
            }

            $content .= "<{$hex}> Tj\n0 -14 Td\n";
        }

        $content .= "ET\n";

        $mappings = '';

        foreach (array_chunk($glyphs, 100, true) as $chunk) {
            $mappings .= count($chunk)." beginbfchar\n";

            foreach ($chunk as $character => $glyph) {
                $unicode = strtoupper(bin2hex(mb_convert_encoding((string) $character, 'UTF-16BE', 'UTF-8')));
                $mappings .= sprintf("<%04X> <%s>\n", $glyph, $unicode);
            }

            $mappings .= "endbfchar\n";
        }

        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            .$mappings
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

        return self::build([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type0 /BaseFont /AAAAAA+Arial /Encoding /Identity-H /DescendantFonts [6 0 R] /ToUnicode 7 0 R >>',
            self::stream($content, compress: true),
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /AAAAAA+Arial /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 8 0 R /CIDToGIDMap /Identity /DW 500 >>',
            self::stream($cmap, compress: true),
            '<< /Type /FontDescriptor /FontName /AAAAAA+Arial /Flags 32 /FontBBox [0 -200 1000 900] /ItalicAngle 0 /Ascent 900 /Descent -200 /CapHeight 700 /StemV 80 >>',
        ]);
    }

    private static function stream(string $data, bool $compress = false): string
    {
        if ($compress) {
            $data = (string) gzcompress($data);

            return '<< /Length '.strlen($data)." /Filter /FlateDecode >>\nstream\n{$data}\nendstream";
        }

        return '<< /Length '.strlen($data)." >>\nstream\n{$data}\nendstream";
    }

    /** @param  list<string>  $objects */
    private static function build(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}

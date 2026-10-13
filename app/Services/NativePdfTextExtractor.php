<?php

namespace App\Services;

use App\Contracts\ResumeTextExtractor;
use App\Models\Resume;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class NativePdfTextExtractor implements ResumeTextExtractor
{
    public function extract(Resume $resume): string
    {
        $contents = Storage::disk($resume->disk)->get($resume->path);

        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('The stored file is not a valid PDF document.');
        }

        if (str_contains($contents, '/Encrypt')) {
            throw new RuntimeException('Encrypted PDF documents are not supported.');
        }

        preg_match_all(
            '/stream(?:\r\n|\n|\r)(.*?)endstream/s',
            $contents,
            $streams,
            PREG_OFFSET_CAPTURE,
        );

        $textParts = [];

        foreach ($streams[1] as [$stream, $offset]) {
            $dictionary = substr($contents, max(0, $offset - 1000), min(1000, $offset));
            $decodedStream = $this->decodeStream($stream, $dictionary);

            if ($decodedStream === null) {
                continue;
            }

            foreach ($this->extractTextBlocks($decodedStream) as $text) {
                $textParts[] = $text;
            }
        }

        $text = trim((string) preg_replace('/[ \t]+/', ' ', implode("\n", $textParts)));
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        if ($text === '') {
            throw new RuntimeException('No readable text was found in the PDF document.');
        }

        return $text;
    }

    private function decodeStream(string $stream, string $dictionary): ?string
    {
        if (! str_contains($dictionary, '/Filter')) {
            return rtrim($stream, "\r\n");
        }

        if (! str_contains($dictionary, '/FlateDecode')) {
            return null;
        }

        $compressed = rtrim($stream, "\r\n");
        $decoded = @gzuncompress($compressed);

        if ($decoded === false) {
            $decoded = @gzinflate($compressed);
        }

        return $decoded === false ? null : $decoded;
    }

    /** @return list<string> */
    private function extractTextBlocks(string $stream): array
    {
        preg_match_all('/BT(.*?)ET/s', $stream, $blocks);
        $textParts = [];

        foreach ($blocks[1] as $block) {
            preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $block, $literalStrings);

            foreach ($literalStrings[1] as $literalString) {
                $decoded = trim($this->decodeLiteralString($literalString));

                if ($decoded !== '') {
                    $textParts[] = $decoded;
                }
            }

            preg_match_all('/<([A-Fa-f0-9\s]+)>/', $block, $hexStrings);

            foreach ($hexStrings[1] as $hexString) {
                $decoded = $this->decodeHexString($hexString);

                if ($decoded !== '') {
                    $textParts[] = $decoded;
                }
            }
        }

        return $textParts;
    }

    private function decodeLiteralString(string $value): string
    {
        $value = (string) preg_replace("/\\\\(?:\r\n|\n|\r)/", '', $value);

        return (string) preg_replace_callback(
            '/\\\\([0-7]{1,3}|[nrtbf()\\\\])/',
            function (array $matches): string {
                if (preg_match('/^[0-7]{1,3}$/', $matches[1]) === 1) {
                    return chr(octdec($matches[1]));
                }

                return match ($matches[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    default => $matches[1],
                };
            },
            $value,
        );
    }

    private function decodeHexString(string $value): string
    {
        $hex = (string) preg_replace('/\s+/', '', $value);

        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        $decoded = hex2bin($hex);

        if ($decoded === false) {
            return '';
        }

        if (str_starts_with($decoded, "\xFE\xFF")) {
            return trim(mb_convert_encoding(substr($decoded, 2), 'UTF-8', 'UTF-16BE'));
        }

        return trim($decoded);
    }
}

<?php

namespace App\Services;

use App\Contracts\ResumeTextExtractor;
use App\Models\Resume;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Extracts text with smalot/pdfparser, which resolves font encodings and ToUnicode
 * maps (e.g. Identity-H fonts used by Google Docs and Word exports).
 */
class PdfParserTextExtractor implements ResumeTextExtractor
{
    public function extract(Resume $resume): string
    {
        $contents = Storage::disk($resume->disk)->get($resume->path);

        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('The stored file is not a valid PDF document.');
        }

        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(16 * 1024 * 1024);

        return (new Parser([], $config))->parseContent($contents)->getText();
    }
}

<?php

namespace App\Contracts;

use App\Models\Resume;

interface ResumeTextExtractor
{
    public function extract(Resume $resume): string;
}

<?php

namespace App\Contracts;

interface ResumeAssessor
{
    /**
     * @return array{
     *     version: string,
     *     overall_score: int,
     *     category_scores: array<string, int>,
     *     weaknesses: list<string>,
     *     recommendations: list<string>
     * }
     */
    public function assess(string $text): array;
}

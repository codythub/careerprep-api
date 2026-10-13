<?php

namespace App\Services;

use App\Contracts\ResumeAssessor;
use Illuminate\Support\Str;

class RuleBasedResumeAssessor implements ResumeAssessor
{
    public const VERSION = 'rule-based-v1';

    /** @var array<string, int> */
    private const WEIGHTS = [
        'contact_information' => 10,
        'professional_summary' => 10,
        'work_experience' => 20,
        'education' => 10,
        'skills' => 15,
        'structure_completeness' => 15,
        'formatting' => 5,
        'ats_readiness' => 10,
        'writing_quality' => 5,
    ];

    /**
     * @return array{
     *     version: string,
     *     overall_score: int,
     *     category_scores: array<string, int>,
     *     weaknesses: list<string>,
     *     recommendations: list<string>
     * }
     */
    public function assess(string $text): array
    {
        $normalized = Str::of($text)->replace("\r\n", "\n")->replace("\r", "\n")->trim()->toString();
        $lowercase = Str::lower($normalized);
        $wordCount = str_word_count($normalized);

        $headings = [
            'summary' => $this->hasHeading($normalized, ['professional summary', 'summary', 'profile', 'objective']),
            'experience' => $this->hasHeading($normalized, ['work experience', 'professional experience', 'experience', 'employment history']),
            'education' => $this->hasHeading($normalized, ['education', 'academic background', 'qualifications']),
            'skills' => $this->hasHeading($normalized, ['skills', 'core competencies', 'technical skills', 'competencies']),
        ];

        $hasEmail = preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $normalized) === 1;
        $hasPhone = preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $normalized) === 1;
        $hasLinkedIn = str_contains($lowercase, 'linkedin.com/');
        $bulletCount = preg_match_all('/^\s*(?:[-•▪◦*]|\d+[.)])\s+/mu', $normalized);
        $dateCount = preg_match_all('/\b(?:19|20)\d{2}\b/', $normalized);
        $actionVerbCount = preg_match_all('/\b(?:achieved|built|created|delivered|designed|developed|improved|increased|led|managed|reduced|implemented|optimized)\b/i', $normalized);

        $categoryScores = [
            'contact_information' => min(100, ($hasEmail ? 40 : 0) + ($hasPhone ? 40 : 0) + ($hasLinkedIn ? 20 : 0)),
            'professional_summary' => min(100, ($headings['summary'] ? 70 : 0) + ($this->sectionHasUsefulLength($normalized, ['professional summary', 'summary', 'profile', 'objective']) ? 30 : 0)),
            'work_experience' => min(100, ($headings['experience'] ? 40 : 0) + ($dateCount >= 2 ? 20 : 0) + ($bulletCount >= 3 ? 20 : 0) + ($actionVerbCount >= 3 ? 20 : 0)),
            'education' => min(100, ($headings['education'] ? 60 : 0) + (preg_match('/\b(?:bachelor|master|degree|diploma|phd|b\.?sc|m\.?sc|university|college)\b/i', $normalized) === 1 ? 40 : 0)),
            'skills' => min(100, ($headings['skills'] ? 60 : 0) + ($this->hasUsefulSkillsList($normalized) ? 40 : 0)),
            'structure_completeness' => min(100, count(array_filter($headings)) * 20 + ($hasEmail ? 10 : 0) + ($hasPhone ? 10 : 0)),
            'formatting' => $this->formattingScore($normalized, $bulletCount),
            'ats_readiness' => $this->atsReadinessScore($headings, $hasEmail, $wordCount, $normalized),
            'writing_quality' => $this->writingScore($normalized),
        ];

        $overallScore = 0;

        foreach (self::WEIGHTS as $category => $weight) {
            $overallScore += $categoryScores[$category] * $weight;
        }

        [$weaknesses, $recommendations] = $this->feedback($categoryScores);

        return [
            'version' => self::VERSION,
            'overall_score' => (int) round($overallScore / 100),
            'category_scores' => $categoryScores,
            'weaknesses' => $weaknesses,
            'recommendations' => $recommendations,
        ];
    }

    /** @param list<string> $headings */
    private function hasHeading(string $text, array $headings): bool
    {
        foreach ($headings as $heading) {
            if (preg_match('/^\s*'.preg_quote($heading, '/').'\s*:?[ \t]*$/mi', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $headings */
    private function sectionHasUsefulLength(string $text, array $headings): bool
    {
        foreach ($headings as $heading) {
            if (preg_match('/^\s*'.preg_quote($heading, '/').'\s*:?[ \t]*$\s*(.{80,800}?)(?=^\s*[A-Z][A-Z ]{2,}:?[ \t]*$|\z)/ims', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasUsefulSkillsList(string $text): bool
    {
        $knownSkillCount = preg_match_all('/\b(?:excel|python|javascript|php|laravel|react|sql|postgresql|project management|communication|leadership|data analysis|figma|aws|docker)\b/i', $text);
        $separatorCount = substr_count($text, ',') + substr_count($text, '|');

        return $knownSkillCount >= 4 || $separatorCount >= 5;
    }

    private function formattingScore(string $text, int $bulletCount): int
    {
        $score = 100;
        $lines = preg_split('/\n/', $text) ?: [];
        $longLines = count(array_filter($lines, fn (string $line): bool => Str::length($line) > 140));
        $uppercaseLines = count(array_filter($lines, function (string $line): bool {
            $trimmed = trim($line);

            return Str::length($trimmed) > 20 && $trimmed === Str::upper($trimmed);
        }));

        if ($longLines > 3) {
            $score -= 25;
        }

        if ($uppercaseLines > 3) {
            $score -= 20;
        }

        if ($bulletCount === 0) {
            $score -= 25;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            $score -= 30;
        }

        return max(0, $score);
    }

    /** @param array<string, bool> $headings */
    private function atsReadinessScore(array $headings, bool $hasEmail, int $wordCount, string $text): int
    {
        $score = count(array_filter($headings)) * 15;
        $score += $hasEmail ? 20 : 0;
        $score += $wordCount >= 150 && $wordCount <= 1500 ? 20 : 0;

        if (substr_count($text, '|') > 15) {
            $score -= 20;
        }

        return max(0, min(100, $score));
    }

    private function writingScore(string $text): int
    {
        $score = 100;

        if (preg_match('/[!?.,]{3,}/', $text) === 1) {
            $score -= 20;
        }

        if (preg_match_all('/\b(?:I|me|my|mine)\b/i', $text) > 5) {
            $score -= 15;
        }

        $sentences = preg_split('/[.!?]+/', $text) ?: [];
        $longSentences = count(array_filter($sentences, fn (string $sentence): bool => str_word_count($sentence) > 40));

        if ($longSentences > 2) {
            $score -= 25;
        }

        return max(0, $score);
    }

    /**
     * @param  array<string, int>  $scores
     * @return array{list<string>, list<string>}
     */
    private function feedback(array $scores): array
    {
        $feedback = [
            'contact_information' => ['Contact information is incomplete.', 'Include a professional email address, phone number, and relevant profile link.'],
            'professional_summary' => ['A clear professional summary is missing or too brief.', 'Add a concise summary highlighting experience, strengths, and target role.'],
            'work_experience' => ['Work experience lacks clear structure, dates, bullets, or achievement language.', 'Use dated roles with concise bullets that begin with action verbs and show measurable outcomes.'],
            'education' => ['Education details are missing or difficult to identify.', 'Add an Education section with qualification, institution, and completion date.'],
            'skills' => ['Relevant skills are not presented clearly.', 'Add a focused Skills section containing role-relevant technical and transferable skills.'],
            'structure_completeness' => ['The resume is missing important standard sections.', 'Use clearly labelled Summary, Experience, Education, and Skills sections.'],
            'formatting' => ['Text formatting indicators suggest the resume may be difficult to scan.', 'Use concise lines, consistent headings, and bullet points for achievements.'],
            'ats_readiness' => ['The document may be harder for basic automated parsers to interpret.', 'Use standard section headings, plain text labels, and avoid dense separator-based layouts.'],
            'writing_quality' => ['Basic writing heuristics found readability concerns.', 'Use concise sentences, restrained punctuation, and achievement-focused language.'],
        ];

        $weaknesses = [];
        $recommendations = [];

        foreach ($scores as $category => $score) {
            if ($score < 70) {
                $weaknesses[] = $feedback[$category][0];
                $recommendations[] = $feedback[$category][1];
            }
        }

        return [$weaknesses, $recommendations];
    }
}

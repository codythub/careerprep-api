<?php

namespace Tests\Unit\Services;

use App\Services\RuleBasedResumeAssessor;
use PHPUnit\Framework\TestCase;

class RuleBasedResumeAssessorTest extends TestCase
{
    public function test_complete_resume_returns_versioned_category_scores_and_feedback(): void
    {
        $assessment = (new RuleBasedResumeAssessor)->assess(<<<'TEXT'
Jane Candidate
jane@example.com | +234 801 234 5678 | linkedin.com/in/janecandidate

PROFESSIONAL SUMMARY
Product-focused software engineer with five years of experience delivering reliable applications and measurable improvements for customers and internal teams.

WORK EXPERIENCE
Senior Engineer, Example Ltd, 2021 - 2026
- Developed services that reduced response time by 35 percent.
- Led delivery of twelve reliable production releases.
- Implemented automated tests that reduced defects by 30 percent.

EDUCATION
Bachelor of Science in Computer Science, Example University, 2020

SKILLS
PHP, Laravel, PostgreSQL, SQL, Docker, AWS, JavaScript, communication
TEXT);

        $this->assertSame('rule-based-v1', $assessment['version']);
        $this->assertSame([
            'contact_information',
            'professional_summary',
            'work_experience',
            'education',
            'skills',
            'structure_completeness',
            'formatting',
            'ats_readiness',
            'writing_quality',
        ], array_keys($assessment['category_scores']));
        $this->assertGreaterThanOrEqual(0, $assessment['overall_score']);
        $this->assertLessThanOrEqual(100, $assessment['overall_score']);
        $this->assertIsArray($assessment['weaknesses']);
        $this->assertIsArray($assessment['recommendations']);
    }

    public function test_incomplete_resume_returns_weaknesses_and_actionable_recommendations(): void
    {
        $assessment = (new RuleBasedResumeAssessor)->assess('I worked at a company. I did many things!!!');

        $this->assertLessThan(50, $assessment['overall_score']);
        $this->assertContains('Contact information is incomplete.', $assessment['weaknesses']);
        $this->assertContains('The resume is missing important standard sections.', $assessment['weaknesses']);
        $this->assertContains('Use clearly labelled Summary, Experience, Education, and Skills sections.', $assessment['recommendations']);
    }
}

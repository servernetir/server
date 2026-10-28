<?php

namespace Tests\Unit;

use App\Services\Growth\HermesLearningAssessment;
use PHPUnit\Framework\TestCase;

class HermesLearningAssessmentTest extends TestCase
{
    public function test_teachback_covers_six_sections_without_supplying_the_answers(): void
    {
        $questions = (new HermesLearningAssessment)->questions();
        $sections = array_unique(array_column($questions['questions'], 'section'));
        sort($sections);
        $this->assertSame(['ai_growth', 'blog', 'comments', 'kb', 'mail', 'marketing'], array_values($sections));
        foreach ($questions['questions'] as $question) {
            $this->assertArrayNotHasKey('decision', $question);
            $this->assertArrayNotHasKey('verification', $question);
        }
    }

    public function test_no_answers_never_mean_the_model_has_learned(): void
    {
        $assessment = (new HermesLearningAssessment)->assess([]);
        $this->assertFalse($assessment['decision_contract_passed']);
        $this->assertSame(11, count($assessment['failed_scenarios']));
        $this->assertFalse($assessment['semantic_mastery_verified']);
        $this->assertFalse($assessment['execution_authorized_by_assessment']);
    }

    public function test_a_good_decision_without_result_verification_fails(): void
    {
        $assessment = (new HermesLearningAssessment)->assess([
            'marketing_unknown' => ['decision' => 'reconcile_without_resend'],
            'machine_review' => ['decision' => 'record_machine_review', 'verification' => 'human_approved'],
        ]);
        $this->assertContains('marketing_unknown', $assessment['failed_scenarios']);
        $this->assertContains('machine_review', $assessment['failed_scenarios']);
        $this->assertSame(0, $assessment['passed_count']);
        $this->assertFalse($assessment['human_knowledge_approval']);
    }

    public function test_grade_does_not_echo_untrusted_model_output(): void
    {
        $assessment = (new HermesLearningAssessment)->assess([
            'blog_duplicate' => ['decision' => '<script>private-model-token</script>', 'verification' => 'private@example.invalid'],
        ]);
        $json = json_encode($assessment, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-model-token', $json);
        $this->assertStringNotContainsString('private@example.invalid', $json);
        $this->assertFalse($assessment['decision_contract_passed']);
    }
}

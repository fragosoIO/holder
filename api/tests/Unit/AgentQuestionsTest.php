<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\HolderException;
use App\Domain\Work\AgentQuestions;
use Codeception\Test\Unit;

final class AgentQuestionsTest extends Unit
{
    private const FOLLOW_UP = <<<'TEXT'
Answer these four questions so I can propose a plan and a team. I will not hire anyone until you approve that plan.

1. What does this organization do, and who is it for?
2. What should we achieve first?
3. What limits apply (time, budget, tools, or people)?
4. What does done look like?
TEXT;

    public function testANumberedQuestionListBecomesACardAndKeepsTheLeadIn(): void
    {
        $card = (new AgentQuestions())->open(null, [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => self::FOLLOW_UP],
        ]);

        $this->assertIsArray($card);
        $this->assertSame('c1', $card['commentId']);
        $this->assertSame(
            'Answer these four questions so I can propose a plan and a team. I will not hire anyone until you approve that plan.',
            $card['commentBody'],
        );
        $this->assertSame('Continue', $card['submitLabel']);
        $this->assertCount(4, $card['questions']);
        $this->assertSame('q1', $card['questions'][0]['id']);
        $this->assertSame('What does this organization do, and who is it for?', $card['questions'][0]['prompt']);
        $this->assertSame([], $card['questions'][0]['options']);
        $this->assertSame('What does done look like?', $card['questions'][3]['prompt']);
    }

    public function testALaterUserCommentClosesTheCard(): void
    {
        $questions = new AgentQuestions();
        $open = $questions->open(null, [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => "1. What should we achieve first?\n2. What does done look like?"],
        ]);
        $this->assertIsArray($open);

        $closed = $questions->open(null, [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => "1. What should we achieve first?\n2. What does done look like?"],
            ['id' => 'c2', 'author_type' => 'user', 'body' => 'Ship a site.'],
        ]);
        $this->assertNull($closed);
        $this->assertNull($questions->open(null, [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => 'The run failed.'],
        ]));
    }

    public function testASavedSelectWinsAndRendersTheChosenLabel(): void
    {
        $questions = new AgentQuestions();
        $card = $questions->open([
            'intro' => '',
            'questions' => [[
                'id' => 'first',
                'prompt' => 'What should we achieve first?',
                'options' => [
                    ['id' => 'site', 'label' => 'A one-page site', 'description' => 'Ship a public page.'],
                    ['id' => 'plan', 'label' => 'A written plan', 'description' => 'Agree the work before building.', 'freeText' => true],
                ],
            ]],
        ], [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => "1. What should we achieve first?\n2. What does done look like?"],
        ]);

        $this->assertIsArray($card);
        $this->assertNull($card['commentId']);
        $this->assertCount(2, $card['questions'][0]['options']);
        $this->assertFalse($card['questions'][0]['options'][0]['freeText']);
        $this->assertTrue($card['questions'][0]['options'][1]['freeText']);
        $this->assertSame(
            "What should we achieve first?\nA one-page site",
            $questions->render($card, [['id' => 'first', 'optionId' => 'site', 'text' => '']]),
        );
        $this->assertSame(
            "What should we achieve first?\nA written plan\nFocus on pricing.",
            $questions->render($card, [['id' => 'first', 'optionId' => 'plan', 'text' => 'Focus on pricing.']]),
        );
    }

    public function testOpenAnswersRenderUnderTheirPrompts(): void
    {
        $questions = new AgentQuestions();
        $card = $questions->open(null, [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => "1. What should we achieve first?\n2. What does done look like?"],
        ]);
        $this->assertIsArray($card);
        $this->assertSame(
            "What should we achieve first?\nShip a site.\n\nWhat does done look like?\nThe page is live.",
            $questions->render($card, [
                ['id' => 'q2', 'optionId' => '', 'text' => 'The page is live.'],
                ['id' => 'q1', 'optionId' => '', 'text' => 'Ship a site.'],
            ]),
        );
    }

    public function testASelectNeedsTwoOptionsAndAnAnswerNeedsText(): void
    {
        $questions = new AgentQuestions();
        try {
            $questions->normalize([
                'questions' => [[
                    'id' => 'only',
                    'prompt' => 'Which one?',
                    'options' => [['id' => 'a', 'label' => 'Only']],
                ]],
            ]);
            $this->fail('A one-option select should be rejected.');
        } catch (HolderException $exception) {
            $this->assertSame('invalid_question', $exception->errorCode);
        }

        $card = $questions->open(null, [
            ['id' => 'c1', 'author_type' => 'agent', 'body' => '1. What should we achieve first?'],
        ]);
        $this->assertIsArray($card);
        try {
            $questions->render($card, [['id' => 'q1', 'optionId' => '', 'text' => '   ']]);
            $this->fail('An empty answer should be rejected.');
        } catch (HolderException $exception) {
            $this->assertSame('missing_field', $exception->errorCode);
        }
    }
}

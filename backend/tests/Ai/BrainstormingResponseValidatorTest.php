<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Brainstorming\BrainstormingLimits;
use App\Ai\Brainstorming\BrainstormingNormalizer;
use App\Ai\Brainstorming\BrainstormingResponseValidator;
use App\Ai\Exception\AiProviderFailureException;
use PHPUnit\Framework\TestCase;

/**
 * The validator is the trust boundary for model output: these cases pin what it
 * repairs, what it caps and what it refuses.
 */
final class BrainstormingResponseValidatorTest extends TestCase
{
    private BrainstormingResponseValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BrainstormingResponseValidator(new BrainstormingNormalizer());
    }

    public function testAcceptsAValidProposalAndAssignsDeterministicIds(): void
    {
        $proposal = $this->validator->validate($this->document([
            ['id' => 'model-chose-this', 'title' => '  Publics   prioritaires ', 'category' => 'audience', 'notes' => [
                ['id' => 'whatever', 'type' => 'question', 'content' => "Combien d'abonnés ?\n"],
            ]],
            $this->section('Risques', 'risks', 2),
        ]));

        self::assertSame('Board title', $proposal->title);
        self::assertSame('Board objective', $proposal->objective);
        self::assertFalse($proposal->truncated);
        self::assertSame('s1', $proposal->sections[0]->id);
        self::assertSame('Publics prioritaires', $proposal->sections[0]->title);
        self::assertSame('audience', $proposal->sections[0]->category);
        self::assertSame('s1-n1', $proposal->sections[0]->notes[0]->id);
        self::assertSame("Combien d'abonnés ?", $proposal->sections[0]->notes[0]->content);
        self::assertSame(3, $proposal->noteCount());
    }

    public function testCapsSectionsToTheContractMaximum(): void
    {
        $proposal = $this->validator->validate($this->document([
            $this->section('One'),
            $this->section('Two'),
            $this->section('Three'),
            $this->section('Four'),
            $this->section('Five'),
            $this->section('Six'),
        ]));

        self::assertCount(BrainstormingLimits::MAX_SECTIONS, $proposal->sections);
        self::assertTrue($proposal->truncated);
    }

    public function testCapsNotesPerSectionAndInTotal(): void
    {
        $proposal = $this->validator->validate($this->document([
            $this->section('One', 'ideas', 12),
            $this->section('Two', 'ideas', 12),
            $this->section('Three', 'ideas', 12),
        ]));

        self::assertSame(BrainstormingLimits::MAX_TOTAL_NOTES, $proposal->noteCount());
        self::assertTrue($proposal->truncated);
        foreach ($proposal->sections as $section) {
            self::assertLessThanOrEqual(BrainstormingLimits::MAX_NOTES_PER_SECTION, count($section->notes));
        }
    }

    public function testNormalisesUnknownTypeAndCategory(): void
    {
        $proposal = $this->validator->validate($this->document([
            ['title' => 'Ideas', 'category' => 'nonsense', 'notes' => [
                ['type' => 'TODO', 'content' => 'First idea'],
                ['type' => 'action', 'content' => 'Second idea'],
                ['type' => 'unknown', 'content' => 'Third idea'],
            ]],
        ]));

        self::assertSame('ideas', $proposal->sections[0]->category);
        self::assertSame('idea', $proposal->sections[0]->notes[0]->type);
        self::assertSame('action', $proposal->sections[0]->notes[1]->type);
        self::assertSame('idea', $proposal->sections[0]->notes[2]->type);
    }

    public function testDropsEmptyDuplicateAndEmptySections(): void
    {
        $proposal = $this->validator->validate($this->document([
            ['title' => 'Recovered', 'category' => 'ideas', 'notes' => [
                ['type' => 'idea', 'content' => 'Same idea'],
                ['type' => 'idea', 'content' => 'same IDEA'],
                ['type' => 'idea', 'content' => '   '],
                ['type' => 'idea', 'content' => 'Another idea'],
                ['type' => 'idea', 'content' => 'Third idea'],
            ]],
            ['title' => 'All empty', 'category' => 'ideas', 'notes' => [
                ['type' => 'idea', 'content' => ''],
            ]],
        ]));

        self::assertCount(1, $proposal->sections);
        self::assertSame(3, $proposal->noteCount());
        self::assertFalse($proposal->truncated);
    }

    public function testClampsLongTextAndReportsTruncation(): void
    {
        $proposal = $this->validator->validate($this->document([
            ['title' => str_repeat('T', 200), 'category' => 'ideas', 'notes' => [
                ['type' => 'idea', 'content' => str_repeat('c', 400)],
                ['type' => 'idea', 'content' => 'Second idea'],
                ['type' => 'idea', 'content' => 'Third idea'],
            ]],
        ], str_repeat('B', 200)));

        self::assertTrue($proposal->truncated);
        self::assertSame(BrainstormingLimits::MAX_PROPOSAL_TITLE_LENGTH, mb_strlen($proposal->title));
        self::assertSame(BrainstormingLimits::MAX_SECTION_TITLE_LENGTH, mb_strlen($proposal->sections[0]->title));
        self::assertSame(BrainstormingLimits::MAX_NOTE_CONTENT_LENGTH, mb_strlen($proposal->sections[0]->notes[0]->content));
    }

    public function testRejectsRelationships(): void
    {
        $document = json_encode([
            'proposal' => [
                'title' => 'Board title',
                'objective' => 'Board objective',
                'relationships' => [],
                'sections' => [$this->section('One')],
            ],
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(AiProviderFailureException::class);
        $this->validator->validate($document);
    }

    public function testRejectsAProposalWithTooFewUsableNotes(): void
    {
        $this->expectException(AiProviderFailureException::class);
        $this->validator->validate($this->document([
            ['title' => 'Only one', 'category' => 'ideas', 'notes' => [
                ['type' => 'idea', 'content' => 'A single idea'],
            ]],
        ]));
    }

    public function testRejectsAMissingTitle(): void
    {
        $document = json_encode([
            'proposal' => [
                'objective' => 'Board objective',
                'sections' => [$this->section('One')],
            ],
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(AiProviderFailureException::class);
        $this->validator->validate($document);
    }

    public function testRejectsAJsonArrayDocument(): void
    {
        $this->expectException(AiProviderFailureException::class);
        $this->validator->validate('[]');
    }

    public function testRejectsOversizedOutput(): void
    {
        $this->expectException(AiProviderFailureException::class);
        $this->validator->validate(str_repeat('x', BrainstormingLimits::MAX_PROPOSAL_BYTES + 1));
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function document(array $sections, string $title = 'Board title', string $objective = 'Board objective'): string
    {
        return json_encode([
            'proposal' => ['title' => $title, 'objective' => $objective, 'sections' => $sections],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function section(string $title, string $category = 'ideas', int $notes = 3): array
    {
        $items = [];
        for ($note = 1; $note <= $notes; ++$note) {
            $items[] = ['type' => 'idea', 'content' => $title.' idea '.$note];
        }

        return ['title' => $title, 'category' => $category, 'notes' => $items];
    }
}

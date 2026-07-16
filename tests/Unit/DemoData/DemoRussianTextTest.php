<?php

declare(strict_types=1);

namespace Tests\Unit\DemoData;

use App\Services\DemoData\DemoLexicalFingerprint;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRussianText;
use App\Services\DemoData\DemoStableValue;
use App\ValueObjects\CommentBody;
use App\ValueObjects\ReviewBody;
use Tests\TestCase;

final class DemoRussianTextTest extends TestCase
{
    public function test_one_hundred_personas_have_normal_unique_names_usernames_and_complete_profiles(): void
    {
        $factory = new DemoPersonaFactory(
            new DemoStableValue('seasonvar-demo-v1'),
            new DemoLexicalFingerprint,
        );
        $personas = array_map($factory->make(...), range(1, 100));

        $this->assertCount(100, array_unique(array_column($personas, 'displayName')));
        $this->assertCount(100, array_unique(array_column($personas, 'username')));

        foreach ($personas as $persona) {
            $this->assertMatchesRegularExpression('/^[А-ЯЁ][а-яё-]+ [А-ЯЁ][а-яё-]+$/u', $persona->displayName);
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $persona->username);
            $this->assertNotSame('', $persona->city);
            $this->assertNotSame('', $persona->occupation);
            $this->assertGreaterThanOrEqual(2, count($persona->favoriteGenres));
            $this->assertGreaterThan(100, mb_strlen($persona->biography));
        }
    }

    public function test_generated_reviews_comments_and_workflows_are_valid_russian_text_without_placeholders(): void
    {
        $stable = new DemoStableValue('seasonvar-demo-v1');
        $fingerprints = new DemoLexicalFingerprint;
        $persona = (new DemoPersonaFactory($stable, $fingerprints))->make(1);
        $text = new DemoRussianText($stable, $fingerprints);

        $review = $text->reviewBody($persona, 'Тихая гавань', 42);
        $comment = $text->commentBody($persona, 'Тихая гавань', 42);
        $reply = $text->replyBody($persona, 'Марина', 'Тихая гавань', 42);
        $collection = $text->collection($persona, 3);
        $request = $text->request($persona, 'добавление сериала', 4);
        $issue = $text->technicalIssue($persona, 'ошибка воспроизведения', 5);

        $this->assertNotSame('', ReviewBody::from($review)->normalizedHash);
        $this->assertNotSame('', CommentBody::from($comment)->hash);
        $this->assertNotSame('', CommentBody::from($reply)->hash);

        foreach ([$review, $comment, $reply, ...array_values($collection), ...array_values($request), ...array_values($issue)] as $value) {
            $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $value);
            $this->assertStringNotContainsStringIgnoringCase('lorem ipsum', $value);
            $this->assertStringNotContainsStringIgnoringCase('тестовый текст', $value);
        }
    }

    public function test_domain_text_is_deterministic_and_minimally_repeated(): void
    {
        $stable = new DemoStableValue('seasonvar-demo-v1');
        $fingerprints = new DemoLexicalFingerprint;
        $factory = new DemoPersonaFactory($stable, $fingerprints);
        $text = new DemoRussianText($stable, $fingerprints);
        $bodies = [];

        foreach (range(1, 250) as $ordinal) {
            $persona = $factory->make((($ordinal - 1) % 100) + 1);
            $body = $text->reviewBody($persona, 'Северный ветер', $ordinal);
            $this->assertSame($body, $text->reviewBody($persona, 'Северный ветер', $ordinal));
            $bodies[] = $body;
        }

        $this->assertCount(250, array_unique($bodies));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use InvalidArgumentException;

final class DemoLexicalFingerprint
{
    /** @var list<string> */
    private array $masculine;

    /** @var list<string> */
    private array $feminine;

    /** @var list<string> */
    private array $neuter;

    /** @var list<string> */
    private array $plural;

    public function __construct()
    {
        $this->masculine = $this->combine(
            ['сдержанный', 'точный', 'живой', 'спокойный', 'выразительный', 'честный', 'уверенный', 'тонкий', 'ровный', 'смелый', 'мягкий', 'вдумчивый'],
            ['ритм', 'характер', 'диалог', 'конфликт', 'поворот', 'финал'],
        );
        $this->feminine = $this->combine(
            ['бережная', 'ясная', 'точная', 'живая', 'спокойная', 'выразительная', 'честная', 'уверенная', 'тонкая', 'ровная', 'смелая', 'вдумчивая'],
            ['интонация', 'атмосфера', 'динамика', 'драматургия', 'деталь', 'развязка'],
        );
        $this->neuter = $this->combine(
            ['цельное', 'ясное', 'точное', 'живое', 'спокойное', 'выразительное', 'честное', 'уверенное', 'тонкое', 'ровное', 'смелое', 'вдумчивое'],
            ['настроение', 'развитие', 'повествование', 'наблюдение', 'решение', 'послевкусие'],
        );
        $this->plural = $this->combine(
            ['убедительные', 'ясные', 'точные', 'живые', 'спокойные', 'выразительные', 'честные', 'уверенные', 'тонкие', 'ровные', 'смелые', 'вдумчивые'],
            ['герои', 'акценты', 'эпизоды', 'переходы', 'мотивы', 'отношения'],
        );
    }

    public function clause(string $domain, int $ordinal): string
    {
        if ($ordinal < 0 || $ordinal >= $this->capacity()) {
            throw new InvalidArgumentException('Lexical fingerprint ordinal is outside generator capacity.');
        }

        $indexes = [];
        $remainder = $ordinal;

        foreach ([$this->masculine, $this->feminine, $this->neuter, $this->plural] as $vocabulary) {
            $indexes[] = $remainder % count($vocabulary);
            $remainder = intdiv($remainder, count($vocabulary));
        }

        $introduction = match ($domain) {
            'biography' => 'В историях мне особенно близки',
            'comment' => 'После этой серии запомнились',
            'reply' => 'В продолжение разговора отмечу',
            'request' => 'Для уточнения запроса важны',
            'report' => 'При проверке обращения заметны',
            'issue' => 'При повторной проверке проявились',
            'support' => 'В ходе диагностики подтверждены',
            default => 'В этой истории особенно работают',
        };

        return sprintf(
            '%s %s, %s, %s и %s.',
            $introduction,
            $this->masculine[$indexes[0]],
            $this->feminine[$indexes[1]],
            $this->neuter[$indexes[2]],
            $this->plural[$indexes[3]],
        );
    }

    public function capacity(): int
    {
        return count($this->masculine)
            * count($this->feminine)
            * count($this->neuter)
            * count($this->plural);
    }

    /**
     * @param  list<string>  $adjectives
     * @param  list<string>  $nouns
     * @return list<string>
     */
    private function combine(array $adjectives, array $nouns): array
    {
        $values = [];

        foreach ($adjectives as $adjective) {
            foreach ($nouns as $noun) {
                $values[] = $adjective.' '.$noun;
            }
        }

        return $values;
    }
}

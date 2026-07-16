<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoPersona;

final readonly class DemoRussianText
{
    private const OPENINGS = [
        'Сериал уверенно задаёт настроение уже в первых сценах.',
        'История раскрывается постепенно и не торопит зрителя.',
        'Авторы внимательно работают с характерами и последствиями решений.',
        'Повествование держится на деталях, которые интересно замечать.',
        'Главная сила этой истории — в живых реакциях героев.',
        'Серия хорошо сочетает движение сюжета и спокойные наблюдения.',
        'Здесь особенно убедительно показано, как меняются отношения персонажей.',
        'Материал подан собранно, без лишней суеты и громких обещаний.',
        'История оставляет пространство для собственного вывода зрителя.',
        'Авторы не упрощают конфликт и дают героям право на ошибку.',
    ];

    private const CONCLUSIONS = [
        'Продолжение хочется смотреть прежде всего ради дальнейшего развития героев.',
        'После просмотра остаётся несколько вопросов, и это работает на интерес к следующей серии.',
        'Эпизод получился цельным и при этом сохранил интригу.',
        'Особенно приятно, что важные решения не объясняют зрителю слишком прямолинейно.',
        'К финалу выбранная интонация становится ещё точнее.',
        'Эта часть истории хорошо вознаграждает внимательный просмотр.',
        'Вернуться к отдельным сценам будет интересно после завершения сезона.',
        'Впечатление складывается не из одного поворота, а из множества небольших деталей.',
        'Серия спокойно выполняет свою задачу и заметно двигает общую историю.',
        'Даже спорные решения здесь выглядят осознанными и дают повод для обсуждения.',
    ];

    public function __construct(
        private DemoStableValue $stable,
        private DemoLexicalFingerprint $fingerprints,
    ) {}

    public function biography(DemoPersona $persona): string
    {
        return $persona->biography;
    }

    public function reviewTitle(DemoPersona $persona, string $titleName, int $ordinal): string
    {
        $templates = [
            'Внимательный взгляд на «%s»',
            'Почему история «%s» работает',
            'Впечатления после просмотра «%s»',
            'Сильные стороны сериала «%s»',
            'Что запомнилось в истории «%s»',
            'Разговор о героях сериала «%s»',
        ];

        return sprintf($this->stable->pick($this->scope($persona, 'review-title', $ordinal), $templates), $titleName);
    }

    public function reviewBody(DemoPersona $persona, string $titleName, int $ordinal): string
    {
        return implode(' ', [
            $this->stable->pick($this->scope($persona, 'review-opening', $ordinal), self::OPENINGS),
            sprintf('В «%s» мне ближе всего %s способ разговора со зрителем.', $titleName, $persona->reviewStyle),
            $this->fingerprints->clause('review', $ordinal),
            $this->stable->pick($this->scope($persona, 'review-conclusion', $ordinal), self::CONCLUSIONS),
        ]);
    }

    public function commentBody(DemoPersona $persona, string $titleName, int $ordinal): string
    {
        $openings = [
            'Интересно, как эта серия меняет прежнее представление о героях.',
            'Мне понравилось, что ключевая сцена не дала слишком простого ответа.',
            'После просмотра хочется обсудить не поворот, а мотивы персонажей.',
            'Хорошо заметно, как небольшая деталь связывает несколько сюжетных линий.',
            'Эпизод оказался спокойнее предыдущего, но дал больше материала для разговора.',
            'Музыка и паузы здесь сказали не меньше, чем прямые реплики.',
            'Сильнее всего сработала реакция героев на уже сделанный выбор.',
            'Понравилось, что авторы не стали искусственно ускорять развязку.',
        ];

        return sprintf(
            '%s В «%s» это особенно заметно. %s',
            $this->stable->pick($this->scope($persona, 'comment-opening', $ordinal), $openings),
            $titleName,
            $this->fingerprints->clause('comment', $ordinal),
        );
    }

    public function replyBody(DemoPersona $persona, string $addressee, string $titleName, int $ordinal): string
    {
        $agreements = [
            'согласен с мыслью о мотивации героев, но иначе воспринимаю финальную сцену',
            'тоже обратил внимание на эту деталь, особенно после второго эпизода',
            'понимаю такую оценку, хотя для меня медленный темп оказался преимуществом',
            'хорошее наблюдение; кажется, следующая серия даст ему новый смысл',
            'вижу этот момент немного иначе, потому что важнее оказалась реакция второго героя',
            'спасибо за точную формулировку, она помогает иначе посмотреть на сцену',
        ];

        return sprintf(
            '%s, %s. В разговоре о «%s» это как раз один из самых интересных вопросов. %s',
            $addressee,
            $this->stable->pick($this->scope($persona, 'reply-position', $ordinal), $agreements),
            $titleName,
            $this->fingerprints->clause('reply', $ordinal),
        );
    }

    public function personalTag(DemoPersona $persona, int $ordinal): string
    {
        $moods = ['Тихие', 'Напряжённые', 'Светлые', 'Мрачные', 'Уютные', 'Динамичные', 'Ироничные', 'Вдумчивые'];
        $groups = ['истории', 'вечера', 'детективы', 'драмы', 'приключения', 'открытия', 'миры', 'диалоги'];

        return $moods[$ordinal % count($moods)].' '.$groups[intdiv($ordinal, count($moods)) % count($groups)];
    }

    public function publicTag(int $ordinal): string
    {
        $adjectives = [
            'Тихая', 'Напряжённая', 'Светлая', 'Мрачная', 'Уютная', 'Динамичная', 'Ироничная', 'Вдумчивая',
            'Тёплая', 'Холодная', 'Лирическая', 'Загадочная', 'Неспешная', 'Энергичная', 'Драматическая', 'Комедийная',
            'Историческая', 'Семейная', 'Приключенческая', 'Фантастическая', 'Реалистичная', 'Камерная', 'Масштабная',
            'Психологическая', 'Музыкальная', 'Романтическая', 'Детективная', 'Документальная', 'Авторская',
            'Сатирическая', 'Мистическая', 'Эмоциональная', 'Атмосферная', 'Необычная', 'Честная', 'Выразительная',
            'Смелая', 'Бережная', 'Современная', 'Классическая',
        ];
        $subjects = [
            'история', 'атмосфера', 'интрига', 'драматургия', 'комедия', 'фантастика', 'мелодрама', 'загадка',
            'хроника', 'экранизация', 'анимация', 'биография', 'режиссура', 'операторская работа', 'музыка',
            'развязка', 'экспедиция', 'семья', 'дружба', 'любовь', 'тайна', 'эпоха', 'провинция', 'столица',
            'адаптация', 'сатира', 'мистика', 'драма', 'постановка', 'композиция', 'легенда', 'ретроспектива',
            'хореография', 'психология', 'мифология', 'повседневность', 'утопия', 'антиутопия', 'импровизация', 'премьера',
        ];
        $capacity = count($adjectives) * count($subjects);

        if ($ordinal < 0 || $ordinal >= $capacity) {
            throw new \InvalidArgumentException('Public tag ordinal is outside generator capacity.');
        }

        return $adjectives[$ordinal % count($adjectives)]
            .' '.$subjects[intdiv($ordinal, count($adjectives))];
    }

    /** @return array{name: string, description: string} */
    public function collection(DemoPersona $persona, int $ordinal): array
    {
        $names = [
            'Для спокойного вечера', 'Истории с сильными героями', 'Неочевидные детективы', 'Сериалы для обсуждения',
            'Красивые путешествия', 'Лучшие диалоги', 'Короткие открытия', 'Медленное повествование',
            'Семейный просмотр', 'Музыка и атмосфера', 'Исторические миры', 'Смелая фантастика',
            'Тёплый юмор', 'Сложные решения', 'Выразительная анимация', 'Документальные находки',
            'Напряжённые выходные', 'Любимые экранизации', 'Герои второго плана', 'Финалы для разговора',
        ];
        $name = $names[$ordinal % count($names)];

        return [
            'name' => $name,
            'description' => sprintf(
                '%s собирает здесь сериалы, которые подходят под тему «%s». %s',
                $persona->givenName,
                mb_strtolower($name),
                $this->fingerprints->clause('collection', $persona->index * 20 + $ordinal),
            ),
        ];
    }

    /** @return array{title: string, description: string} */
    public function request(DemoPersona $persona, string $type, int $ordinal): array
    {
        return [
            'title' => sprintf('Уточнение: %s', $type),
            'description' => sprintf(
                'Прошу проверить раздел «%s». Я сверил доступную карточку и поиск, но нужной информации пока не нашёл. %s',
                $type,
                $this->fingerprints->clause('request', $persona->index * 10 + $ordinal),
            ),
        ];
    }

    public function report(DemoPersona $persona, string $subject, int $ordinal): string
    {
        return sprintf(
            'Прошу модерацию проверить %s: формулировка может нарушать правила спокойного обсуждения. %s',
            $subject,
            $this->fingerprints->clause('report', $persona->index * 20 + $ordinal),
        );
    }

    /** @return array{subject: string, description: string, steps: string, expected: string, actual: string} */
    public function technicalIssue(DemoPersona $persona, string $type, int $ordinal): array
    {
        return [
            'subject' => sprintf('Проверка: %s', $type),
            'description' => sprintf(
                'Проблема воспроизводится при обычном использовании портала и не исчезает после повторного открытия страницы. %s',
                $this->fingerprints->clause('issue', $persona->index * 10 + $ordinal),
            ),
            'steps' => 'Открыть нужную карточку, перейти к проблемному разделу, выполнить действие и дождаться результата.',
            'expected' => 'Раздел открывается без задержки, действие завершается и введённые данные сохраняются.',
            'actual' => 'После действия интерфейс остаётся в прежнем состоянии или показывает сообщение об ошибке.',
        ];
    }

    public function supportReply(DemoPersona $persona, string $subject, int $ordinal): string
    {
        return sprintf(
            'Проверили обращение «%s» и воспроизвели описанный сценарий. Передали результат ответственному разделу; о следующем изменении статуса сообщим здесь. %s',
            $subject,
            $this->fingerprints->clause('support', $persona->index * 10 + $ordinal),
        );
    }

    private function scope(DemoPersona $persona, string $domain, int $ordinal): string
    {
        return 'persona:'.$persona->index.':'.$domain.':'.$ordinal;
    }
}

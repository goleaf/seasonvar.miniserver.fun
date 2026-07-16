<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoPersona;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DemoPersonaFactory
{
    private const MALE_NAMES = [
        'Александр', 'Алексей', 'Анатолий', 'Андрей', 'Антон', 'Аркадий', 'Артём', 'Борис', 'Вадим', 'Валентин',
        'Валерий', 'Виктор', 'Виталий', 'Владимир', 'Владислав', 'Всеволод', 'Георгий', 'Глеб', 'Григорий', 'Даниил',
        'Денис', 'Дмитрий', 'Евгений', 'Егор', 'Иван', 'Игорь', 'Илья', 'Кирилл', 'Константин', 'Лев',
        'Леонид', 'Максим', 'Марк', 'Матвей', 'Михаил', 'Никита', 'Николай', 'Олег', 'Павел', 'Пётр',
        'Роман', 'Ростислав', 'Руслан', 'Семён', 'Сергей', 'Станислав', 'Степан', 'Тимофей', 'Фёдор', 'Юрий',
    ];

    private const FEMALE_NAMES = [
        'Александра', 'Алёна', 'Алина', 'Алла', 'Анастасия', 'Анна', 'Антонина', 'Валентина', 'Валерия', 'Варвара',
        'Вера', 'Вероника', 'Виктория', 'Галина', 'Дарья', 'Диана', 'Евгения', 'Екатерина', 'Елена', 'Елизавета',
        'Зинаида', 'Зоя', 'Инна', 'Ирина', 'Карина', 'Кира', 'Кристина', 'Ксения', 'Лариса', 'Лидия',
        'Любовь', 'Людмила', 'Маргарита', 'Марина', 'Мария', 'Надежда', 'Наталья', 'Нина', 'Оксана', 'Ольга',
        'Полина', 'Раиса', 'Светлана', 'София', 'Таисия', 'Тамара', 'Татьяна', 'Ульяна', 'Юлия', 'Яна',
    ];

    private const SURNAMES = [
        'Алексеев', 'Андреев', 'Антонов', 'Баранов', 'Белов', 'Беляев', 'Богданов', 'Борисов', 'Васильев', 'Виноградов',
        'Власов', 'Волков', 'Воробьёв', 'Гаврилов', 'Герасимов', 'Голубев', 'Григорьев', 'Громов', 'Давыдов', 'Данилов',
        'Демидов', 'Дмитриев', 'Егоров', 'Елисеев', 'Емельянов', 'Ершов', 'Жуков', 'Зайцев', 'Захаров', 'Иванов',
        'Ильин', 'Казаков', 'Калинин', 'Карпов', 'Киселёв', 'Ковалёв', 'Козлов', 'Комаров', 'Королёв', 'Крылов',
        'Кузнецов', 'Лебедев', 'Макаров', 'Мельников', 'Миронов', 'Морозов', 'Никитин', 'Орлов', 'Павлов', 'Поляков',
    ];

    private const CITIES = [
        'Архангельск', 'Астрахань', 'Великий Новгород', 'Владимир', 'Волгоград', 'Воронеж', 'Екатеринбург', 'Иркутск',
        'Казань', 'Калининград', 'Киров', 'Краснодар', 'Красноярск', 'Курск', 'Мурманск', 'Нижний Новгород',
        'Новосибирск', 'Омск', 'Оренбург', 'Пермь', 'Петрозаводск', 'Псков', 'Рязань', 'Самара', 'Саратов',
        'Смоленск', 'Сочи', 'Тверь', 'Томск', 'Тула', 'Тюмень', 'Уфа', 'Хабаровск', 'Челябинск', 'Ярославль',
    ];

    private const OCCUPATIONS = [
        'архитектор', 'библиотекарь', 'врач', 'географ', 'дизайнер', 'журналист', 'звукорежиссёр', 'инженер',
        'историк', 'картограф', 'книготорговец', 'лингвист', 'маркетолог', 'музейный сотрудник', 'переводчик',
        'преподаватель', 'программист', 'редактор', 'реставратор', 'социолог', 'специалист по данным', 'сценарист',
        'технолог', 'фотограф', 'художник', 'эколог', 'экономист', 'юрист', 'видеомонтажёр', 'радиоведущий',
    ];

    private const GENRES = [
        'драма', 'комедия', 'детектив', 'триллер', 'фантастика', 'фэнтези', 'мелодрама', 'приключения',
        'исторический', 'документальный', 'криминальный', 'мистика', 'семейный', 'биография', 'военный',
        'спорт', 'музыка', 'анимация', 'научный', 'путешествия',
    ];

    private const REVIEW_STYLES = [
        'аналитичный', 'эмоциональный', 'лаконичный', 'наблюдательный', 'сюжетный', 'актёрский', 'визуальный', 'музыкальный',
        'сравнительный', 'ироничный', 'спокойный', 'подробный',
    ];

    private const COMMENT_STYLES = [
        'дружелюбный', 'вдумчивый', 'деловой', 'живой', 'осторожный', 'открытый', 'вопросительный', 'поддерживающий',
        'дискуссионный', 'наблюдательный', 'краткий', 'развёрнутый',
    ];

    public function __construct(
        private DemoStableValue $stable,
        private DemoLexicalFingerprint $fingerprints,
    ) {}

    public function make(int $userIndex): DemoPersona
    {
        if ($userIndex < 1 || $userIndex > 100) {
            throw new InvalidArgumentException('Demo persona index must be between one and one hundred.');
        }

        $female = $userIndex > 50;
        $nameIndex = ($userIndex - 1) % 50;
        $givenName = ($female ? self::FEMALE_NAMES : self::MALE_NAMES)[$nameIndex];
        $familyName = self::SURNAMES[$nameIndex].($female ? 'а' : '');
        $displayName = $givenName.' '.$familyName;
        $username = Str::of($givenName.'.'.$familyName)->ascii()->lower()->toString();
        $city = $this->stable->pick('persona:'.$userIndex.':city', self::CITIES);
        $occupation = $this->stable->pick('persona:'.$userIndex.':occupation', self::OCCUPATIONS);
        $genres = $this->genres($userIndex);
        $genreSummary = implode(', ', $genres);
        $biography = sprintf(
            'Живу в городе %s, работаю как %s. В свободное время смотрю сериалы и обсуждаю их без спойлеров; чаще выбираю такие направления, как %s. %s',
            $city,
            $occupation,
            $genreSummary,
            $this->fingerprints->clause('biography', $userIndex - 1),
        );

        return new DemoPersona(
            index: $userIndex,
            givenName: $givenName,
            familyName: $familyName,
            displayName: $displayName,
            username: $username,
            biography: $biography,
            city: $city,
            occupation: $occupation,
            favoriteGenres: $genres,
            reviewStyle: $this->stable->pick('persona:'.$userIndex.':review-style', self::REVIEW_STYLES),
            commentStyle: $this->stable->pick('persona:'.$userIndex.':comment-style', self::COMMENT_STYLES),
        );
    }

    /** @return list<string> */
    private function genres(int $userIndex): array
    {
        $genres = [];
        $count = $this->stable->integer('persona:'.$userIndex.':genre-count', 2, 5);

        for ($ordinal = 0; count($genres) < $count; $ordinal++) {
            $genre = $this->stable->pick('persona:'.$userIndex.':genre:'.$ordinal, self::GENRES);

            if (! in_array($genre, $genres, true)) {
                $genres[] = $genre;
            }
        }

        return $genres;
    }
}

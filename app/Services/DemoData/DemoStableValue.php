<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class DemoStableValue
{
    public function __construct(private string $namespace) {}

    public function integer(string $scope, int $minimum, int $maximum): int
    {
        if ($maximum < $minimum) {
            throw new InvalidArgumentException('Maximum must be greater than or equal to minimum.');
        }

        $value = (int) hexdec(substr($this->hash($scope), 0, 12));

        return $minimum + ($value % ($maximum - $minimum + 1));
    }

    public function boolean(string $scope, int $percentage): bool
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new InvalidArgumentException('Percentage must be between zero and one hundred.');
        }

        return $percentage > 0 && $this->integer($scope, 1, 100) <= $percentage;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $values
     * @return TValue
     */
    public function pick(string $scope, array $values): mixed
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values must not be empty.');
        }

        return $values[$this->integer($scope, 0, count($values) - 1)];
    }

    public function uuid(string $scope): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $this->namespace.'|'.$scope)->toString();
    }

    public function hash(string $scope): string
    {
        return hash('sha256', $this->namespace.'|'.$scope);
    }

    public function date(string $scope, CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        if ($to->isBefore($from)) {
            throw new InvalidArgumentException('End date must not be before start date.');
        }

        $maximumSeconds = (int) floor($from->diffInSeconds($to));

        return $from->addSeconds($this->integer('date:'.$scope, 0, $maximumSeconds));
    }
}

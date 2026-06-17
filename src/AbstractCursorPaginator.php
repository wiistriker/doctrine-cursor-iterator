<?php

declare(strict_types=1);

namespace Wiistriker\DoctrineCursorPaginator;

use IteratorAggregate;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Traversable;
use Wiistriker\DoctrineCursorPaginator\Exception\InvalidArgumentException;

/**
 * @template T
 * @implements IteratorAggregate<int, T>
 */
abstract class AbstractCursorPaginator implements IteratorAggregate
{
    protected PropertyAccessorInterface $propertyAccessor;

    /** @var list<array{field: string, property: string, is_asc: bool}> */
    protected array $orderByProperties;

    protected int $orderByPropertiesCnt;
    protected int $maxResultsCnt;

    /**
     * @param list<array{field: string, property: string, is_asc: bool}> $orderByProperties
     */
    protected function __construct(
        int $maxResultsCnt,
        array $orderByProperties,
        ?PropertyAccessorInterface $propertyAccessor = null,
    ) {
        if ($orderByProperties === []) {
            throw new InvalidArgumentException('No order properties found. Please specify order properties by calling orderBy() or addOrderBy() method on query builder.');
        }

        if ($maxResultsCnt <= 0) {
            throw new InvalidArgumentException('Max results should be greater than zero.');
        }

        $this->orderByProperties = $orderByProperties;
        $this->orderByPropertiesCnt = count($orderByProperties);
        $this->maxResultsCnt = $maxResultsCnt;
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        $lastPropertiesValues = [];
        $endReached = false;

        do {
            $itemsCnt = 0;

            foreach ($this->executePageQuery($lastPropertiesValues) as $item) {
                foreach ($this->orderByProperties as $orderByProperty) {
                    $propertyPath = is_array($item)
                        ? '[' . $orderByProperty['property'] . ']'
                        : $orderByProperty['property'];

                    $lastPropertiesValues[$orderByProperty['property']] = $this->propertyAccessor->getValue(
                        $item,
                        $propertyPath
                    );
                }

                yield $item;
                $itemsCnt++;
            }

            if ($itemsCnt < $this->maxResultsCnt) {
                $endReached = true;
            }
        } while (!$endReached);
    }

    /**
     * Execute a single page query with optional cursor conditions.
     *
     * @param array<string, mixed> $lastPropertiesValues
     * @return iterable<int, T>
     */
    abstract protected function executePageQuery(array $lastPropertiesValues): iterable;

    /**
     * @return Traversable<int, list<T>>
     */
    public function batch(?int $size = null): Traversable
    {
        $size = $size ?? $this->maxResultsCnt;

        $batch = [];
        $batchSize = 0;
        foreach ($this->getIterator() as $item) {
            $batch[] = $item;
            $batchSize++;
            if ($batchSize >= $size) {
                yield $batch;
                $batch = [];
                $batchSize = 0;
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Build cursor comparison descriptors for keyset pagination, ordered from
     * the least significant sort key to the most significant one.
     *
     * @return list<array{operator: string, field: string, property: string}>
     */
    protected function buildComparisons(): array
    {
        $comparisons = [];
        for ($i = $this->orderByPropertiesCnt - 1; $i >= 0; $i--) {
            $orderByProperty = $this->orderByProperties[$i];
            $comparisons[] = [
                'operator' => $orderByProperty['is_asc'] ? '>' : '<',
                'field' => $orderByProperty['field'],
                'property' => $orderByProperty['property'],
            ];
        }

        return $comparisons;
    }
}

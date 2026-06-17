<?php

declare(strict_types=1);

namespace Wiistriker\DoctrineCursorPaginator;

use Doctrine\DBAL\Query\QueryBuilder;
use ReflectionObject;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Wiistriker\DoctrineCursorPaginator\Exception\InvalidArgumentException;

/**
 * @extends AbstractCursorPaginator<array<string, mixed>>
 */
class DoctrineDBALCursorPaginator extends AbstractCursorPaginator
{
    protected QueryBuilder $queryBuilder;

    /**
     * @param array<string, string>|null $orderBy Explicit order specification as
     *        ['column' => 'ASC'|'DESC', ...]. When omitted, the order is read from
     *        the query builder (which requires reflection on its internals).
     */
    public function __construct(
        QueryBuilder $queryBuilder,
        ?array $orderBy = null,
        ?PropertyAccessorInterface $propertyAccessor = null
    ) {
        $orderByProperties = $orderBy !== null
            ? $this->normalizeOrderBy($orderBy)
            : $this->extractOrderByFromQueryBuilder($queryBuilder);

        $maxResultsCnt = $queryBuilder->getMaxResults();
        if ($maxResultsCnt === null) {
            throw new InvalidArgumentException('No max results found. Please specify maxResultsCnt parameter by calling setMaxResults() method on query builder.');
        }

        parent::__construct($maxResultsCnt, $orderByProperties, $propertyAccessor);

        $this->queryBuilder = clone $queryBuilder;
    }

    /**
     * Normalize an explicit ['column' => 'ASC'|'DESC'] specification.
     *
     * @param array<string, string> $orderBy
     * @return list<array{field: string, property: string, is_asc: bool}>
     */
    protected function normalizeOrderBy(array $orderBy): array
    {
        $orderByProperties = [];
        foreach ($orderBy as $field => $direction) {
            $field = (string) $field;
            $directionUpper = strtoupper($direction);

            if ($directionUpper !== 'ASC' && $directionUpper !== 'DESC') {
                throw new InvalidArgumentException(sprintf(
                    'Invalid order direction "%s" for field "%s". Expected "ASC" or "DESC".',
                    $direction,
                    $field
                ));
            }

            $orderByProperties[] = [
                'field' => $field,
                'property' => $field,
                'is_asc' => $directionUpper === 'ASC',
            ];
        }

        return $orderByProperties;
    }

    /**
     * Read the order specification from the query builder internals.
     *
     * DBAL exposes no public getter for the ORDER BY clause, so reflection is
     * the only option here. Pass the $orderBy constructor argument to avoid it.
     *
     * @return list<array{field: string, property: string, is_asc: bool}>
     */
    protected function extractOrderByFromQueryBuilder(QueryBuilder $queryBuilder): array
    {
        $reflection = new ReflectionObject($queryBuilder);

        if ($reflection->hasProperty('sqlParts')) {
            $sqlPartsProperty = $reflection->getProperty('sqlParts');
            $sqlPartsProperty->setAccessible(true);

            /** @var array<string, mixed> $sqlParts */
            $sqlParts = $sqlPartsProperty->getValue($queryBuilder);
            $orderByValues = $sqlParts['orderBy'] ?? [];
        } else {
            $orderByProperty = $reflection->getProperty('orderBy');
            $orderByProperty->setAccessible(true);

            $orderByValues = $orderByProperty->getValue($queryBuilder);
        }

        $orderByProperties = [];
        /** @var iterable<string> $orderByValues */
        foreach ($orderByValues as $orderByPart) {
            if (preg_match('/^([a-z0-9_.]+)\s+(ASC|DESC)$/i', $orderByPart, $matches)) {
                $orderByProperties[] = [
                    'field' => $matches[1],
                    'property' => $matches[1],
                    'is_asc' => mb_strtolower($matches[2], 'utf-8') === 'asc',
                ];
            }
        }

        return $orderByProperties;
    }

    /**
     * @param array<string, mixed> $lastPropertiesValues
     * @return iterable<int, array<string, mixed>>
     */
    protected function executePageQuery(array $lastPropertiesValues): iterable
    {
        $cursorQb = clone $this->queryBuilder;

        if ($lastPropertiesValues) {
            $expr = $cursorQb->expr();

            $nested = null;
            foreach ($this->buildComparisons() as $comparison) {
                $expression = $comparison['field'] . ' ' . $comparison['operator'] . ' :' . $comparison['property'];

                if ($nested === null) {
                    $nested = $expression;
                } else {
                    $nested = $expr->or(
                        $expression,
                        $expr->and($expr->eq($comparison['field'], ':' . $comparison['property']), $nested)
                    );
                }

                $cursorQb->setParameter($comparison['property'], $lastPropertiesValues[$comparison['property']]);
            }

            $cursorQb->andWhere($nested);
        }

        if (method_exists($cursorQb, 'executeQuery')) {
            $stmt = $cursorQb->executeQuery();
        } else {
            $stmt = $cursorQb->execute();
        }

        if (method_exists($stmt, 'fetchAllAssociative')) {
            $results = $stmt->fetchAllAssociative();
        } else {
            $results = $stmt->fetchAll();
        }

        yield from $results;
    }
}

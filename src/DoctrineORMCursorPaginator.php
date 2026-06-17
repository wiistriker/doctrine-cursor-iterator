<?php

declare(strict_types=1);

namespace Wiistriker\DoctrineCursorPaginator;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query\Expr;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Wiistriker\DoctrineCursorPaginator\Exception\InvalidArgumentException;

/**
 * @template T
 * @extends AbstractCursorPaginator<T>
 */
class DoctrineORMCursorPaginator extends AbstractCursorPaginator
{
    protected QueryBuilder $queryBuilder;
    protected int $hydrationMode;

    /** @var array<string, mixed> */
    protected array $queryHints;

    /**
     * @param array<string, mixed> $queryHints
     */
    public function __construct(
        QueryBuilder $queryBuilder,
        int $hydrationMode = AbstractQuery::HYDRATE_OBJECT,
        array $queryHints = [],
        ?PropertyAccessorInterface $propertyAccessor = null
    ) {
        $orderByProperties = [];
        foreach ($queryBuilder->getDQLPart('orderBy') as $orderByPart) {
            $orderByPartFirst = $orderByPart->getParts()[0];
            if (preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)\s+(ASC|DESC)$/i', $orderByPartFirst, $matches)) {
                $orderByProperties[] = [
                    'field' => $matches[1] . '.' . $matches[2],
                    'property' => $matches[2],
                    'is_asc' => mb_strtolower($matches[3], 'utf-8') === 'asc',
                ];
            }
        }

        $maxResultsCnt = $queryBuilder->getMaxResults();
        if ($maxResultsCnt === null) {
            throw new InvalidArgumentException('No max results found. Please specify maxResultsCnt parameter by calling setMaxResults() method on query builder.');
        }

        parent::__construct($maxResultsCnt, $orderByProperties, $propertyAccessor);

        $this->queryBuilder = clone $queryBuilder;
        $this->hydrationMode = $hydrationMode;
        $this->queryHints = $queryHints;
    }

    /**
     * @param array<string, mixed> $lastPropertiesValues
     * @return iterable<int, T>
     */
    protected function executePageQuery(array $lastPropertiesValues): iterable
    {
        $cursorQb = clone $this->queryBuilder;

        if ($lastPropertiesValues) {
            $expr = $cursorQb->expr();

            $nested = null;
            foreach ($this->buildComparisons() as $comparison) {
                $expression = new Expr\Comparison(
                    $comparison['field'],
                    $comparison['operator'],
                    ':' . $comparison['property']
                );

                if ($nested === null) {
                    $nested = $expression;
                } else {
                    $nested = $expr->orX(
                        $expression,
                        $expr->andX($expr->eq($comparison['field'], ':' . $comparison['property']), $nested)
                    );
                }

                $cursorQb->setParameter($comparison['property'], $lastPropertiesValues[$comparison['property']]);
            }

            $cursorQb->andWhere($nested);
        }

        $cursorQuery = $cursorQb->getQuery();
        foreach ($this->queryHints as $hintName => $hintValue) {
            $cursorQuery->setHint($hintName, $hintValue);
        }

        yield from $cursorQuery->getResult($this->hydrationMode);
    }
}

<?php

namespace Wiistriker;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use IteratorAggregate;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Doctrine\ORM\Query\Expr;
use Traversable;
use Wiistriker\Exception\InvalidArgumentException;

/**
 * @template T
 */
class DoctrineORMCursorPaginator implements IteratorAggregate
{
    protected QueryBuilder $queryBuilder;
    protected int $hydrationMode;
    protected array $queryHints;
    protected PropertyAccessorInterface $propertyAccessor;

    protected array $orderByProperties;
    protected int $orderByPropertiesCnt;
    protected int $maxResultsCnt;

    public function __construct(
        QueryBuilder $queryBuilder,
        int $hydrationMode = AbstractQuery::HYDRATE_OBJECT,
        array $queryHints = [],
        PropertyAccessorInterface $propertyAccessor = null
    ) {
        $orderByProperties = [];
        $orderByPropertiesCnt = 0;
        foreach ($queryBuilder->getDQLPart('orderBy') as $orderByPart) {
            $orderByPartFirst = $orderByPart->getParts()[0];
            if (preg_match('/^([a-z0-9_]*)\.([a-z0-9_]*)\s+(ASC|DESC)$/i', $orderByPartFirst, $matches)) {
                $orderField = $matches[1] . '.' . $matches[2];

                $orderByProperties[] = [
                    'field' => $orderField,
                    'property' => $matches[2],
                    'is_asc' => mb_strtolower($matches[3], 'utf-8') === 'asc'
                ];

                $orderByPropertiesCnt++;
            }
        }

        if ($orderByPropertiesCnt === 0) {
            throw new InvalidArgumentException('No order properties found. Please specify order properties by calling orderBy() or addOrderBy() method on query builder.');
        }

        $maxResultsCnt = $queryBuilder->getMaxResults();

        if ($maxResultsCnt === null) {
            throw new InvalidArgumentException('No max results found. Please specify maxResultsCnt parameter by calling setMaxResults() method on query builder.');
        }

        if ($maxResultsCnt <= 0) {
            throw new InvalidArgumentException('Max results should be greater than zero.');
        }

        $this->queryBuilder = clone($queryBuilder);
        $this->hydrationMode = $hydrationMode;
        $this->queryHints = $queryHints;
        $this->propertyAccessor = $propertyAccessor ?: PropertyAccess::createPropertyAccessor();

        $this->orderByProperties = $orderByProperties;
        $this->orderByPropertiesCnt = $orderByPropertiesCnt;
        $this->maxResultsCnt = $maxResultsCnt;
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        $lastPropertiesValues = [];
        $endReached = false;

        do {
            $cursorQb = clone($this->queryBuilder);

            if ($lastPropertiesValues) {
                $expr = $cursorQb->expr();

                $nested = null;
                for ($i = $this->orderByPropertiesCnt - 1; $i >= 0; $i--) {
                    $orderByProperty = $this->orderByProperties[$i];

                    $comparison = new Expr\Comparison(
                        $orderByProperty['field'],
                        $orderByProperty['is_asc'] ? Expr\Comparison::GT : Expr\Comparison::LT,
                        ':' . $orderByProperty['property']
                    );

                    if ($nested === null) {
                        $nested = $comparison;
                    } else {
                        $nested = $expr->orX($comparison, $expr->andX($expr->eq($orderByProperty['field'], ':' . $orderByProperty['property']), $nested));
                    }

                    $cursorQb->setParameter($orderByProperty['property'], $lastPropertiesValues[$orderByProperty['property']]);
                }

                $cursorQb->andWhere($nested);
            }

            $cursorQuery = $cursorQb->getQuery();
            foreach ($this->queryHints as $hintName => $hintValue) {
                $cursorQuery->setHint($hintName, $hintValue);
            }

            $itemsCnt = 0;
            foreach ($cursorQuery->getResult($this->hydrationMode) as $item) {
                foreach ($this->orderByProperties as $orderByProperty) {
                    $property_path = is_array($item) ? '[' . $orderByProperty['property'] . ']' : $orderByProperty['property'];
                    $lastPropertiesValues[$orderByProperty['property']] = $this->propertyAccessor->getValue($item, $property_path);
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
     * @return Traversable<int, T[]>
     */
    public function batch(?int $size = null): Traversable
    {
        $size = $size ?? $this->queryBuilder->getMaxResults();

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

        if (!empty($batch)) {
            yield $batch;
        }
    }
}

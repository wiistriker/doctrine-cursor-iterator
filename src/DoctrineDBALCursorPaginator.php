<?php

namespace Wiistriker;

use Doctrine\DBAL\Query\QueryBuilder;
use IteratorAggregate;
use ReflectionObject;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Traversable;
use Wiistriker\Exception\InvalidArgumentException;

class DoctrineDBALCursorPaginator implements IteratorAggregate
{
    protected QueryBuilder $queryBuilder;
    protected PropertyAccessorInterface $propertyAccessor;

    protected array $orderByProperties;
    protected int $orderByPropertiesCnt;
    protected int $maxResultsCnt;

    public function __construct(
        QueryBuilder $queryBuilder,
        PropertyAccessorInterface $propertyAccessor = null
    ) {
        $queryBuilderReflection = new ReflectionObject($queryBuilder);
        $orderByProperty = $queryBuilderReflection->getProperty('orderBy');
        $orderByProperty->setAccessible(true);

        $orderByValues = $orderByProperty->getValue($queryBuilder);

        $orderByProperties = [];
        $orderByPropertiesCnt = 0;
        foreach ($orderByValues as $orderByPart) {
            if (preg_match('/^([a-z0-9_.]*)\s+(ASC|DESC)$/i', $orderByPart, $matches)) {
                $orderField = $matches[1];

                $orderByProperties[] = [
                    'field' => $orderField,
                    'property' => $matches[1],
                    'is_asc' => mb_strtolower($matches[2], 'utf-8') === 'asc'
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
        $this->propertyAccessor = $propertyAccessor ?: PropertyAccess::createPropertyAccessor();

        $this->orderByProperties = $orderByProperties;
        $this->orderByPropertiesCnt = $orderByPropertiesCnt;
        $this->maxResultsCnt = $maxResultsCnt;
    }

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

                    $comparison = $orderByProperty['field'] . ' ' . ($orderByProperty['is_asc'] ? '>' : '<') . ' :' . $orderByProperty['property'];

                    if ($nested === null) {
                        $nested = $comparison;
                    } else {
                        $nested = $expr->or($comparison, $expr->and($expr->eq($orderByProperty['field'], ':' . $orderByProperty['property']), $nested));
                    }

                    $cursorQb->setParameter($orderByProperty['property'], $lastPropertiesValues[$orderByProperty['property']]);
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

            $itemsCnt = 0;
            foreach ($results as $item) {
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

<?php

namespace Wiistriker;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use IteratorAggregate;
use RuntimeException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Doctrine\ORM\Query\Expr;
use Traversable;

/**
 * @template T
 */
class DoctrineCursorIterator implements IteratorAggregate
{
    protected QueryBuilder $qb;
    protected int $hydrationMode;
    protected array $queryHints;
    protected PropertyAccessorInterface $propertyAccessor;

    public function __construct(
        QueryBuilder $qb,
        int $hydrationMode = AbstractQuery::HYDRATE_OBJECT,
        array $queryHints = [],
        PropertyAccessorInterface $propertyAccessor = null
    ) {
        $this->qb = $qb;
        $this->hydrationMode = $hydrationMode;
        $this->queryHints = $queryHints;
        $this->propertyAccessor = $propertyAccessor ?: PropertyAccess::createPropertyAccessor();
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        $last_properties_values = [];
        $end_reached = false;

        $order_by_properties = [];
        $order_by_properties_cnt = 0;
        foreach ($this->qb->getDQLPart('orderBy') as $orderByPart) {
            $order_by_part = $orderByPart->getParts()[0];
            if (preg_match('/^([a-z0-9_]*)\.([a-z0-9_]*)\s+(ASC|DESC)$/i', $order_by_part, $matches)) {
                $order_field = $matches[1] . '.' . $matches[2];

                $order_by_properties[] = [
                    'field' => $order_field,
                    'property' => $matches[2],
                    'is_asc' => mb_strtolower($matches[3], 'utf-8') === 'asc'
                ];

                $order_by_properties_cnt++;
            }
        }

        if ($order_by_properties_cnt === 0) {
            throw new RuntimeException('No order properties found');
        }

        $max_results_cnt = $this->qb->getMaxResults();

        if ($max_results_cnt === null) {
            throw new RuntimeException('No max results found');
        }

        do {
            $cursorQb = clone($this->qb);

            if ($last_properties_values) {
                $expr = $cursorQb->expr();

                $nested = null;
                for ($i = $order_by_properties_cnt - 1; $i >= 0; $i--) {
                    $orderByProperty = $order_by_properties[$i];

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

                    $cursorQb->setParameter($orderByProperty['property'], $last_properties_values[$orderByProperty['property']]);
                }

                $cursorQb->andWhere($nested);
            }

            $cursorQuery = $cursorQb->getQuery();
            foreach ($this->queryHints as $hint_name => $hint_value) {
                $cursorQuery->setHint($hint_name, $hint_value);
            }

            $items_cnt = 0;
            foreach ($cursorQuery->getResult($this->hydrationMode) as $item) {
                foreach ($order_by_properties as $orderByProperty) {
                    $property_path = is_array($item) ? '[' . $orderByProperty['property'] . ']' : $orderByProperty['property'];
                    $last_properties_values[$orderByProperty['property']] = $this->propertyAccessor->getValue($item, $property_path);
                }

                yield $item;

                $items_cnt++;
            }

            if ($items_cnt < $max_results_cnt) {
                $end_reached = true;
            }
        } while (!$end_reached);
    }

    /**
     * @return Traversable<int, T[]>
     */
    public function batch(?int $size = null): Traversable
    {
        $size = $size ?? $this->qb->getMaxResults();

        $batch = [];
        $batch_size = 0;
        foreach ($this->getIterator() as $item) {
            $batch[] = $item;
            $batch_size++;
            if ($batch_size >= $size) {
                yield $batch;
                $batch = [];
                $batch_size = 0;
            }
        }

        if (!empty($batch)) {
            yield $batch;
        }
    }
}

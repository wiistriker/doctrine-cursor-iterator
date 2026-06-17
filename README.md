# Doctrine ORM and DBAL Cursor Paginator for large datasets

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Total Downloads][ico-downloads]][link-downloads]

Iterate through large database results with easy

## Installation

```
composer require wiistriker/doctrine-cursor-paginator
```

## Usage for ORM

Create query builder as usual. Dont forget about `orderBy` and `maxResults`.

```php
$testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
$qb = $testEntityRepository->createQueryBuilder('t')
    ->orderBy('t.id', 'ASC')
    ->setMaxResults(100)
;

/** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
$cursorPaginator = new DoctrineORMCursorPaginator($qb);

foreach ($cursorPaginator as $testEntity) {
    //...
}
```

DoctrineORMCursorPaginator fetches only 100 records per query, so it never loads the whole dataset into memory at once
and can efficiently iterate through even large datasets. See [Memory and the EntityManager](#memory-and-the-entitymanager)
below for an important caveat about object hydration.

First sql:

```SELECT ... FROM table ORDER BY id ASC LIMIT 100```

Next:

```SELECT ... FROM table WHERE id > {$id_from_last_record} ORDER BY id ASC LIMIT 100```

You can also specify more order by fields

```php
$testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
$qb = $testEntityRepository->createQueryBuilder('t')
    ->select('t.id', 't.createdAt')
    ->orderBy('t.createdAt', 'DESC')
    ->addOrderBy('t.id', 'DESC')
    ->setMaxResults(100)
;

/** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
$cursorPaginator = new DoctrineORMCursorPaginator($qb);

foreach ($cursorPaginator as $testEntity) {
    //...
}
```

You can change hydration mode

```php
$cursorPaginator = new DoctrineORMCursorPaginator($qb, AbstractQuery::HYDRATE_ARRAY);
```

And even set query hints

```php
$cursorPaginator = new DoctrineORMCursorPaginator(
    queryBuilder: $qb,
    queryHints: [
        'fetchMode' => [
            TestEntity::class => [
                'field' => ClassMetadataInfo::FETCH_EAGER
            ]
        ]
    ]
);
```

You wanna batch? Lets batch:

```php
$cursorPaginator = new DoctrineORMCursorPaginator($qb);

foreach ($cursorPaginator->batch() as $entities) {
    foreach ($entities as $testEntity) {
        $cnt++;
    }
}
```

By default batch size equals to `maxResults` but you can also specify desired amount by yourself:

```php
$my_batch_size = 1000;

$cursorPaginator = new DoctrineORMCursorPaginator($qb);

foreach ($cursorPaginator->batch($my_batch_size) as $entities) {
}
```

### Memory and the EntityManager

The paginator limits how many rows each query returns, but with the default object hydration
(`HYDRATE_OBJECT`) Doctrine keeps every hydrated entity in the EntityManager's identity map. Over a large
dataset that map keeps growing, so the per-query limit alone does **not** keep memory flat. When you iterate
over many entities, clear the EntityManager periodically (batching makes a natural place to do it):

```php
foreach ($cursorPaginator->batch() as $entities) {
    foreach ($entities as $entity) {
        // ... process the entity
    }

    $entityManager->clear(); // detach processed entities and free memory
}
```

Keep in mind that `clear()` detaches **all** managed entities: flush any pending changes before calling it,
and don't keep references to entities you still expect to be managed. If you don't need managed objects at all,
array hydration avoids the identity map entirely and sidesteps the issue:

```php
$cursorPaginator = new DoctrineORMCursorPaginator($qb, AbstractQuery::HYDRATE_ARRAY);
```

## Usage for DBAL

Just use `DoctrineDBALCursorPaginator` instead.

```php
$queryBuilder = $this->connection->createQueryBuilder();

$queryBuilder
    ->select('id', 'name')
    ->from('test')
    ->orderBy('id', 'ASC')
    ->setMaxResults(100)
;

$cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder);

foreach ($cursorPaginator as $row) {
}
```

DBAL exposes no public getter for the `ORDER BY` clause, so by default the order
is read from the query builder via reflection. If you prefer to avoid reflection
(or your DBAL version changes its internals), pass the order explicitly. It must
mirror the `orderBy()`/`addOrderBy()` calls on the query builder:

```php
$queryBuilder
    ->select('id', 'name')
    ->from('test')
    ->orderBy('id', 'ASC')
    ->setMaxResults(100)
;

$cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder, ['id' => 'ASC']);
```

[ico-version]: https://img.shields.io/packagist/v/wiistriker/doctrine-cursor-paginator.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/wiistriker/doctrine-cursor-paginator.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/wiistriker/doctrine-cursor-paginator
[link-downloads]: https://packagist.org/packages/wiistriker/doctrine-cursor-paginator

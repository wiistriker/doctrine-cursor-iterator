# Doctrine ORM and DBAL Cursor Paginator for large datasets

Iterate through large database results with easy

## Usage for ORM

Create query builder as usual. Dont forget about `orderBy` and `maxResults`.

```php
$testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
$qb = $testEntityRepository->createQueryBuilder('t')
    ->orderBy('t.id', 'ASC')
    ->setMaxResults(100)
;

$cursorIterator = new DoctrineORMCursorPaginator($qb);

foreach ($cursorIterator as $testEntity) {
    //...
}
```

DoctrineORMCursorPaginator will hold only 100 records in memory to prevent memory leaks and efficiently iterate through
even large datasets.

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

$cursorIterator = new DoctrineCursorIterator($qb);

foreach ($cursorIterator as $testEntity) {
    //...
}
```

You can change hydration mode

```php
$cursorIterator = new DoctrineCursorIterator($qb, AbstractQuery::HYDRATE_ARRAY);
```

And even set query hints

```php
$cursorIterator = new DoctrineCursorIterator(
    qb: $qb,
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

$cnt = 0;
foreach ($cursorPaginator as $row) {
    $cnt++;
}
```

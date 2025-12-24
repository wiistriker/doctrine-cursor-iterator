<?php

namespace Tests;

use DateTime;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use ColinODell\PsrTestLogger\TestLogger;
use Tests\Entity\TestEntity;
use Wiistriker\DoctrineORMCursorPaginator;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;

class DoctrineORMCursorPaginatorTest extends TestCase
{
    private ?EntityManager $entityManager;
    private TestLogger $queryLogger;

    public function testWithId(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $entity = new TestEntity('test' . $i, new DateTime('+' . $i . ' seconds'), new DateTime('+' . ($i * 2) . ' seconds'));
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $this->queryLogger->reset();

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(100)
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);

        $cnt = 0;
        foreach ($cursorPaginator as $testEntity) {
            $cnt++;
        }

        $this->assertEquals(1234, $cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT t0_.id AS id_0 FROM TestEntity t0_ ORDER BY t0_.id ASC LIMIT 100', $query_logs[0]['context']['sql']);
        $this->assertArrayNotHasKey('params', $query_logs[0]['context']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT t0_.id AS id_0 FROM TestEntity t0_ WHERE t0_.id > ? ORDER BY t0_.id ASC LIMIT 100', $query_logs[$i]['context']['sql']);
            $this->assertEquals($i * 100, $query_logs[$i]['context']['params']['1']);
        }
    }

    public function testWithIdAndCreatedAt(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $entity = new TestEntity('test' . $i, new DateTime('+' . $i . ' seconds'), new DateTime('+' . ($i * 2) . ' seconds'));
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $this->queryLogger->reset();

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id', 't.createdAt')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(100)
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);

        $cnt = 0;
        foreach ($cursorPaginator as $testEntity) {
            $cnt++;
        }

        $this->assertEquals(1234, $cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT t0_.id AS id_0, t0_.createdAt AS createdAt_1 FROM TestEntity t0_ ORDER BY t0_.createdAt DESC, t0_.id DESC LIMIT 100', $query_logs[0]['context']['sql']);
        $this->assertArrayNotHasKey('params', $query_logs[0]['context']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT t0_.id AS id_0, t0_.createdAt AS createdAt_1 FROM TestEntity t0_ WHERE t0_.createdAt < ? OR (t0_.createdAt = ? AND t0_.id < ?) ORDER BY t0_.createdAt DESC, t0_.id DESC LIMIT 100', $query_logs[$i]['context']['sql']);
        }
    }

    public function testAsBatch(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $entity = new TestEntity('test' . $i, new DateTime('+' . $i . ' seconds'), new DateTime('+' . ($i * 2) . ' seconds'));
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $this->queryLogger->reset();

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id', 't.createdAt')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(100)
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);

        $cnt = 0;
        $batch_cnt = 0;
        foreach ($cursorPaginator->batch() as $testEntities) {
            $batch_cnt++;
            foreach ($testEntities as $testEntity) {
                $cnt++;
            }
        }

        $this->assertEquals(1234, $cnt);
        $this->assertEquals(13, $batch_cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT t0_.id AS id_0, t0_.createdAt AS createdAt_1 FROM TestEntity t0_ ORDER BY t0_.createdAt DESC, t0_.id DESC LIMIT 100', $query_logs[0]['context']['sql']);
        $this->assertArrayNotHasKey('params', $query_logs[0]['context']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT t0_.id AS id_0, t0_.createdAt AS createdAt_1 FROM TestEntity t0_ WHERE t0_.createdAt < ? OR (t0_.createdAt = ? AND t0_.id < ?) ORDER BY t0_.createdAt DESC, t0_.id DESC LIMIT 100', $query_logs[$i]['context']['sql']);
        }
    }

    public function testAsBatchWithExplicitSize(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $entity = new TestEntity('test' . $i, new DateTime('+' . $i . ' seconds'), new DateTime('+' . ($i * 2) . ' seconds'));
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();

        $this->queryLogger->reset();

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id', 't.createdAt')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(100)
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);

        $cnt = 0;
        $batch_cnt = 0;
        foreach ($cursorPaginator->batch(10) as $testEntities) {
            $batch_cnt++;
            foreach ($testEntities as $testEntity) {
                $cnt++;
            }
        }

        $this->assertEquals(1234, $cnt);
        $this->assertEquals(124, $batch_cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT t0_.id AS id_0, t0_.createdAt AS createdAt_1 FROM TestEntity t0_ ORDER BY t0_.createdAt DESC, t0_.id DESC LIMIT 100', $query_logs[0]['context']['sql']);
        $this->assertArrayNotHasKey('params', $query_logs[0]['context']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT t0_.id AS id_0, t0_.createdAt AS createdAt_1 FROM TestEntity t0_ WHERE t0_.createdAt < ? OR (t0_.createdAt = ? AND t0_.id < ?) ORDER BY t0_.createdAt DESC, t0_.id DESC LIMIT 100', $query_logs[$i]['context']['sql']);
        }
    }

    public function testWithoutOrderBy(): void
    {
        $this->expectExceptionMessage('No order properties found');

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id', 't.createdAt')
            ->setMaxResults(100)
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);
    }

    public function testWithoutMaxResults(): void
    {
        $this->expectExceptionMessage('No max results found');

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id', 't.createdAt')
            ->orderBy('t.createdAt', 'DESC')
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);
    }

    public function testWithNegativeMaxResults(): void
    {
        $this->expectExceptionMessage('Max results should be greater than zero.');

        $testEntityRepository = $this->entityManager->getRepository(TestEntity::class);
        $qb = $testEntityRepository->createQueryBuilder('t')
            ->select('t.id', 't.createdAt')
            ->setMaxResults(-100)
            ->orderBy('t.createdAt', 'DESC')
        ;

        /** @var DoctrineORMCursorPaginator<TestEntity> $cursorPaginator */
        $cursorPaginator = new DoctrineORMCursorPaginator($qb);
    }

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [ __DIR__ . '/Entity' ],
            true
        );

        $this->queryLogger = new TestLogger();
        $config->setMiddlewares([new LoggingMiddleware($this->queryLogger)]);

        $connection = DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true
            ],
            $config
        );

        $this->entityManager = new EntityManager($connection, $config);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);

        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager !== null && $this->entityManager->isOpen()) {
            $conn = $this->entityManager->getConnection();
            $this->entityManager->close();
            $conn->close();
        }

        $this->entityManager = null;
        $this->queryLogger->reset();
    }
}

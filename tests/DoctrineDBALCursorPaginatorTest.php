<?php

namespace Tests;

use ColinODell\PsrTestLogger\TestLogger;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Wiistriker\DoctrineCursorPaginator\DoctrineDBALCursorPaginator;

class DoctrineDBALCursorPaginatorTest extends TestCase
{
    private ?Connection $connection;
    private TestLogger $queryLogger;

    public function testWithId(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $this->connection->insert('test', [
                'id' => ($i + 1),
                'created_at' => (new DateTime('+' . $i . ' seconds'))->format('Y-m-d H:i:s'),
                'name' => 'test_' . $i
            ]);
        }

        $this->queryLogger->reset();

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

        $this->assertEquals(1234, $cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT id, name FROM test ORDER BY id ASC LIMIT 100', $query_logs[0]['context']['sql']);
        $this->assertArrayNotHasKey('params', $query_logs[0]['context']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT id, name FROM test WHERE id > ? ORDER BY id ASC LIMIT 100', $query_logs[$i]['context']['sql']);
            $this->assertEquals($i * 100, $query_logs[$i]['context']['params']['1']);
        }
    }

    public function testWithIdAndCreatedAt(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $this->connection->insert('test', [
                'id' => ($i + 1),
                'created_at' => (new DateTime('+' . $i . ' seconds'))->format('Y-m-d H:i:s'),
                'name' => 'test_' . $i
            ]);
        }

        $this->queryLogger->reset();

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder
            ->select('id', 'name', 'created_at')
            ->from('test')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(100)
        ;

        $cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder);

        $cnt = 0;
        foreach ($cursorPaginator as $row) {
            $cnt++;
        }

        $this->assertEquals(1234, $cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT id, name, created_at FROM test ORDER BY created_at DESC, id DESC LIMIT 100', $query_logs[0]['context']['sql']);
        $this->assertArrayNotHasKey('params', $query_logs[0]['context']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT id, name, created_at FROM test WHERE (created_at < ?) OR ((created_at = ?) AND (id < ?)) ORDER BY created_at DESC, id DESC LIMIT 100', $query_logs[$i]['context']['sql']);
        }
    }

    public function testWithExplicitOrderBy(): void
    {
        for ($i = 0; $i < 1234; $i++) {
            $this->connection->insert('test', [
                'id' => ($i + 1),
                'created_at' => (new DateTime('+' . $i . ' seconds'))->format('Y-m-d H:i:s'),
                'name' => 'test_' . $i
            ]);
        }

        $this->queryLogger->reset();

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder
            ->select('id', 'name')
            ->from('test')
            ->orderBy('id', 'ASC')
            ->setMaxResults(100)
        ;

        // Order is passed explicitly so the paginator does not need to read it
        // from the query builder internals via reflection.
        $cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder, ['id' => 'ASC']);

        $cnt = 0;
        foreach ($cursorPaginator as $row) {
            $cnt++;
        }

        $this->assertEquals(1234, $cnt);
        $this->assertCount(13, $this->queryLogger->records);

        $query_logs = $this->queryLogger->records;

        $this->assertEquals('SELECT id, name FROM test ORDER BY id ASC LIMIT 100', $query_logs[0]['context']['sql']);

        for ($i = 1; $i < sizeof($query_logs); $i++) {
            $this->assertEquals('SELECT id, name FROM test WHERE id > ? ORDER BY id ASC LIMIT 100', $query_logs[$i]['context']['sql']);
            $this->assertEquals($i * 100, $query_logs[$i]['context']['params']['1']);
        }
    }

    /**
     * Documents a known limitation: keyset pagination requires a deterministic
     * (unique) order. When the only sort key is non-unique, rows sharing the
     * boundary value get skipped by the strict ">" comparison, so the paginator
     * silently iterates over fewer rows than exist.
     *
     * 250 rows split into groups of 60 by `name` (grp_0..grp_4). With a page
     * size of 100 each page boundary falls inside a group, dropping the 20
     * remaining rows of that group:
     *   page 1: grp_0(60) + grp_1(40), then `name > 'grp_1'` skips 20 of grp_1
     *   page 2: grp_2(60) + grp_3(40), then `name > 'grp_3'` skips 20 of grp_3
     *   page 3: grp_4(10)
     * => 210 iterated instead of 250.
     *
     * TODO: replace this with an exception once non-unique ordering is rejected.
     */
    public function testKeysetBreaksOnNonUniqueOrdering(): void
    {
        $total = 250;
        for ($i = 0; $i < $total; $i++) {
            $this->connection->insert('test', [
                'id' => ($i + 1),
                'created_at' => (new DateTime('+' . $i . ' seconds'))->format('Y-m-d H:i:s'),
                'name' => 'grp_' . intdiv($i, 60)
            ]);
        }

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder
            ->select('id', 'name')
            ->from('test')
            ->orderBy('name', 'ASC')
            ->setMaxResults(100)
        ;

        $cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder);

        $cnt = 0;
        foreach ($cursorPaginator as $row) {
            $cnt++;
        }

        $this->assertLessThan($total, $cnt);
        $this->assertEquals(210, $cnt);
    }

    public function testWithoutOrderBy(): void
    {
        $this->expectExceptionMessage('No order properties found');

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder
            ->select('id', 'name', 'created_at')
            ->from('test')
            ->setMaxResults(100)
        ;

        $cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder);
    }

    public function testWithoutMaxResults(): void
    {
        $this->expectExceptionMessage('No max results found');

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder
            ->select('id', 'name', 'created_at')
            ->from('test')
            ->orderBy('id', 'DESC')
        ;

        $cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder);
    }

    public function testWithNegativeMaxResults(): void
    {
        $this->expectExceptionMessage('Max results should be greater than zero.');

        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder
            ->select('id', 'name', 'created_at')
            ->from('test')
            ->orderBy('id', 'DESC')
            ->setMaxResults(-1)
        ;

        $cursorPaginator = new DoctrineDBALCursorPaginator($queryBuilder);
    }

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([], true);

        $this->queryLogger = new TestLogger();
        $config->setMiddlewares([new LoggingMiddleware($this->queryLogger)]);

        $connection = DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true
            ],
            $config
        );

        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `test` (`id` int(11) NOT NULL, `name` varchar(32) NOT NULL, `created_at` datetime DEFAULT NULL, PRIMARY KEY (`id`))');

        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $this->connection->close();

        unset($this->connection);
        $this->connection = null;
        $this->queryLogger->reset();
    }
}

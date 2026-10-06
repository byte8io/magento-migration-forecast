<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Test\Unit\Model;

use Byte8\MigrationForecast\Model\Classifier;
use Byte8\MigrationForecast\Model\Data\SchemaChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassifierTest extends TestCase
{
    private Classifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new Classifier();
    }

    /**
     * @dataProvider matrixProvider
     */
    #[DataProvider('matrixProvider')]
    public function testClassifiesAgainstOnlineDdlMatrix(
        string $kind,
        array $context,
        string $algorithm,
        bool $rowScaled,
        bool $rebuild,
        bool $blocks
    ): void {
        $change = $this->classifier->classify('acme_widget', $kind, 'thing', $context);

        self::assertSame($algorithm, $change->algorithm, 'algorithm');
        self::assertSame($rowScaled, $change->rowScaled, 'row scaled');
        self::assertSame($rebuild, $change->rebuildsTable, 'rebuilds table');
        self::assertSame($blocks, $change->blocksWrites, 'blocks writes');
    }

    public static function matrixProvider(): array
    {
        $varchar = static fn (int $length, array $extra = []): array => $extra + [
            'type' => 'varchar',
            'nullable' => true,
            'default' => null,
            'length' => $length,
            'comment' => 'Label',
        ];
        $int = ['type' => 'int', 'nullable' => false, 'default' => 0, 'unsigned' => true, 'comment' => 'Qty'];

        return [
            'new table' => [SchemaChange::KIND_NEW_TABLE, [], 'metadata', false, false, false],
            'drop table' => [SchemaChange::KIND_DROP_TABLE, [], 'metadata', false, false, false],
            'add column' => [SchemaChange::KIND_ADD_COLUMN, [], 'instant', false, false, false],
            'add auto-increment column' => [
                SchemaChange::KIND_ADD_COLUMN, ['identity' => true], 'inplace', true, true, true,
            ],
            'add column, fulltext table' => [
                SchemaChange::KIND_ADD_COLUMN, ['table_has_fulltext' => true], 'inplace', true, true, true,
            ],
            'drop column' => [SchemaChange::KIND_DROP_COLUMN, [], 'inplace', true, true, false],
            'add btree index' => [
                SchemaChange::KIND_ADD_INDEX, ['element_type' => 'btree'], 'inplace', true, false, false,
            ],
            'add fulltext index' => [
                SchemaChange::KIND_ADD_INDEX, ['element_type' => 'fulltext'], 'inplace', true, false, true,
            ],
            'drop index' => [SchemaChange::KIND_DROP_INDEX, [], 'metadata', false, false, false],
            'add unique' => [
                SchemaChange::KIND_ADD_CONSTRAINT, ['element_type' => 'unique'], 'inplace', true, false, false,
            ],
            'add foreign key' => [
                SchemaChange::KIND_ADD_CONSTRAINT, ['element_type' => 'foreign'], 'inplace', true, false, false,
            ],
            'add primary key' => [
                SchemaChange::KIND_ADD_CONSTRAINT, ['element_type' => 'primary'], 'inplace', true, true, false,
            ],
            'drop foreign key' => [
                SchemaChange::KIND_DROP_CONSTRAINT, ['element_type' => 'foreign'], 'metadata', false, false, false,
            ],
            'drop primary key' => [
                SchemaChange::KIND_DROP_CONSTRAINT, ['element_type' => 'primary'], 'copy', true, true, true,
            ],
            'modify column: comment only' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $varchar(64), 'new' => $varchar(64, ['comment' => 'New label'])],
                'metadata', false, false, false,
            ],
            'modify column: default only' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $int, 'new' => ['default' => 1] + $int],
                'metadata', false, false, false,
            ],
            'modify column: scalar type noise is not a change' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $int, 'new' => ['default' => '0', 'comment' => 'Quantity'] + $int],
                'metadata', false, false, false,
            ],
            'modify column: varchar grows within two length bytes' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $varchar(128), 'new' => $varchar(255), 'bytes_per_char' => 4],
                'inplace', false, false, false,
            ],
            'modify column: varchar crosses the 255-byte line' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $varchar(32), 'new' => $varchar(255), 'bytes_per_char' => 4],
                'copy', true, true, true,
            ],
            'modify column: same growth is cheap on latin1' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $varchar(32), 'new' => $varchar(255), 'bytes_per_char' => 1],
                'inplace', false, false, false,
            ],
            'modify column: unknown charset takes the slow answer' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $varchar(64), 'new' => $varchar(128)],
                'copy', true, true, true,
            ],
            'modify column: varchar shrinks' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $varchar(255), 'new' => $varchar(128), 'bytes_per_char' => 4],
                'copy', true, true, true,
            ],
            'modify column: nullability' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $int, 'new' => ['nullable' => true] + $int],
                'inplace', true, true, false,
            ],
            'modify column: type' => [
                SchemaChange::KIND_MODIFY_COLUMN,
                ['old' => $int, 'new' => ['type' => 'bigint'] + $int],
                'copy', true, true, true,
            ],
            'modify column: nothing known' => [
                SchemaChange::KIND_MODIFY_COLUMN, [], 'copy', true, true, true,
            ],
            'modify table: comment' => [
                SchemaChange::KIND_MODIFY_TABLE,
                ['old' => ['engine' => 'innodb', 'comment' => 'A'], 'new' => ['engine' => 'innodb', 'comment' => 'B']],
                'metadata', false, false, false,
            ],
            'modify table: engine' => [
                SchemaChange::KIND_MODIFY_TABLE,
                ['old' => ['engine' => 'myisam', 'comment' => 'A'], 'new' => ['engine' => 'innodb', 'comment' => 'A']],
                'copy', true, true, true,
            ],
            'recreate table' => [SchemaChange::KIND_RECREATE_TABLE, [], 'copy', true, true, true],
            'unrecognised operation' => ['something_new', [], 'copy', true, true, true],
        ];
    }

    public function testOnlineRebuildBlocksWritesOnFulltextTable(): void
    {
        $change = $this->classifier->classify(
            'catalogsearch_fulltext',
            SchemaChange::KIND_DROP_COLUMN,
            'legacy',
            ['table_has_fulltext' => true]
        );

        self::assertTrue($change->blocksWrites);
        self::assertSame('blocking', $change->getImpact());
        self::assertStringContainsString('FULLTEXT', $change->note);
    }
}

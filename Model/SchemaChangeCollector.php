<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Byte8\MigrationForecast\Model\Data\SchemaChange;
use Magento\Framework\Setup\Declaration\Schema\Diff\SchemaDiff;
use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Columns\ColumnIdentityAwareInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementDiffAwareInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Magento\Framework\Setup\Declaration\Schema\Dto\Table;
use Magento\Framework\Setup\Declaration\Schema\Dto\TableElementInterface;
use Magento\Framework\Setup\Declaration\Schema\ElementHistory;
use Magento\Framework\Setup\Declaration\Schema\Operations\AddColumn;
use Magento\Framework\Setup\Declaration\Schema\Operations\AddComplexElement;
use Magento\Framework\Setup\Declaration\Schema\Operations\CreateTable;
use Magento\Framework\Setup\Declaration\Schema\Operations\DropElement;
use Magento\Framework\Setup\Declaration\Schema\Operations\DropReference;
use Magento\Framework\Setup\Declaration\Schema\Operations\DropTable;
use Magento\Framework\Setup\Declaration\Schema\Operations\ModifyColumn;
use Magento\Framework\Setup\Declaration\Schema\Operations\ModifyTable;
use Magento\Framework\Setup\Declaration\Schema\Operations\ReCreateTable;
use Magento\Framework\Setup\Declaration\Schema\SchemaConfigInterface;

/**
 * Reads the pending DDL from Magento's own declarative-schema diff.
 *
 * This is the same diff setup:upgrade executes (declared db_schema.xml vs the
 * live database, whitelist rules included), so the forecast covers exactly
 * what will run — nothing is re-derived from XML here.
 */
class SchemaChangeCollector
{
    /**
     * @param SchemaConfigInterface $schemaConfig
     * @param SchemaDiff $schemaDiff
     */
    public function __construct(
        private readonly SchemaConfigInterface $schemaConfig,
        private readonly SchemaDiff $schemaDiff
    ) {
    }

    /**
     * @return array<int, array{table: string, resource: string, kind: string, name: string, context: array}>
     */
    public function collect(): array
    {
        $diff = $this->schemaDiff->diff(
            $this->schemaConfig->getDeclarationConfig(),
            $this->schemaConfig->getDbConfig()
        );

        $changes = [];
        foreach ($diff->getAll() ?? [] as $operations) {
            foreach ($operations as $operation => $histories) {
                foreach ($histories as $history) {
                    if ($history instanceof ElementHistory) {
                        $changes[] = $this->describe((string) $operation, $history);
                    }
                }
            }
        }

        return $changes;
    }

    /**
     * @param string $operation
     * @param ElementHistory $history
     * @return array{table: string, resource: string, kind: string, name: string, context: array}
     */
    private function describe(string $operation, ElementHistory $history): array
    {
        $element = $history->getNew();
        $old = $history->getOld();
        $table = $element instanceof TableElementInterface ? $element->getTable() : $element;
        $context = [];

        switch ($operation) {
            case CreateTable::OPERATION_NAME:
                $kind = SchemaChange::KIND_NEW_TABLE;
                break;
            case DropTable::OPERATION_NAME:
                $kind = SchemaChange::KIND_DROP_TABLE;
                break;
            case ReCreateTable::OPERATION_NAME:
                $kind = SchemaChange::KIND_RECREATE_TABLE;
                break;
            case ModifyTable::OPERATION_NAME:
                $kind = SchemaChange::KIND_MODIFY_TABLE;
                $context = $this->paramsContext($element, $old);
                break;
            case AddColumn::OPERATION_NAME:
                $kind = SchemaChange::KIND_ADD_COLUMN;
                $context['identity'] = $element instanceof ColumnIdentityAwareInterface && $element->isIdentity();
                break;
            case ModifyColumn::OPERATION_NAME:
                $kind = SchemaChange::KIND_MODIFY_COLUMN;
                $context = $this->paramsContext($element, $old);
                break;
            case DropReference::OPERATION_NAME:
                $kind = SchemaChange::KIND_DROP_CONSTRAINT;
                $context['element_type'] = 'foreign';
                break;
            case DropElement::OPERATION_NAME:
                [$kind, $context] = $this->describeElement($element, false);
                break;
            case AddComplexElement::OPERATION_NAME:
                [$kind, $context] = $this->describeElement($element, true);
                break;
            default:
                $kind = $operation;
        }

        if ($table instanceof Table) {
            $context['table_has_fulltext'] = $this->hasFulltextIndex($table);
        }

        return [
            'table' => $table->getName(),
            'resource' => $table instanceof Table ? (string) $table->getResource() : '',
            'kind' => $kind,
            'name' => $element instanceof Table ? '' : $element->getName(),
            'context' => $context,
        ];
    }

    /**
     * @param ElementInterface $element
     * @param bool $adding
     * @return array{string, array<string, mixed>}
     */
    private function describeElement(ElementInterface $element, bool $adding): array
    {
        if ($element instanceof Column) {
            return [$adding ? SchemaChange::KIND_ADD_COLUMN : SchemaChange::KIND_DROP_COLUMN, []];
        }
        if ($element instanceof Index) {
            return [
                $adding ? SchemaChange::KIND_ADD_INDEX : SchemaChange::KIND_DROP_INDEX,
                ['element_type' => strtolower((string) $element->getIndexType())],
            ];
        }

        return [
            $adding ? SchemaChange::KIND_ADD_CONSTRAINT : SchemaChange::KIND_DROP_CONSTRAINT,
            ['element_type' => $element instanceof Reference ? 'foreign' : strtolower((string) $element->getType())],
        ];
    }

    /**
     * @param ElementInterface $new
     * @param ElementInterface|null $old
     * @return array<string, mixed>
     */
    private function paramsContext(ElementInterface $new, ?ElementInterface $old): array
    {
        return [
            'new' => $new instanceof ElementDiffAwareInterface ? $new->getDiffSensitiveParams() : [],
            'old' => $old instanceof ElementDiffAwareInterface ? $old->getDiffSensitiveParams() : [],
        ];
    }

    /**
     * @param Table $table
     * @return bool
     */
    private function hasFulltextIndex(Table $table): bool
    {
        foreach ($table->getIndexes() as $index) {
            if (strtolower((string) $index->getIndexType()) === Index::FULLTEXT_INDEX) {
                return true;
            }
        }

        return false;
    }
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Byte8\MigrationForecast\Model\Data\SchemaChange;

/**
 * Maps a pending change onto MySQL 8 / InnoDB's online-DDL matrix.
 *
 * | change                              | algorithm | rebuild | concurrent writes |
 * |-------------------------------------|-----------|---------|-------------------|
 * | new / drop table                    | metadata  | no      | yes               |
 * | add column                          | INSTANT   | no      | yes               |
 * | add auto-increment column           | INPLACE   | yes     | no                |
 * | drop column                         | INPLACE   | yes     | yes               |
 * | modify column: comment / default    | metadata  | no      | yes               |
 * | modify column: extend VARCHAR       | INPLACE   | no      | yes               |
 * | modify column: nullability          | INPLACE   | yes     | yes               |
 * | modify column: anything else        | COPY      | yes     | no                |
 * | add index / unique / foreign key    | INPLACE   | no      | yes               |
 * | add FULLTEXT index                  | INPLACE   | no      | no                |
 * | add primary key                     | INPLACE   | yes     | yes               |
 * | drop index / unique / foreign key   | metadata  | no      | yes               |
 * | drop primary key                    | COPY      | yes     | no                |
 * | table engine / charset / resource   | COPY      | yes     | no                |
 *
 * Where MySQL's behaviour depends on something not known here (server
 * version, whether a dropped column is indexed), the slower class wins: the
 * forecast is meant to be an upper bound, never a promise it can't keep.
 */
class Classifier
{
    private const VARCHAR_SINGLE_LENGTH_BYTE_MAX = 255;

    /**
     * @param string $table
     * @param string $kind One of SchemaChange::KIND_*.
     * @param string $name
     * @param array<string, mixed> $context element_type, identity, table_has_fulltext,
     *                                      old, new (diff-sensitive params), bytes_per_char.
     * @return SchemaChange Classified, not yet costed.
     */
    public function classify(string $table, string $kind, string $name, array $context = []): SchemaChange
    {
        $elementType = (string) ($context['element_type'] ?? '');

        [$algorithm, $rowScaled, $rebuild, $blocks, $note] = match ($kind) {
            SchemaChange::KIND_NEW_TABLE,
            SchemaChange::KIND_DROP_TABLE,
            SchemaChange::KIND_DROP_INDEX => $this->metadata(),
            SchemaChange::KIND_ADD_COLUMN => $this->addColumn($context),
            SchemaChange::KIND_DROP_COLUMN => $this->inplaceRebuild(
                'Table rebuild. INSTANT on MySQL 8.0.29+ when the column is not indexed.'
            ),
            SchemaChange::KIND_MODIFY_COLUMN => $this->modifyColumn($context),
            SchemaChange::KIND_ADD_INDEX => $elementType === 'fulltext'
                ? [SchemaChange::ALGORITHM_INPLACE, true, false, true, 'FULLTEXT build blocks writes.']
                : [SchemaChange::ALGORITHM_INPLACE, true, false, false, ''],
            SchemaChange::KIND_ADD_CONSTRAINT => $this->addConstraint($elementType),
            SchemaChange::KIND_DROP_CONSTRAINT => $elementType === 'primary'
                ? $this->copy('Dropping a primary key rebuilds the table.')
                : $this->metadata(),
            SchemaChange::KIND_MODIFY_TABLE => $this->modifyTable($context),
            SchemaChange::KIND_RECREATE_TABLE => $this->copy('Table is re-created and its data copied.'),
            default => $this->copy('Unrecognised operation; assumed blocking.'),
        };

        // An in-place rebuild of a table carrying a FULLTEXT index cannot run alongside writes.
        if ($rebuild && !$blocks && !empty($context['table_has_fulltext'])) {
            $blocks = true;
            $note = trim($note . ' Table has a FULLTEXT index, so the rebuild blocks writes.');
        }

        return new SchemaChange($table, $kind, $name, $algorithm, $rowScaled, $rebuild, $blocks, $note);
    }

    /**
     * @return array{string, bool, bool, bool, string}
     */
    private function metadata(string $note = ''): array
    {
        return [SchemaChange::ALGORITHM_METADATA, false, false, false, $note];
    }

    /**
     * @return array{string, bool, bool, bool, string}
     */
    private function inplaceRebuild(string $note = ''): array
    {
        return [SchemaChange::ALGORITHM_INPLACE, true, true, false, $note];
    }

    /**
     * @return array{string, bool, bool, bool, string}
     */
    private function copy(string $note = ''): array
    {
        return [SchemaChange::ALGORITHM_COPY, true, true, true, $note];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{string, bool, bool, bool, string}
     */
    private function addColumn(array $context): array
    {
        if (!empty($context['identity'])) {
            return [
                SchemaChange::ALGORITHM_INPLACE,
                true,
                true,
                true,
                'Adding an auto-increment column rebuilds the table and blocks writes.',
            ];
        }
        if (!empty($context['table_has_fulltext'])) {
            return $this->inplaceRebuild('INSTANT is unavailable on tables with a FULLTEXT index.');
        }

        return [SchemaChange::ALGORITHM_INSTANT, false, false, false, ''];
    }

    /**
     * @param string $elementType
     * @return array{string, bool, bool, bool, string}
     */
    private function addConstraint(string $elementType): array
    {
        return match ($elementType) {
            'primary' => $this->inplaceRebuild('Adding a primary key rebuilds the table.'),
            'foreign' => [
                SchemaChange::ALGORITHM_INPLACE,
                true,
                false,
                false,
                'Costed as an index build: InnoDB creates a supporting index when none exists.',
            ],
            default => [SchemaChange::ALGORITHM_INPLACE, true, false, false, ''],
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array{string, bool, bool, bool, string}
     */
    private function modifyTable(array $context): array
    {
        $changed = $this->changedKeys($context);
        if ($changed && !array_diff($changed, ['comment'])) {
            return $this->metadata('Comment only.');
        }

        return $this->copy('Changed: ' . ($changed ? implode(', ', $changed) : 'table options') . '.');
    }

    /**
     * @param array<string, mixed> $context
     * @return array{string, bool, bool, bool, string}
     */
    private function modifyColumn(array $context): array
    {
        $changed = $this->changedKeys($context);
        if (!$changed) {
            return $this->copy('Column definition changed.');
        }
        $label = 'Changed: ' . implode(', ', $changed) . '.';

        if (!array_diff($changed, ['comment', 'default'])) {
            return $this->metadata($label);
        }
        if (!array_diff($changed, ['comment', 'default', 'length']) && $this->isCheapVarcharExtension($context)) {
            return [SchemaChange::ALGORITHM_INPLACE, false, false, false, 'VARCHAR extended in place.'];
        }
        if (!array_diff($changed, ['comment', 'default', 'nullable'])) {
            return $this->inplaceRebuild($label);
        }

        return $this->copy($label);
    }

    /**
     * Keys whose value differs between the live and the declared definition.
     *
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function changedKeys(array $context): array
    {
        $old = (array) ($context['old'] ?? []);
        $new = (array) ($context['new'] ?? []);
        $changed = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            // Loose on purpose: the DB reader and the XML reader disagree on scalar types.
            if (($old[$key] ?? null) != ($new[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * Growing a VARCHAR is metadata-only as long as the number of length bytes
     * (1 up to 255 bytes, 2 above) stays the same; crossing that line or
     * shrinking forces a table copy.
     *
     * @param array<string, mixed> $context
     * @return bool
     */
    private function isCheapVarcharExtension(array $context): bool
    {
        $old = (array) ($context['old'] ?? []);
        $new = (array) ($context['new'] ?? []);
        if (($old['type'] ?? '') !== 'varchar' || ($new['type'] ?? '') !== 'varchar') {
            return false;
        }
        $oldLength = (int) ($old['length'] ?? 0);
        $newLength = (int) ($new['length'] ?? 0);
        if ($oldLength <= 0 || $newLength < $oldLength) {
            return false;
        }

        // Unknown charset: require the answer to hold for every plausible width.
        $widths = empty($context['bytes_per_char']) ? [1, 2, 3, 4] : [(int) $context['bytes_per_char']];
        foreach ($widths as $width) {
            $oldSingle = $oldLength * $width <= self::VARCHAR_SINGLE_LENGTH_BYTE_MAX;
            $newSingle = $newLength * $width <= self::VARCHAR_SINGLE_LENGTH_BYTE_MAX;
            if ($oldSingle !== $newSingle) {
                return false;
            }
        }

        return true;
    }
}

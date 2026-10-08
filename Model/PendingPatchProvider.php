<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\DbVersionInfo;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Setup\Patch\PatchHistory;
use Magento\Framework\Setup\Patch\PatchInterface;
use Magento\Framework\Setup\Patch\PatchReader;

/**
 * Work setup:upgrade will do that cannot be statically costed: patches and
 * legacy Install/Upgrade scripts are arbitrary PHP.
 */
class PendingPatchProvider
{
    private const LEGACY_SCRIPTS = [
        'schema' => ['InstallSchema.php', 'UpgradeSchema.php'],
        'data' => ['InstallData.php', 'UpgradeData.php'],
    ];

    /**
     * @param ModuleListInterface $moduleList
     * @param PatchHistory $patchHistory
     * @param PatchReader $dataPatchReader
     * @param PatchReader $schemaPatchReader
     * @param DbVersionInfo $dbVersionInfo
     * @param ComponentRegistrarInterface $componentRegistrar
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly PatchHistory $patchHistory,
        private readonly PatchReader $dataPatchReader,
        private readonly PatchReader $schemaPatchReader,
        private readonly DbVersionInfo $dbVersionInfo,
        private readonly ComponentRegistrarInterface $componentRegistrar
    ) {
    }

    /**
     * @return string[] Patch class names not yet in patch_list.
     */
    public function getDataPatches(): array
    {
        return $this->getPending($this->dataPatchReader);
    }

    /**
     * @return string[] Patch class names not yet in patch_list.
     */
    public function getSchemaPatches(): array
    {
        return $this->getPending($this->schemaPatchReader);
    }

    /**
     * Modules behind on setup_version that still ship Install/Upgrade scripts.
     *
     * @return string[] e.g. "Vendor_Module (schema 1.0.0 -> 1.1.0)"
     */
    public function getLegacyScripts(): array
    {
        $pending = [];
        try {
            /** @var array<int, array<string, string>> $errors Core documents string[], returns rows. */
            $errors = $this->dbVersionInfo->getDbVersionErrors();
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($errors as $error) {
            $module = (string) $error[DbVersionInfo::KEY_MODULE];
            $type = (string) $error[DbVersionInfo::KEY_TYPE];
            $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $module);
            foreach (self::LEGACY_SCRIPTS[$type] ?? [] as $script) {
                if ($path && is_file($path . '/Setup/' . $script)) {
                    $pending[] = sprintf(
                        '%s (%s %s -> %s)',
                        $module,
                        $type,
                        $error[DbVersionInfo::KEY_CURRENT],
                        $error[DbVersionInfo::KEY_REQUIRED]
                    );
                    break;
                }
            }
        }

        return $pending;
    }

    /**
     * @param PatchReader $reader
     * @return string[]
     */
    private function getPending(PatchReader $reader): array
    {
        $pending = [];
        foreach ($this->moduleList->getNames() as $moduleName) {
            foreach ($reader->read($moduleName) as $patchClass) {
                $patchClass = ltrim((string) $patchClass, '\\');
                if (!$this->isApplied($patchClass)) {
                    $pending[] = $patchClass;
                }
            }
        }
        sort($pending);

        return $pending;
    }

    /**
     * A patch counts as applied when it, or any alias it declares, is recorded.
     *
     * @param string $patchClass
     * @return bool
     */
    private function isApplied(string $patchClass): bool
    {
        if ($this->patchHistory->isApplied($patchClass)) {
            return true;
        }
        try {
            if (!is_subclass_of($patchClass, PatchInterface::class)) {
                return false;
            }
            // Aliases are constant lists; reading them must not run the patch's dependencies.
            $patch = (new \ReflectionClass($patchClass))->newInstanceWithoutConstructor();
            foreach ($patch->getAliases() ?: [] as $alias) {
                if ($this->patchHistory->isApplied(ltrim((string) $alias, '\\'))) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }
}

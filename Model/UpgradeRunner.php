<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\MigrationForecast\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Process\Process;

/**
 * Runs the real, untouched setup:upgrade.
 *
 * In a child process on purpose: the upgrade starts from a clean slate, exactly
 * as if it had been typed, with nothing the forecast loaded left in memory.
 */
class UpgradeRunner
{
    /**
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * @param string[] $arguments Extra arguments for setup:upgrade, e.g. ["--keep-generated"].
     * @param callable $onOutput Receives each chunk of the upgrade's output as a string.
     * @return int The exit code of setup:upgrade.
     */
    public function run(array $arguments, callable $onOutput): int
    {
        $root = $this->directoryList->getRoot();
        $process = new Process(
            array_merge(
                // Same binary and memory limit as this process; a "-d" given on the command line is not inherited.
                [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), $root . '/bin/magento', 'setup:upgrade'],
                $arguments
            ),
            $root
        );
        $process->setTimeout(null);

        return $process->run(static function (string $type, string $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });
    }
}

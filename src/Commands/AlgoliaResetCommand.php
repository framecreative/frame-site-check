<?php

namespace Frame\Testbed\Commands;

use Frame\Testbed\Algolia;

class AlgoliaResetCommand extends TestbedCommand
{
    protected $signature = 'testbed:algolia-reset';

    protected $description = 'Delete every index in the configured (test) Algolia app';

    protected function perform(): int
    {
        $algolia = Algolia::admin();

        foreach ($algolia->indexNames() as $index) {
            $algolia->deleteIndex($index)->throw();
            $this->line("Deleted Algolia index {$index}.");
        }

        return self::SUCCESS;
    }
}

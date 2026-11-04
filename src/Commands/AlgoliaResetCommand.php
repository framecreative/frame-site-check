<?php

namespace Frame\SiteCheck\Commands;

use Frame\SiteCheck\Algolia;

class AlgoliaResetCommand extends SiteCheckCommand
{
    protected $signature = 'site-check:algolia-reset';

    protected $description = 'Delete every index in the configured (test) Algolia app';

    protected function perform(): int
    {
        $algolia = Algolia::admin();

        $tasks = [];

        foreach ($algolia->indexNames() as $index) {
            $tasks[$index] = $algolia->deleteIndex($index)->throw()->json('taskID');
        }

        foreach ($tasks as $index => $taskId) {
            $algolia->waitForTask($index, $taskId);
            $this->line("Deleted Algolia index {$index}.");
        }

        return self::SUCCESS;
    }
}

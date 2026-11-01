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

        foreach ($algolia->indexNames() as $index) {
            $algolia->deleteIndex($index)->throw();
            $this->line("Deleted Algolia index {$index}.");
        }

        return self::SUCCESS;
    }
}

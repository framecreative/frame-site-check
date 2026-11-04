<?php

namespace Frame\SiteCheck\Statamic;

use Illuminate\Support\Str;
use StatamicRadPack\Runway\Runway;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * What the crawler saves and creates in the control panel, written once seeding is done: one record of every
 * collection, taxonomy, global set and Runway resource, with its edit and create pages and the path its form
 * submits to. `skip` removes content types by key (e.g. runway.user); `create: false` turns creating off.
 *
 * Each create carries a payload: a value for every field in the blueprint from the seeder's generator, so a
 * create is tested with complete data instead of only a title.
 */
class AdminManifest
{
    public function __construct(private array $config = [], private ?ContentSeeder $content = null) {}

    public function build(): array
    {
        $site = Site::default()->handle();
        $cp = '/'.trim(config('statamic.cp.route', 'cp'), '/');
        $create = $this->config['create'] ?? true;
        $targets = [];

        foreach (Collection::all() as $collection) {
            $handle = $collection->handle();
            $targets[] = [
                'key' => "collections.{$handle}",
                'label' => $collection->title(),
                'edit' => $this->path($collection->queryEntries()->first()?->editUrl()),
                'create' => $create ? $this->path($collection->createEntryUrl($site)) : null,
                'submits' => "{$cp}/collections/{$handle}/entries",
                'payload' => $create ? $this->payload($collection->entryBlueprint()) : null,
            ];
        }

        foreach (Taxonomy::all() as $taxonomy) {
            $handle = $taxonomy->handle();
            $targets[] = [
                'key' => "taxonomies.{$handle}",
                'label' => $taxonomy->title(),
                'edit' => $this->path(Term::query()->where('taxonomy', $handle)->first()?->editUrl()),
                'create' => $create ? $this->path(cp_route('taxonomies.terms.create', [$handle, $site])) : null,
                'submits' => "{$cp}/taxonomies/{$handle}/terms",
                'payload' => $create ? $this->payload($taxonomy->termBlueprint()) : null,
            ];
        }

        foreach (GlobalSet::all() as $set) {
            $targets[] = [
                'key' => "globals.{$set->handle()}",
                'label' => $set->title(),
                'edit' => $this->path($set->in($site)?->editUrl()),
                'create' => null,
                'submits' => "{$cp}/globals/{$set->handle()}",
            ];
        }

        // Control panel users. Statamic asks for the password again (an elevated session) before showing, saving or
        // creating one, and its create page is a wizard with no save shortcut, so the create is submitted directly.
        if (\Statamic\Statamic::pro()) {
            $user = \Statamic\Facades\User::all()->sortBy(fn ($user) => $user->email())->first();
            $payload = $create ? $this->payload(\Statamic\Facades\User::blueprint()) : null;

            if ($payload !== null) {
                $payload = array_merge(array_diff_key($payload, array_flip(['roles', 'groups', 'super', 'password'])), [
                    'email' => 'site-check-'.fake()->unique()->regexify('[a-z0-9]{8}').'@example.test',
                    'invitation' => ['send' => true, 'subject' => 'Site check invitation', 'message' => 'Sent by the site check.'],
                ]);
            }

            $targets[] = [
                'key' => 'users',
                'label' => 'User',
                'edit' => $user ? $this->path($user->editUrl()) : null,
                'create' => $create ? $this->path(cp_route('users.create')) : null,
                'submits' => "{$cp}/users",
                'payload' => $payload,
                'elevate' => true,
                'submit_directly' => true,
            ];
        }

        if (class_exists(Runway::class)) {
            foreach (Runway::allResources() as $resource) {
                if ($resource->hidden() || $resource->readOnly()) {
                    continue;
                }

                $handle = $resource->handle();
                $model = $resource->model()->newQuery()->first();
                $targets[] = [
                    'key' => "runway.{$handle}",
                    'label' => $resource->name(),
                    'edit' => $model ? $this->path(cp_route('runway.edit', [$handle, $model->{$resource->routeKey()}])) : null,
                    'create' => $create ? $this->path(cp_route('runway.create', $handle)) : null,
                    'submits' => "{$cp}/runway/{$handle}",
                    'payload' => $create ? $this->payload($resource->blueprint()) : null,
                ];
            }
        }

        $skip = $this->config['skip'] ?? [];

        return array_values(array_filter($targets, fn ($target) => ! in_array($target['key'], $skip, true)));
    }

    public function write(string $file): int
    {
        $targets = $this->build();
        file_put_contents($file, json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return count($targets);
    }

    /**
     * Field values as the create form would submit them: generated, stored the way a save stores them, then
     * pre-processed by each fieldtype into its form format (single assets and relationships as arrays, dates as the
     * date picker sends them). Title, name and slug are generated here because the seeder sets those outside the
     * blueprint; a unique title keeps unique slugs and names from clashing with seeded records.
     */
    private function payload($blueprint): ?array
    {
        if (! $this->content || ! $blueprint) {
            return null;
        }

        $stored = array_merge($this->content->processed($blueprint, false), $this->content->processed($blueprint, true));
        $form = $blueprint->fields()->addValues($stored)->preProcess()->values()->only(array_keys($stored))->all();

        $fields = $blueprint->fields()->all();
        $title = 'Site check '.Str::headline(fake()->unique()->words(3, true));
        $own = array_filter([
            'title' => $fields->has('title') ? $title : null,
            'name' => $fields->has('name') ? $title : null,
            'slug' => $fields->has('slug') ? Str::slug($title) : null,
        ]);

        return array_merge($form, $own);
    }

    private function path(?string $url): ?string
    {
        return $url ? parse_url($url, PHP_URL_PATH) : null;
    }
}

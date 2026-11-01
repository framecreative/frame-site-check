<?php

namespace Frame\SiteCheck\Statamic;

use Illuminate\Support\Collection as LaravelCollection;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Generates Statamic content from the site's blueprints: every field gets a value chosen by its fieldtype,
 * so the seeded content follows blueprint changes without being maintained by hand.
 */
class ContentSeeder
{
    /** @var array<string, int> fieldtypes that had no generator, with how often they were seen */
    public array $unhandled = [];

    protected ?string $assetContainer = null;

    protected int $placeholders = 0;

    /** @var array<int, array{0: \Statamic\Contracts\Entries\Entry, 1: \Statamic\Fields\Blueprint}> */
    protected array $created = [];

    /** @var array<int, array{0: \Statamic\Contracts\Entries\Entry, 1: \Statamic\Fields\Blueprint}> entries present before seeding */
    protected array $existing = [];

    public function __construct(protected array $config = [])
    {
    }

    public function run(): void
    {
        $this->seedTerms();
        $this->seedEntries();
        $this->fillRelationships();
        $this->seedStructures();
        $this->seedGlobals();
        $this->seedNavigation();
    }

    protected function perBlueprint(): int
    {
        return (int) ($this->config['per_blueprint'] ?? 3);
    }

    /** Runs before any seeding, so Runway models and entries both get placeholders on the local disk. */
    public function prepareAssetContainers(): void
    {
        $disk = $this->config['asset_disk'] ?? 'public';

        foreach (AssetContainer::all() as $container) {
            $container->disk($disk)->save();
            $this->assetContainer ??= $container->handle();
        }
    }

    protected function seedTerms(): void
    {
        foreach (Taxonomy::all() as $taxonomy) {
            if (Term::query()->where('taxonomy', $taxonomy->handle())->count() > 0) {
                continue; // kept reference data
            }

            foreach ($taxonomy->termBlueprints() as $blueprint) {
                for ($i = 1; $i <= $this->perBlueprint(); $i++) {
                    $title = Str::title(fake()->unique()->words(2, true));

                    Term::make()
                        ->taxonomy($taxonomy)
                        ->slug(Str::slug($title))
                        ->blueprint($blueprint->handle())
                        ->data(array_merge($this->processed($blueprint, false), ['title' => $title]))
                        ->save();
                }
            }
        }
    }

    protected function seedEntries(): void
    {
        $fixed = $this->config['entries'] ?? [];

        foreach (Collection::all() as $collection) {
            $site = $collection->sites()->first() ?? Site::default()->handle();

            // Entries that already exist (boot entries) get their fields filled, but keep their place.
            foreach (Entry::query()->where('collection', $collection->handle())->get() as $entry) {
                $blueprint = $entry->blueprint();
                $entry->merge($this->processed($blueprint, false))->save();
                $this->existing[] = [$entry, $blueprint];
            }

            foreach ($fixed[$collection->handle()] ?? [] as $definition) {
                $blueprint = $collection->entryBlueprint($definition['blueprint'] ?? null);
                $this->makeEntry($collection, $blueprint, $site, $definition['title'] ?? Str::headline($definition['slug']), $definition['slug']);
            }

            foreach ($collection->entryBlueprints() as $blueprint) {
                for ($i = 1; $i <= $this->perBlueprint(); $i++) {
                    $title = Str::headline(fake()->unique()->words(3, true));
                    $this->makeEntry($collection, $blueprint, $site, $title, Str::slug($title));
                }
            }
        }
    }

    protected function makeEntry($collection, $blueprint, string $site, string $title, string $slug): void
    {
        $entry = Entry::make()
            ->collection($collection)
            ->blueprint($blueprint->handle())
            ->locale($site)
            ->slug($slug)
            ->published(true)
            ->data(array_merge($this->processed($blueprint, false), ['title' => $title]));

        if ($collection->dated()) {
            $entry->date(now()->subDays(count($this->created)));
        }

        $entry->save();

        $this->created[] = [$entry, $blueprint];
    }

    protected function fillRelationships(): void
    {
        foreach (array_merge($this->existing, $this->created) as [$entry, $blueprint]) {
            if ($values = $this->processed($blueprint, true)) {
                $entry->merge($values)->save();
            }
        }
    }

    protected function seedStructures(): void
    {
        foreach (Collection::all() as $collection) {
            if (! $collection->hasStructure()) {
                continue;
            }

            $structure = $collection->structure();
            $site = $collection->sites()->first() ?? Site::default()->handle();
            $seeded = collect($this->created)->map(fn ($pair) => $pair[0]->id());
            $ids = Entry::query()->where('collection', $collection->handle())->get()->map->id()
                // Entries that existed before seeding (boot entries such as a root home page) stay first.
                ->sortBy(fn ($id) => $seeded->contains($id) ? 1 : 0)
                ->values();

            $branches = $ids->map(fn ($id) => ['entry' => $id])->all();

            ($structure->in($site) ?? $structure->makeTree($site))->tree($branches)->save();
        }
    }

    protected function seedGlobals(): void
    {
        foreach (GlobalSet::all() as $set) {
            $site = Site::default()->handle();
            $variables = $set->in($site) ?? $set->makeLocalization($site);

            if ($blueprint = $set->blueprint()) {
                $variables->data(array_merge($this->processed($blueprint, false), $this->processed($blueprint, true)))->save();
            }
        }
    }

    protected function seedNavigation(): void
    {
        foreach (Nav::all() as $nav) {
            $site = Site::default()->handle();
            $collections = $nav->collections()->map->handle()->all();

            $branches = Entry::query()
                ->when($collections, fn ($query) => $query->whereIn('collection', $collections))
                ->limit(5)
                ->get()
                ->map(fn ($entry) => ['entry' => $entry->id()])
                ->all();

            $branches[] = ['title' => 'External link', 'url' => 'https://example.test'];

            ($nav->in($site) ?? $nav->makeTree($site))->tree($branches)->save();
        }
    }

    /**
     * Generated values run through Statamic's own field processing, so they're stored exactly as a
     * control panel save would store them (Bard as HTML where save_html is on, single assets as strings…).
     */
    public function processed($blueprint, bool $relationships): array
    {
        $values = $this->values($blueprint->fields()->all(), $relationships);

        if (! $values) {
            return [];
        }

        $processed = $blueprint->fields()->addValues($values)->process()->values()->all();

        return array_intersect_key($processed, $values);
    }

    /**
     * Values for a set of fields. Relationship fields are filled in a second pass ($relationships = true),
     * once everything they can point at exists.
     */
    public function values(LaravelCollection $fields, bool $relationships): array
    {
        $values = [];

        foreach ($fields as $handle => $field) {
            // Timestamps are left to the model; a generated deleted_at would soft-delete the record.
            if (in_array($handle, ['title', 'slug', 'created_at', 'updated_at', 'deleted_at'], true)
                || $this->isRelationship($field) !== $relationships) {
                continue;
            }

            $value = $this->value($field);

            if ($value !== null) {
                $values[$handle] = $value;
            }
        }

        return $values;
    }

    public function isRelationship(Field $field): bool
    {
        return in_array($field->type(), ['entries', 'terms', 'users', 'belongs_to', 'has_many', 'link'], true);
    }

    protected function value(Field $field): mixed
    {
        // Site-specific meanings (an icon file name, a video ID…) come from site-check.yaml `values`, by field handle.
        if (array_key_exists($field->handle(), $this->config['values'] ?? [])) {
            return $this->config['values'][$field->handle()];
        }

        $multiple = (int) $field->get('max_items', $field->get('max_files', 0)) !== 1;

        return match ($field->type()) {
            'text' => $this->text($field),
            'slug' => fake()->slug(3),
            'textarea', 'markdown' => fake()->paragraph(),
            'bard' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => fake()->paragraph()]]]],
            'code' => ['code' => '<p>Site check</p>', 'mode' => 'htmlmixed'],
            'integer' => fake()->numberBetween(1, 10),
            'float' => fake()->randomFloat(2, 1, 10),
            'range' => (int) $field->get('min', 0),
            'toggle' => true,
            'select', 'radio', 'button_group' => $this->option($field, (bool) $field->get('multiple')),
            'checkboxes' => $this->option($field, true),
            'date' => now()->format('Y-m-d'),
            'time' => '09:00',
            'color' => '#336699',
            'list', 'taggable', 'tags' => [fake()->word(), fake()->word()],
            'array' => [fake()->word() => fake()->word()],
            'table' => [['cells' => [fake()->word(), fake()->word()]]],
            'assets' => $this->assets($field, $multiple),
            'entries' => $this->entryIds($field, $multiple),
            'terms' => $this->termIds($field, $multiple),
            'link' => $this->entryIds($field, false) ? 'entry::'.$this->entryIds($field, false) : 'https://example.test',
            'belongs_to', 'has_many' => $this->runwayIds($field, $field->type() === 'has_many'),
            'replicator' => $this->replicator($field),
            'grid' => $this->grid($field),
            'group' => $this->values($this->fields($field->get('fields', [])), false),
            'video' => null,
            'users' => null,
            'section', 'spacer', 'html', 'hidden', 'revealer' => null,
            default => $this->unhandled($field),
        };
    }

    /** Plain text fields often hold URLs, emails or numbers; give those a usable value rather than a sentence. */
    protected function text(Field $field): string
    {
        $handle = strtolower($field->handle());

        return match (true) {
            $field->get('input_type') === 'url', (bool) preg_match('/url|link|href/', $handle) => 'https://example.test/',
            $field->get('input_type') === 'email', str_contains($handle, 'email') => 'site-check@example.test',
            in_array($field->get('input_type'), ['number', 'tel'], true) => (string) fake()->numberBetween(1, 100),
            default => fake()->sentence(4),
        };
    }

    protected function unhandled(Field $field): null
    {
        $this->unhandled[$field->type()] = ($this->unhandled[$field->type()] ?? 0) + 1;

        return null;
    }

    protected function option(Field $field, bool $multiple): mixed
    {
        $options = $field->get('options', []);
        $keys = array_is_list($options) && ! is_array($options[0] ?? null)
            ? $options
            : array_map(fn ($key, $option) => is_array($option) ? ($option['key'] ?? $key) : $key, array_keys($options), $options);

        if (! $keys) {
            return null;
        }

        return $multiple ? [reset($keys)] : reset($keys);
    }

    protected function fields(array $config): LaravelCollection
    {
        return (new Fields($config))->all();
    }

    protected function replicator(Field $field): array
    {
        return $field->fieldtype()->flattenedSetsConfig()
            ->map(fn ($set, $handle) => array_merge(
                ['id' => Str::random(8), 'type' => $handle, 'enabled' => true],
                $this->values($this->fields($set['fields'] ?? []), false),
            ))
            ->values()
            ->all();
    }

    protected function grid(Field $field): array
    {
        $fields = $this->fields($field->get('fields', []));

        return collect(range(1, 2))
            ->map(fn () => array_merge(['id' => Str::random(8)], $this->values($fields, false)))
            ->all();
    }

    protected function assets(Field $field, bool $multiple): mixed
    {
        $container = AssetContainer::find($field->get('container') ?? $this->assetContainer);

        if (! $container) {
            return null;
        }

        // Asset IDs (container::path), as the control panel submits them; processing stores the path.
        $id = $container->handle().'::'.$this->placeholder($container);

        return $multiple ? [$id] : $id;
    }

    /** A generated JPEG, so image-dependent code (dimensions, Glide, colour sampling) has a real image. */
    protected function placeholder($container): string
    {
        $index = ($this->placeholders++ % 6) + 1;
        $path = "site-check/placeholder-{$index}.jpg";

        if (! $container->disk()->exists($path)) {
            $image = imagecreatetruecolor(1200, 800);
            $colours = [[51, 102, 153], [153, 102, 51], [51, 153, 102], [102, 51, 153], [153, 51, 102], [102, 153, 51]];
            imagefill($image, 0, 0, imagecolorallocate($image, ...$colours[$index - 1]));
            imagestring($image, 5, 40, 40, "Site check placeholder {$index}", imagecolorallocate($image, 255, 255, 255));

            ob_start();
            imagejpeg($image, null, 80);
            $container->disk()->put($path, ob_get_clean());
        }

        return $path;
    }

    protected function entryIds(Field $field, bool $multiple): mixed
    {
        $ids = Entry::query()
            ->when($field->get('collections'), fn ($query, $collections) => $query->whereIn('collection', $collections))
            ->limit($multiple ? 2 : 1)
            ->get()
            ->map->id()
            ->all();

        return $multiple ? $ids : ($ids[0] ?? null);
    }

    protected function termIds(Field $field, bool $multiple): mixed
    {
        $taxonomies = (array) ($field->get('taxonomies') ?: Taxonomy::handles()->all());

        $ids = Term::query()
            ->whereIn('taxonomy', $taxonomies)
            ->limit($multiple ? 2 : 1)
            ->get()
            ->map(fn ($term) => count($taxonomies) === 1 ? $term->slug() : $term->taxonomyHandle().'::'.$term->slug())
            ->all();

        return $multiple ? $ids : ($ids[0] ?? null);
    }

    protected function runwayIds(Field $field, bool $multiple): mixed
    {
        $runway = 'StatamicRadPack\\Runway\\Runway';

        if (! class_exists($runway) || ! ($resource = $runway::findResource((string) $field->get('resource')))) {
            return null;
        }

        $model = $resource->model();
        $ids = $model->newQuery()->limit($multiple ? 2 : 1)->pluck($model->getKeyName())->all();

        return $multiple ? $ids : ($ids[0] ?? null);
    }
}

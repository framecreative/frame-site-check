<?php

namespace Frame\SiteCheck\Statamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use StatamicRadPack\Runway\Runway;
use Throwable;

/**
 * Generates Eloquent models for every Runway resource from its blueprint, using the same field generator as
 * the Statamic content, so JSON content columns get exactly the format the control panel would save.
 *
 * Foreign keys come from the database itself, so required keys the blueprint doesn't show (relationships
 * edited inline from the parent, for example) are still filled. Parents are seeded before children;
 * has_many fields backed by a BelongsToMany relationship are attached afterwards.
 */
class RunwaySeeder
{
    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<string, bool> resource handles already seeded */
    protected array $seeded = [];

    public function __construct(protected ContentSeeder $content, protected array $config = [])
    {
    }

    /**
     * Model events are off while generating: observers would run against half-built data (and some reach
     * external services). Derived data the app normally builds in observers is the site seeder's job.
     */
    public function run(): void
    {
        Model::withoutEvents(fn () => $this->generate());
    }

    protected function generate(): void
    {
        $skip = $this->config['skip'] ?? [];
        $resources = Runway::allResources()->keyBy(fn ($resource) => $resource->handle());
        $pending = $resources->reject(fn ($resource) => in_array($resource->handle(), $skip, true));
        $handleForTable = $resources->mapWithKeys(fn ($resource) => [$resource->databaseTable() => $resource->handle()]);

        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn ($resource) => $this->parents($resource, $handleForTable)
                ->every(fn ($parent) => isset($this->seeded[$parent]) || ! $pending->has($parent) || $parent === $resource->handle()));

            // A dependency cycle: seed what's left anyway, and let the errors show what couldn't be satisfied.
            foreach ($ready->isEmpty() ? $pending : $ready as $handle => $resource) {
                $this->seed($resource);
                $pending->forget($handle);
            }
        }

        foreach ($resources as $resource) {
            if (isset($this->seeded[$resource->handle()])) {
                $this->attach($resource);
            }
        }
    }

    /** Resources this one needs rows from: belongs_to targets and tables its foreign keys reference. */
    protected function parents($resource, $handleForTable)
    {
        $fromBlueprint = $resource->blueprint()->fields()->all()
            ->filter(fn ($field) => $field->type() === 'belongs_to')
            ->map(fn ($field) => $field->get('resource'));

        $fromForeignKeys = collect(Schema::getForeignKeys($resource->databaseTable()))
            ->map(fn ($key) => $handleForTable[$key['foreign_table']] ?? null);

        return $fromBlueprint->merge($fromForeignKeys)->filter()->unique()->values();
    }

    protected function seed($resource): void
    {
        $handle = $resource->handle();
        $this->seeded[$handle] = true;

        $count = (int) ($this->config['counts'][$handle] ?? $this->config['per_resource'] ?? 3);
        $table = $resource->databaseTable();
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $foreignKeys = collect(Schema::getForeignKeys($table))
            ->filter(fn ($key) => count($key['columns']) === 1)
            ->mapWithKeys(fn ($key) => [$key['columns'][0] => [$key['foreign_table'], $key['foreign_columns'][0]]]);
        $blueprint = $resource->blueprint();
        $model = $resource->model();
        $keyName = $model->getKeyName();

        for ($i = 1; $i <= $count; $i++) {
            $attributes = array_intersect_key($this->content->processed($blueprint, false), $columns->all());

            foreach ($blueprint->fields()->all() as $field) {
                if ($field->type() === 'belongs_to' && $columns->has($field->handle()) && ! $foreignKeys->has($field->handle())) {
                    $related = Runway::findResource((string) $field->get('resource'));
                    $attributes[$field->handle()] = $related ? $this->randomKey($related->databaseTable(), $related->model()->getKeyName()) : null;
                }
            }

            foreach ($foreignKeys as $column => [$foreignTable, $foreignColumn]) {
                $attributes[$column] = $this->randomKey($foreignTable, $foreignColumn) ?? ($attributes[$column] ?? null);
            }

            $title = Str::headline(fake()->unique()->words(3, true));
            foreach (['title' => $title, 'name' => $title, 'slug' => Str::slug($title)] as $column => $value) {
                if ($columns->has($column)) {
                    $attributes[$column] = $value;
                }
            }

            if ($columns->has($keyName) && ! $columns[$keyName]['auto_increment']) {
                $attributes[$keyName] = (int) DB::table($table)->max($keyName) + 1;
            }

            // Required columns the blueprint doesn't cover still need a value.
            foreach ($columns as $name => $column) {
                if (! array_key_exists($name, $attributes) && ! $column['nullable'] && $column['default'] === null
                    && ! $column['auto_increment'] && ! in_array($name, ['created_at', 'updated_at'], true)) {
                    $attributes[$name] = $this->requiredValue($column['type_name']);
                }
            }

            try {
                $model->newInstance()->forceFill($attributes)->save();
            } catch (Throwable $e) {
                $this->errors[] = "{$handle}: ".Str::limit($e->getMessage(), 300);

                return;
            }
        }
    }

    protected function requiredValue(string $type): mixed
    {
        return match (true) {
            in_array($type, ['datetime', 'timestamp', 'date'], true) => now(),
            in_array($type, ['tinyint', 'smallint', 'int', 'integer', 'bigint', 'decimal', 'double', 'float'], true) => 0,
            $type === 'json' => '[]',
            default => fake()->word(),
        };
    }

    // Picks go through Faker rather than the database's RAND(), so a run's --seed reproduces the same data.
    protected function randomKey(string $table, string $column): mixed
    {
        $keys = DB::table($table)->orderBy($column)->pluck($column)->all();

        return $keys ? fake()->randomElement($keys) : null;
    }

    protected function attach($resource): void
    {
        $model = $resource->model();
        $table = $resource->databaseTable();

        foreach ($resource->blueprint()->fields()->all() as $field) {
            if ($field->type() !== 'has_many') {
                continue;
            }

            $relationName = $field->get('relationship_name') ?: $field->handle();

            if (! method_exists($model, $relationName) || ! ($model->{$relationName}() instanceof BelongsToMany)) {
                continue;
            }

            $relation = $model->{$relationName}();
            $related = $relation->getRelated();

            $relatedKeys = $related->newQueryWithoutScopes()->orderBy($related->getKeyName())->pluck($related->getKeyName())->all();

            foreach (DB::table($table)->orderBy($model->getKeyName())->pluck($model->getKeyName()) as $key) {
                $ids = fake()->randomElements($relatedKeys, min(2, count($relatedKeys)));

                try {
                    $model->newQueryWithoutScopes()->find($key)?->{$relationName}()->syncWithoutDetaching($ids);
                } catch (Throwable $e) {
                    $this->errors[] = "{$resource->handle()}.{$relationName}: ".Str::limit($e->getMessage(), 300);
                    break;
                }
            }
        }
    }
}

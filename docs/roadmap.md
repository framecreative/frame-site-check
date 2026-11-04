# Site check roadmap

Agreed direction and open work. Nothing here is built unless marked done.

## Purpose

Find out whether a change (usually maintenance updates) has broken a site in a big, obvious, testable way,
before it reaches staging. It doesn't try to test everything: the report lists what it couldn't test.

It must work for any Frame site: Laravel, Statamic, WordPress (Bedrock) and Craft 5. Anything specific to
a stack or a site is configured in the project's `site-check.yaml`.

## Done

- Seeded container runner (`bin/site-check`), crawler, findings report format.
- `site-check compare`: lock file diff against `live`, scripted, writes `.site-check/changes.json`.
- Claude skill (`skills/site-check`), linked into each site by a Composer plugin on install/update
  (skipped on `--no-dev`).
- Nothing registered in the site's app: the tasks (preflight, reset, seed, freshness, Algolia reset) run from
  `scripts/task.php` inside the container, against the site's app, for that process only.
- Control panel save and create tests (Statamic, Runway): seeding writes the targets, the crawler saves one
  record and creates one of each type.
- Learning and Families configured (uncommitted on `feature/testbed`, maintenance merged in).

## Next

### 1. Release notes: scripted, and only what matters

- `compare` collects notes for **direct runtime packages** (`require`, not `require-dev`) with a **major**
  change (0.x minors count as major). Written to `.site-check/notes/<package>.md`.
- Sources, in order: GitHub releases in the version range (GitHub API, using the token Composer already
  has, falling back to `gh auth token`); `UPGRADE.md`/`CHANGELOG.md` in `vendor`; for `laravel/framework`,
  the upgrade guide for the new major.
- The skill reads only those files, greps the site for each breaking change, and lists it as **Affected**
  (with the action needed) or **Not affected**. No subagents, no mapping changes to routes.
- Purpose: catch breaking changes the crawl can't see (queues, mail, cron, integrations, config keys,
  changed defaults).

### 2. Modes

| Request | Mode | Compares |
| --- | --- | --- |
| "Test the site" | General | Nothing; full crawl. |
| "Test this branch against master" | Targeted | Code diff against the named branch. |
| "Run a maintenance check" | Maintenance | Lock files against `live` plus release notes. |

Runner flags: `run`, `run --against=<branch>`, `run --maintenance`.

### 3. Doing things, not just viewing them

- **Admin save and create:** done for Statamic and Runway (title only on create; anything validation needs
  beyond that is logged as skipped). Next: fill required fields from `site-check.yaml` values.
- **Front-end forms:** submit every form the crawl finds, per account, with inputs filled by type.
  Destructive forms skipped (`forms.skip`); forms needing specific values get them from `forms.values`.
- Integrations are blocked in the container, so forms that call them may fail. Report these separately
  from upgrade problems.
- The report gains a **Not testable** section: config `not_testable` entries plus anything skipped.

### 4. Stack adapters, configured per project

A stack-neutral core (container, crawl, forms, compare, report) plus an adapter per stack. The adapter
supplies defaults; `site-check.yaml` can override every step.

```yaml
stack: craft            # laravel | statamic | wordpress | craft
runtime: { php: 8.3, node: 22, db: mysql }
env: {}                 # adapter supplies protected keys (DB_HOST vs DB_SERVER etc.)
steps:
  install: []           # adapter default, e.g. craft install + project-config/apply
  seed: []              # adapter generator, then the site's seeders
  after_seed: []        # importers, indexing, other site commands
  build: []
serve: { command: '', port: 8000 }
accounts: []            # front end and admin, with login URL and selectors
admin: {}               # save/create test selectors (adapter default)
forms: { skip: [], values: {} }
not_testable: []
```

| Stack | Schema | Generic seeding | Admin |
| --- | --- | --- | --- |
| Laravel | Schema dump + migrations | Site seeders/factories | None by default |
| Statamic | As Laravel | Blueprint generator (exists) | CP login, save, create |
| WordPress (Bedrock) | `wp core install` + plugin activation | WP-CLI generator for post types and ACF | wp-admin login, save, create |
| Craft 5 | `craft install` + project config apply | Generator from field layouts | CP login, save, create |

Needed for this:
- Split the in-container tasks (`scripts/task.php`) into a core and per-stack adapters, so WordPress and
  Craft don't need Laravel.
- Per-adapter safety checks (env isolation, protected DB/mail keys, blocked hosts).
- Keep Laravel/Statamic working throughout; build WordPress and Craft adapters when a site needs them.

### 5. Seed generator skill

For sites without a scenario seeder. Run once per site; its output is committed, so every run after that is
scripted and comparable.

1. Read the data model (models/migrations, blueprints, ACF JSON, Craft project config).
2. Ask what the code doesn't say: account types, states pages depend on (enrolled, completed, team
   manager), values that need a real format (video IDs, icons), commands to run after seeding.
3. Write the site's seeder and `site-check.yaml` entries, run a seed-only check once, and log anything it
   couldn't seed as not testable.

Generic generators fill every field; this skill writes the site-specific scenarios.

## Nice to have

- Permissions: crawl and save as a limited admin role and check access is allowed or denied as expected.

## Open

- Learning and Families site-check setup is uncommitted. Merging it into the maintenance branch would ship
  the migration flatten with the maintenance release.
- Old Docker containers and volumes under the `testbed-*` project names still exist.

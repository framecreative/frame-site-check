---
name: testbed
description: Test a site's maintenance or dependency updates with the Frame testbed. Use when asked to test the updates, run the testbed, check a maintenance branch, or test against live. Compares the lock files against live, reads the changelogs, runs the seeded container and crawl, then writes and publishes a findings report.
---

# Testbed run

Tests the current branch's dependency changes against `live` in a seeded container, then reports.
A one-line request ("test the maintenance updates") is enough. Use the defaults below; ask only if a
default can't apply.

## Defaults

| Input | Default |
| --- | --- |
| Branch under test | The current branch, including uncommitted changes. |
| Baseline | `live` (or `baseline` in `testbed.yaml`). |
| Runner | `php vendor/framecreative/testbed/bin/testbed`, from the site root. |
| Report format | `docs/report-format.md` in this package. Read it before writing the report. |

## Before starting

Stop and say what's missing (don't work around it) if any of these fail:

- `testbed.yaml`, `.env.testbed` and `database/schema/mysql-schema.sql` exist in the site root.
- `vendor/framecreative/testbed` exists. If not, the site needs `composer install`.
- Docker is running.

Run `git fetch origin live` so the baseline is current.

## Steps

Do every step. If one can't be done, say so in the report; never drop it silently.

1. **Compare.** Run `testbed compare`. It writes `.testbed/changes.json`: every package whose locked
   version differs from the baseline, with `change` (major, minor, patch, added, removed) and `direct`.
2. **Read the changelogs** for every direct package with a major, minor, added or removed change.
   Split them across subagents on a cheaper model (`model: "sonnet"`), a handful of packages each.
   Each returns, per package: breaking changes, behaviour changes and deprecations in the version range,
   and where this site uses the package (grep the site code). Patch and transitive changes are listed
   in the report but not researched, unless a changelog flags a security fix or a known regression.
3. **Map changes to areas.** From the subagent results, list the areas of the site each change could
   affect, as routes or pages (`php artisan route:list` on the host is fine). This is the list the run
   is judged against.
4. **Run.** `testbed run`. It repeats the compare and copies `changes.json` into the report directory.
   The crawl report is at `.testbed/reports/<run>/index.html`, with `results.json` beside it.
5. **Check coverage of the affected areas.** For each area from step 3, find it in `results.json`:
   reached and clean, reached with problems, or not reached (and why).
6. **Investigate problems.** One finding per root cause. Classify per the report format. A problem in an
   area a changed package touches is a candidate Upgrade regression; confirm it against the changelog
   and the code before marking it so.
7. **Report.** Write `.testbed/reports/<run>/findings.md` in the report format, including the Changes
   under test and Testbed issues sections. Publish it as an artifact, following the user's artifact
   instructions.

## Testbed issues: log them, don't fight them

A testbed issue is a failure in running the tests, not in the site: a runner step failing, containers not
starting, preflight failing, seeding errors, the crawler crashing, Algolia unreachable.

- **Retry at most once**, and only for a clearly transient cause (a network timeout, a registry hiccup).
- **Don't change the shared package, `testbed.yaml`, the seeders or the site** to get a run through.
  Fixing the testbed is separate work the user asks for.
- **Log each one** in the findings report under Testbed issues: the step, the exact error, what was tried,
  and the suspected cause. Then stop, or continue with whatever still works (for example, report on the
  compare and changelogs if the run itself failed).
- Seeding gaps the crawl exposes (a page failing because the test data doesn't look like real data) are
  findings classified as Seeding gap, not testbed issues.

## Don't

- Commit, push, merge or switch branches unless the user asks.
- Run the testbed more than once per request, other than the single transient retry above.
- Read or copy any `.env` file other than `.env.testbed`.

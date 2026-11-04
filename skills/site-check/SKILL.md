---
name: site-check
description: Test a site's maintenance or dependency updates with the Frame site check. Use when asked to test the updates, run the site check, check a maintenance branch, or test against live. Compares the lock files against live, reads the changelogs, runs the seeded container and crawl, then writes and publishes a findings report.
---

# Site check run

Tests the current branch's dependency changes against `live` in a seeded container, then reports.
A one-line request ("test the maintenance updates") is enough. Use the defaults below; ask only if a
default can't apply.

## Defaults

| Input | Default |
| --- | --- |
| Branch under test | The current branch, including uncommitted changes. |
| Baseline | `live` (or `baseline` in `site-check.yaml`). |
| Runner | `php vendor/framecreative/frame-site-check/bin/site-check`, from the site root. |
| Report format | `docs/report-format.md` in this package. Read it before writing the report. |

## Before starting

Stop and say what's missing (don't work around it) if any of these fail:

- `site-check.yaml`, `.env.site-check` and `database/schema/mysql-schema.sql` exist in the site root.
- `vendor/framecreative/frame-site-check` exists. If not, the site needs `composer install`.
- Docker is running.

Run `git fetch origin live` so the baseline is current.

## New or continued report

Look for a `findings.md` in `.site-check/reports/` from the past 7 days.

- **Found one, made earlier in this session:** continue it without asking.
- **Found one from another session:** ask the user whether to continue it or start a new report.
- **None found, or a new report chosen:** start a new report.

Continuing keeps the IDs of findings still present, lists the ones no longer present under Resolved since last run,
and updates the artifact linked in that `findings.md`. See "New or continued report" in `docs/report-format.md`.

## Models

Use the cheapest model that can do each job. Pass `model` on every subagent; one without it runs on the session's
model.

| Job | Who |
| --- | --- |
| Lookups: where the site uses a package, finding a file, a route, a config key | Subagent, `model: "haiku"` |
| Reading changelogs (step 2) | Subagents, `model: "sonnet"` |
| Turning `findings.md` into the published page (step 7) | Subagent, `model: "sonnet"` |
| Mapping changes to areas, investigating problems, classifying, writing `findings.md` | The main session |
| Running the site check (step 4) | No model: the runner script, watched with the Monitor tool |

## Steps

Do every step. If the run fails (a "Step failed" line, or no crawl report), stop: see "When the run fails" below.
`findings.md` and the artifact are written only after a complete run.

1. **Compare.** Run `site-check compare`. It writes `.site-check/changes.json`: every package whose locked
   version differs from the baseline, with `change` (major, minor, patch, added, removed) and `direct`.
2. **Read the changelogs** for every direct package with a major, minor, added or removed change.
   Split them across subagents on a cheaper model (`model: "sonnet"`), a handful of packages each.
   Each returns, per package: breaking changes, behaviour changes and deprecations in the version range,
   and where this site uses the package (grep the site code). Patch and transitive changes are listed
   in the report but not researched, unless a changelog flags a security fix or a known regression.
3. **Map changes to areas.** From the subagent results, list the areas of the site each change could
   affect, as routes or pages (`php artisan route:list` on the host is fine). This is the list the run
   is judged against.
4. **Run.** `site-check run`, started with the **Monitor tool** so the user sees where it's up to. The runner and
   crawler mark each milestone with a line starting `▸` (each step, each account crawled, the control panel tests);
   pass on only those, failures and the end. Set `timeout_ms` to 1800000, and use this command from the site root:

   ```bash
   bash -c 'php vendor/framecreative/frame-site-check/bin/site-check run 2>&1 | tee .site-check/run.out \
     | perl -ne '\''$|=1; s/\e\[[0-9;]*m//g; print if /^\s*▸|Step failed|Report:|emails caught|Exception/'\''; \
     echo "▸ Run finished (exit ${PIPESTATUS[0]})"'
   ```

   Don't read the run's output while it runs; the monitor's lines are the progress. If the monitor expires before
   "Run finished", re-arm it on `tail -f .site-check/run.out` with the same filter. Exit 1 at the end only means
   the crawl found problems; a failed step says "Step failed".
   It repeats the compare and copies `changes.json` into the report directory.
   The crawl report is at `.site-check/reports/<run>/index.html`, with `results.json` beside it.
   Control panel save and create tests are the results with an `action`. Creates submit a seeded value for every
   field, so a failed save or create, including a 422, is a finding (a Seeding gap if the seeded value is what
   validation rejected). A `skipped` create (no seeded payload, only a title) or a `denied` one (the account's role
   has no permission) goes under Not testable. A failed save or create has `submitted`: the file in
   `failures/` holding the exact data sent and the response. Cite it in the finding, with the run's seed, so
   the failure can be reproduced with `site-check run --seed=<n>`.
   Emails sent during the run are in `mail.json`, with bodies in `mail/`. An expected email missing (an invite after a
   user create, for example) or a body with an error or a raw template variable is a finding. Queued mail isn't sent:
   no queue worker runs.
5. **Check coverage of the affected areas.** For each area from step 3, find it in `results.json`:
   reached and clean, reached with problems, or not reached (and why).
6. **Investigate problems.** One finding per root cause. Classify per the report format. A problem in an
   area a changed package touches is a candidate Upgrade regression; confirm it against the changelog
   and the code before marking it so.
7. **Report.** Write `.site-check/reports/<run>/findings.md` in the report format, covering this run only
   (plus Resolved since last run when continuing). Publish it as an artifact, following the user's artifact
   instructions: a new artifact for a new report, the same one for a continued report. Add its link to the
   Artifact line in `findings.md`.

## When the run fails

A site check issue is a failure in running the tests, not in the site: a runner step failing, containers not
starting, preflight failing, seeding errors, the crawler crashing, Algolia unreachable. Seeding fails the run when
a Runway model or relation can't be saved or a site seeder exits non-zero; the errors follow "Seeding failed:".

- **One re-run per request, whatever the cause.** Use it for either:
  - a clearly transient cause (a network timeout, a registry hiccup): re-run unchanged;
  - an obvious fix: the cause is clear from the error, and the fix is a few lines in `site-check.yaml`
    (`values`, `counts`, `runway.skip`…), a site seeder or the package's generators. Make it, then re-run.
- **Stop and ask the user instead** when the fix spans several files, needs a new generator or seeder, touches the
  site's own code, or the cause isn't clear. Give the error, the suspected cause and the change you propose.
- **If the re-run fails, stop.** No second fix or re-run.
- **On a failed run, write no `findings.md` and publish nothing.** A continued report's previous `findings.md` and
  artifact stay as they are. The reply gives the error, any fix made, and the path to `site-check.log`.
- **Log each issue** in `.site-check/reports/<run>/site-check.log`, never in the findings report. The runner
  already appends failed steps (time, step, exit code, end of the output); add the suspected cause and any fix made
  (file and change) below its entry, and log anything the runner can't see.
- **Don't report changes made to the site check** (the package, `site-check.yaml`, the seeders) in the findings
  report. List them in the reply.
- Seeding gaps the crawl exposes (a page failing because the test data doesn't look like real data) are
  findings classified as Seeding gap, not site check issues.

## Don't

- Commit, push, merge or switch branches unless the user asks.
- Run the site check more than twice per request: the run, plus the one re-run above.
- Read or copy any `.env` file other than `.env.site-check`.

# Site check findings report format

Every site check run produces two things:

- **The crawl report** (`.site-check/reports/<run>/index.html`, plus `results.json`, `changes.json`, `laravel.log`
  and screenshots).
  Raw evidence, generated automatically. Not the deliverable.
- **`mail.json`** in the same folder: every email sent during the crawl and control panel tests (caught by Mailpit,
  never delivered), with each body in `mail/<n>.html`.
- **`site-check.log`** in the same folder: problems running the tests (a failed step, a crashed crawler), not site
  problems. The runner appends failed steps itself. Never part of the findings report.
- **The findings report** (`.site-check/reports/<run>/findings.md`). The deliverable: what the run found,
  classified and de-duplicated, written so someone who hasn't followed the work can act on it.
  It always follows this format.

## Rules

- **Report what this run found.** Findings from earlier runs appear only when continuing a report (below): in
  Findings if this run still shows them, otherwise in Fixed.
- **One finding per root cause.** The same error on many pages or accounts is one finding; list where it occurs.
- **Never mark a finding Confirmed without evidence in the code** (a `file:line` or the exact config) or a
  reproduction. Anything else is "Needs investigation".
- **Give every finding a source** (below), so a failure the run hit never reads the same as something read in a
  changelog.
- **Classify every finding** (below). Seeding gaps are site check problems, not site problems; keep them separate.
- **Report a changelog change only if it applies to this site**: the site uses the changed method, config key or
  behaviour, with the `file:line`. Changes the site doesn't use aren't reported, only counted.
- **Say what to do**, not just what happened.
- **Every error comes with where it happened:** the source file and line, or the failing URL. A bare message
  isn't evidence.
- Short, literal sentences. Australian English.

## Sources

| Source | Meaning |
| --- | --- |
| Broken | The run hit it: an error, a failed save or create, a bad response, a broken email. |
| Bug | Found by reading the code; the run didn't show it failing. |
| Changelog | A dependency change the site's code relies on (Changelog flags). Nothing failed and nothing was contacted. |

A changelog change in code the run covered and that failed is a Broken finding, not a flag; its evidence cites the
changelog entry.

## Classifications

| Classification | Meaning |
| --- | --- |
| Site bug | Wrong behaviour in the site's own code or config that real users or editors can hit. |
| Upgrade regression | A site bug introduced by the dependency changes being tested (the maintenance diff against `live`). |
| Vendor | A problem inside a third-party package (Statamic, Runway, an addon). Report upstream or ignore. |
| Seeding gap | The test data doesn't look like real data, so the page fails in a way real content wouldn't. Fixed in the site check, not the site. |
| Environment | Caused by the container setup, not the site. Fixed in the site check. |

## Status

| Status | Meaning |
| --- | --- |
| Confirmed | Cause identified with code evidence or a reproduction. |
| Needs investigation | Seen in the run; cause not yet established. |

## Severity

| Severity | Meaning |
| --- | --- |
| High | A page or feature is broken for users, or the site can be taken down. |
| Medium | Breaks in a specific, reachable situation, or for editors in the control panel. |
| Low | Cosmetic, or only reachable in unlikely situations. |

## Template

```markdown
# Site check findings: <site>, <date>

## Run
- Branch / commit: <branch> @ <short hash> (<"clean" or "with uncommitted changes">)
- Baseline: <branch> @ <short hash>
- Seed: <n> (`site-check run --seed=<n>` rebuilds the same data)
- Crawl report: <path to index.html>
- Pages crawled: <total>, by account: <account: n, …>
- Pages with problems: <n>
- GET routes not reached: <n> (see Coverage)
- Control panel: <n> saves (<n> failed), <n> creates (<n> failed, <n> skipped)

## Summary
<Two to four sentences: are the updates safe to deploy, and the findings that need action first.>

## Changes under test
- Composer: <n> changed (<n> direct; <n> major). Node: <n> changed (<n> direct; <n> major). From `changes.json`.

| Package | From → To | Changes that apply to the site | Areas affected | Crawl result |
| --- | --- | --- | --- | --- |
| <package> | <from> → <to> | <the changes the site's code relies on, with flag or finding IDs; or "<n> changes checked, none used by the site"> | <routes or pages> | <Clean, Problems (finding IDs), or Not reached (why)> |

<One row per researched package. Then one line listing the patch and transitive changes that weren't researched.>

## Findings

### <ID>. <Short title>
- **Source:** <Broken | Bug>
- **Classification:** <Site bug | Upgrade regression | Vendor | Seeding gap | Environment>
- **Status:** <Confirmed | Needs investigation>
- **Severity:** <High | Medium | Low>
- **Where:** <file:line, route or URL; accounts affected>
- **Evidence:** <the exact error, the source file and line it was thrown from (from the mapped stack in
  results.json), the first relevant stack frames, and the code or config that causes it>
- **Action:** <what to do, and where>

## Changelog flags
<Only dependency changes the site's code relies on that the run didn't show failing. None → "None: every change
checked is unused by the site, or covered by the run.">

### <ID>. <package> <version>: <what changed>
- **Source:** Changelog
- **Where the site relies on it:** <file:line, and what that code does>
- **What will happen:** <the effect on that code after the update>
- **Run coverage:** <not reached, and why (e.g. the integration is unconfigured in the container); or reached and
  clean, and why that doesn't settle it>
- **Action:** <the change to make, or how to check it before deploying>

## Coverage
- Routes not reached that matter: <list, with why (no seeded data, behind a form, iframe…)>
- Seeding gaps: <fieldtypes with no generator, tables left empty that pages depend on>
- Not testable: <control panel creates skipped (and the fields they needed), types in admin.skip, anything else
  the run couldn't exercise>

## Fixed
<Findings fixed while working on this report. Never left in Findings or Changelog flags. One line each:
- **<ID>. <title>:** <what fixed it (file:line or commit)>. <"Confirmed by run <run>" when a later run no longer
  shows it, or "Not yet re-run".>
Leave the section out if nothing is fixed.>

## Commands to run after deploy
<Only when fixes made while working on this report need them: migrations, cache or Stache clears, search
re-imports, asset builds, config to set on the server. One line each: the command, and the finding it's for.
Carried over when continuing a report until the user says they've been run. None → leave the section out.>

Artifact: <the published page's link, once published>
```

IDs are the site prefix and a number (`L1`, `F3`).

## New or continued report

- **New report:** IDs start at 1, the Fixed list starts empty, and a new artifact is published.
- **Continued report** (re-running to check fixes): a finding still present keeps its ID; a new one takes the next
  unused number. The same artifact is updated, using the link in the previous `findings.md`.

## Fixed findings

A finding is fixed when a change for it has been made in the site, or a later run no longer shows it.

- **When a fix is made,** move the finding out of Findings (or Changelog flags) into Fixed straight away, marked
  "Not yet re-run". Don't leave it in Findings with a note, and don't change its ID.
- **When a re-run no longer shows it,** mark it "Confirmed by run <run>". A finding the run no longer shows that
  wasn't knowingly fixed also moves to Fixed, with "No change made; not seen in run <run>".
- **When a re-run still shows it,** move it back to Findings with the same ID, and say the fix didn't hold.
- **Fixed items stay in Fixed** for the rest of a continued report. A new report drops them.
- A finding is never in Fixed and Findings at once.

## Published report

When the findings report is published as a page:

- Fixed is its own section, in the template's order, and every item in it is green: `#2c7551` on a light background
  and `#6cc191` on a dark one (card edge, ID and a "Fixed" label). Nothing outside Fixed is green.
- Every finding card shows its source as a label (Broken, Bug, Changelog) beside its ID.
- Changelog flags are cards with a neutral grey edge and no severity colour, so they don't read as failures.
- Commands to run after deploy is one code block, ready to copy.

# Testbed findings report format

Every testbed run produces two things:

- **The crawl report** (`.testbed/reports/<run>/index.html`, plus `results.json`, `changes.json`, `laravel.log`
  and screenshots).
  Raw evidence, generated automatically. Not the deliverable.
- **The findings report** (`.testbed/reports/<run>/findings.md`). The deliverable: what the run found,
  classified and de-duplicated, written so someone who hasn't followed the work can act on it.
  It always follows this format.

## Rules

- **One finding per root cause.** The same error on many pages or accounts is one finding; list where it occurs.
- **Never mark a finding Confirmed without evidence in the code** (a `file:line` or the exact config) or a
  reproduction. Anything else is "Needs investigation".
- **Classify every finding** (below). Seeding gaps are testbed problems, not site problems; keep them separate.
- **Say what to do**, not just what happened.
- Short, literal sentences. Australian English.

## Classifications

| Classification | Meaning |
| --- | --- |
| Site bug | Wrong behaviour in the site's own code or config that real users or editors can hit. |
| Upgrade regression | A site bug introduced by the dependency changes being tested (the maintenance diff against `live`). |
| Vendor | A problem inside a third-party package (Statamic, Runway, an addon). Report upstream or ignore. |
| Seeding gap | The test data doesn't look like real data, so the page fails in a way real content wouldn't. Fixed in the testbed, not the site. |
| Environment | Caused by the container setup, not the site. Fixed in the testbed. |

## Status

| Status | Meaning |
| --- | --- |
| Confirmed | Cause identified with code evidence or a reproduction. |
| Needs investigation | Seen in the run; cause not yet established. |
| Fixed | Resolved, with where the fix lives. |

## Severity

| Severity | Meaning |
| --- | --- |
| High | A page or feature is broken for users, or the site can be taken down. |
| Medium | Breaks in a specific, reachable situation, or for editors in the control panel. |
| Low | Cosmetic, or only reachable in unlikely situations. |

## Template

```markdown
# Testbed findings: <site>, <date>

## Run
- Branch / commit: <branch> @ <short hash> (<"clean" or "with uncommitted changes">)
- Baseline: <branch> @ <short hash>
- Crawl report: <path to index.html>
- Pages crawled: <total>, by account: <account: n, …>
- Pages with problems: <n>
- GET routes not reached: <n> (see Coverage)

## Summary
<Two to four sentences: are the updates safe to deploy, and the findings that need action first.>

## Changes under test
- Composer: <n> changed (<n> direct; <n> major). Node: <n> changed (<n> direct; <n> major). From `changes.json`.

| Package | From → To | What changed that matters | Areas affected | Crawl result |
| --- | --- | --- | --- | --- |
| <package> | <from> → <to> | <breaking or behaviour changes, or "nothing relevant"> | <routes or pages> | <Clean, Problems (finding IDs), or Not reached (why)> |

<One row per researched package. Then one line listing the patch and transitive changes that weren't researched.>

## Findings

### <ID>. <Short title>
- **Classification:** <Site bug | Upgrade regression | Vendor | Seeding gap | Environment>
- **Status:** <Confirmed | Needs investigation | Fixed>
- **Severity:** <High | Medium | Low>
- **Where:** <file:line, route or URL; accounts affected>
- **Evidence:** <the error, and the code or config that causes it>
- **Action:** <what to do, and where>

## Coverage
- Routes not reached that matter: <list, with why (no seeded data, behind a form, iframe…)>
- Seeding gaps: <fieldtypes with no generator, tables left empty that pages depend on>

## Testbed issues
<Problems running the tests, not site problems. Per issue: the step, the exact error, what was tried, the
suspected cause. "None" if none.>

## Testbed changes made during this run
<Fixes to the shared testbed or the site's testbed.yaml/seeders, so the next run is comparable. "None" if none.>
```

IDs are the site prefix and a number (`L1`, `F3`) and stay stable across runs of the same piece of work.

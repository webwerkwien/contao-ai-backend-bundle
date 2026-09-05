# Review instructions

How to review a change in this repository. Findings do not approve or block on
their own — a human decides.

## Passes

Run three passes and tag every finding with the pass it came from.

**Bugs** — logic errors, broken edge cases, subtle regressions. Pay attention to
anything that behaves differently depending on which optional Contao bundle is
installed; that is where this repository has actually broken before.

**Security** — missing authorisation on a route or tool, CSRF gaps, secrets or
personal data reaching logs or error messages, unvalidated input reaching a
console command.

**Compliance** — does the change do what its commit message and CHANGELOG entry
say it does, no more and no less? Undeclared behaviour changes belong in this
pass, not in Bugs.

## What Important means here

Reserve **Important** for findings that would break behaviour, leak data, or ship
a defect to an installation that differs from the developer's own. Everything
else is a nit.

Specifically Important, because it has happened:

- A service registered in `services_<plugin>.yaml` that is not also listed under
  `exclude` in `services.yaml` — see the note in `CLAUDE.md`.
- A verification claim without the command output that backs it.
- A check that cannot fail under the conditions it runs in — including a control
  in a test that has an explicit service definition.
- A user-visible string added outside `contao/languages/`, or added to only one
  of the two languages.

## Cap the nits

At most five nits per review; summarise the rest as a count.

## Do not report

- Style and naming, unless it contradicts a convention in `CLAUDE.md`.
- Anything `composer ci` already enforces — it is green, so a finding it would
  have caught means the command was not run, and that is the finding.
- The two documented `ignoreErrors` entries in `phpstan.neon.dist`. Both carry
  their reason; propose a change to the reason, not to the entry.

## On test changes

Treat any edit to an existing test as a finding worth a look. Weakening a check
to make a fix pass is the failure mode this section exists for. A deleted or
loosened assertion needs a reason in the commit message.

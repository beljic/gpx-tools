---
name: php-standards
description: Enforce PHP 8.3+, PSR-12, and SOLID conventions for the beljic/gpx-tools library. Use whenever editing or creating any .php file under src/, tests/, or bin/.
---

# PHP standards for beljic/gpx-tools

Use this skill on **every PHP edit** in this repo.

## When to use

- Creating or editing any `.php` file in `src/`, `tests/`, or `bin/`.
- Reviewing a PHP patch.
- Designing a new class, interface, value object, or enum.

## How to use

1. **Read `references/coding-standards.md`** before proposing changes — it contains the concrete rules and the examples from this codebase. Do not rely on memory alone; the rules are specific.
2. Apply the rules to the touched code only. Do not reformat or "modernize" untouched code.
3. After patching, report in the format defined at the bottom of `references/coding-standards.md`.

## Priority order

When rules conflict, follow this order top to bottom:

1. **Behavior preservation** — never change observable output, public API signatures, or value object shape without explicit instruction.
2. **PHP 8.3+ idioms** — `readonly` classes, constructor promotion, enums, `#[\Override]`, `match`, named args, `json_validate`, typed properties.
3. **PSR-12** — formatting, naming, file structure (applied only to lines you touch in existing files; fully applied to new files).
4. **SOLID** — single responsibility, interface segregation, dependency inversion via `HttpClientInterface` / `CacheInterface`.
5. **Cleanup** — only when a specific problem is named. Do not opportunistically refactor.

## Hard rules (no exceptions)

- `declare(strict_types=1);` in every new `.php` file under `src/` and `tests/`.
- No framework imports in `src/`. No `Illuminate\*`, no `Symfony\*`.
- Value objects (`src/Data/*`) stay `readonly` and immutable.
- `Processor/` and `Analyzer/` return new instances; never mutate input.
- No network or filesystem access from tests. Use `NullCache` or a stub `HttpClientInterface`.
- No new public constructor params inserted in the middle — append at the end with a default.

Details, examples, and the response template are in `references/coding-standards.md`.

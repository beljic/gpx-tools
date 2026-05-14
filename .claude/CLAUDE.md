# beljic/gpx-tools

PHP library for parsing, processing, and analyzing GPX tracks. **Not an application — a Composer package.**

## What this is

- Composer library, MIT licensed, open source.
- PHP **8.3+** required (`composer.json`).
- PSR-4: `Beljic\GpxTools\` → `src/`, `Beljic\GpxTools\Tests\` → `tests/`.
- Zero framework dependencies in `src/`. External integrations only via PHP extensions (`ext-curl`, `ext-dom`, `ext-json`, `ext-simplexml`) and injectable interfaces.
- CLI entrypoint: `bin/gpx` — this is a thin wrapper, not the library.

## Install / build

```bash
composer install
```

## Run tests

```bash
./vendor/bin/phpunit
```

PHPUnit 11. Unit tests only. No external HTTP. Fixtures live in `tests/fixtures/`.

The user runs tests and reports results — do not run the suite yourself unless explicitly asked.

## Architecture decisions

- **Framework-agnostic core**: `src/` must not import Laravel, Symfony, or any framework. Adapters for frameworks live outside this package (e.g. consumer projects).
- **Value objects are `readonly` classes** with constructor promotion (see `Data/TrackPoint.php`, `Data/ParsedGpx.php`, `Data/TrackStats.php`). No setters. No mutation.
- **Enums for finite domains** (`Data/Sport.php`, `Data/PlaceCategory.php`, `Data/EffortLevel.php`) — backed string enums with factory methods (`Sport::fromGpxType`).
- **Injectable interfaces for I/O**: `Http/HttpClientInterface`, `Cache/CacheInterface`. Concrete `CurlHttpClient`, `FileCache`, `NullCache` are defaults; consumers swap them.
- **Processors return new instances** — `GpxSimplifier`, `GpxCleaner` produce a new `ParsedGpx`, never mutate input.
- **`#[\Override]` attribute** on interface implementations (`FileCache`, `NullCache`, `CurlHttpClient`).
- **Module layout**:
  - `Data/` — value objects + enums (no logic beyond construction/derivation)
  - `Parser/` — `GpxParser`, `GpxWriter` (XML I/O)
  - `Processor/` — pure transformations on `ParsedGpx`
  - `Analyzer/` — derived metrics (`TrackStats`, `RouteAnalysis`, `TrainingReport`)
  - `External/Nominatim/`, `External/Overpass/` — OSM clients, depend on `HttpClientInterface` + `CacheInterface`
  - `Support/` — pure helpers (`Geo::haversineKm`)
  - `Http/`, `Cache/` — interfaces + default implementations

## What NOT to do

- **No framework imports in `src/`** — no `Illuminate\*`, no `Symfony\*`, no `Laravel\*`.
- **No breaking changes to public API** — class names, namespaces, public method signatures, constructor parameters, and public properties of value objects are part of the contract. Add new optional params at the end; never reorder.
- **No global state** in `src/` — no static caches, no singletons, no `$GLOBALS`.
- **No direct HTTP / filesystem calls** from `Processor/` or `Analyzer/`. Route I/O through `HttpClientInterface` / `CacheInterface`.
- **No mutation of value objects** — return a new instance with the new state.
- **No untyped code** — every new file declares `strict_types=1` and types every parameter, return, and property.
- **No tests that hit the network** — use `NullCache` or stub `HttpClientInterface` for `External/*`.
- **No CLI logic in `src/`** — `bin/gpx` is the only place that reads `$argv`, echoes, or calls `exit`.

## Skill

Coding standards live in `.claude/skills/php-standards/`. The skill auto-applies on PHP edits.

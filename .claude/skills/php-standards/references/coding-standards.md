# Coding standards — beljic/gpx-tools

Concrete rules. Examples are from real files in this repo.

---

## 1. PHP 8.3+ target

`composer.json` requires `php >= 8.3`. Use 8.3 features. Do not write code that would also run on 7.x.

### Use

- **`readonly` classes** for value objects.
  ```php
  // src/Data/TrackPoint.php
  readonly class TrackPoint
  {
      public function __construct(
          public float $lat,
          public float $lon,
          public ?float $ele = null,
          public ?DateTimeImmutable $time = null,
          // ...
      ) {}
  }
  ```
- **Constructor property promotion** with `readonly` (see `NominatimClient`, `FileCache`, `TrackStatsCalculator`).
- **Backed enums** with methods (see `Data/Sport.php`):
  ```php
  enum Sport: string
  {
      case Running = 'running';
      public static function fromGpxType(?string $type): self { /* match */ }
      public function isPaceBased(): bool { /* ... */ }
  }
  ```
- **`match`** instead of `switch` (see `Sport::fromGpxType`).
- **Named arguments** for value object construction when there are many optional params (see `TrackStatsCalculator::calculate` returning `new TrackStats(distanceKm: ..., elevationGainM: ...)`).
- **`#[\Override]`** on every method that implements an interface or overrides a parent (see `FileCache::get`, `NullCache::*`, `CurlHttpClient::*`).
- **`json_validate()`** before `json_decode()` (see `NominatimClient::reverse`).
- **First-class callable syntax** (`Geo::haversineKm(...)`) when passing methods.
- **Numeric literal separators**: `1_100_000` (see `NominatimClient` rate limit).

### Avoid

- `array()` long form — use `[]`.
- `list($a, $b) = ...` — use `[$a, $b] = ...`.
- `func_get_args()` — use variadics or named params.
- `is_null($x)` / `!is_null($x)` — use `$x === null` / `$x !== null` (consistent with the codebase).
- Untyped properties or untyped returns. Every signature is fully typed in this repo.
- `@property` PHPDoc to fake types — use real types.

---

## 2. `declare(strict_types=1)`

**Every `.php` file** under `src/` and `tests/` starts with:

```php
<?php

declare(strict_types=1);

namespace Beljic\GpxTools\...;
```

Blank line between `<?php` and `declare`. Blank line between `declare` and `namespace`. This matches every existing file.

`bin/gpx` also uses `declare(strict_types=1);`.

---

## 3. PSR-12 rules

Apply fully to **new files**. In existing files, apply **only to lines you touch** — do not reformat untouched code.

- 4-space indentation, no tabs.
- Opening `{` on the next line for classes, interfaces, enums, methods. Same line for control structures.
- One blank line after `namespace`, one blank line after the `use` block.
- `use` statements grouped, no blank lines between them, alphabetized within the group.
- Class names: `PascalCase`. Methods/properties: `camelCase`. Constants: `UPPER_SNAKE_CASE` or `PascalCase` for enum cases.
- One class per file. Filename = class name.
- No trailing whitespace. Final newline at EOF.
- Soft 120-column line limit. Vertical alignment of `=` in constructor promotion is used in this codebase (see `TrackPoint`, `Sport::fromGpxType` match arms) — preserve it when present, do not force-align if not.

---

## 4. SOLID applied here

### Single Responsibility

- `GpxParser` parses XML → `ParsedGpx`. It does not compute stats.
- `TrackStatsCalculator` computes stats from `ParsedGpx`. It does not parse.
- `GpxSimplifier` reduces point count. It does not clean attributes (that's `GpxCleaner`).
- `PlaceClassifier` maps a Nominatim response array → `Place[]`. It does not call HTTP.

When a class does two things, split. Do not add a method that belongs elsewhere.

### Open/Closed

Extend by composition, not by editing. New analyzers go in `src/Analyzer/`, new processors in `src/Processor/`, new external clients in `src/External/<Service>/`. Do not add `if ($mode === 'new')` branches to existing classes.

### Liskov Substitution

`NullCache` and `FileCache` are fully interchangeable for `CacheInterface`. Any new cache must honor the same semantics: `get` returns `null` on miss, `has` is consistent with `get`. Do not throw from `get`/`has`.

### Interface Segregation

`CacheInterface` has three methods (`get`, `set`, `has`). `HttpClientInterface` has two (`get`, `post`). Keep them tight. Do not add `delete`, `clear`, or batch methods unless a concrete consumer needs them.

### Dependency Inversion

Classes that do I/O depend on **interfaces**, injected via the constructor:

```php
// src/External/Nominatim/NominatimClient.php
public function __construct(
    private readonly HttpClientInterface $http,
    private readonly CacheInterface $cache = new NullCache(),
    private readonly int $rateLimitUs = 1_100_000,
) {}
```

Default values use concrete classes with no required config (`new NullCache()`) so the dependency stays optional for simple call sites. Do not inject a service container.

---

## 5. Value object / enum conventions

### Value objects (`src/Data/*`)

- `readonly class` (not `final readonly class` in this codebase — match existing style).
- All properties `public` and promoted in the constructor.
- All properties typed. Nullable when optional. Default to `null` for optional fields.
- No setters. No `with*()` methods unless requested.
- No business logic beyond pure derivations (e.g. `Sport::isPaceBased()` is fine; HTTP calls are not).
- Collections are typed via PHPDoc `@param TrackPoint[] $track` since PHP has no generics — see `ParsedGpx`.

### Enums (`src/Data/Sport.php`, `Data/PlaceCategory.php`, `Data/EffortLevel.php`)

- Backed string enums. The string value is stable and may be persisted.
- Cases in `PascalCase`. Values in `snake_case`.
- Static factory methods named `fromX(...)` for parsing.
- Predicates named `isX(): bool`.
- Always include an `Unknown` case for parsing user data so `from()` callers can use `tryFrom()` or a `match` with a `default`.

### Immutable returns

Processors and analyzers **return a new instance**:

```php
// src/Processor/GpxSimplifier.php
return new ParsedGpx(
    track:     $kept,
    waypoints: $gpx->waypoints,
    name:      $gpx->name,
    type:      $gpx->type,
);
```

Never modify `$gpx` in place. Never modify input arrays via reference.

---

## 6. Interface injection pattern (new dependencies)

When adding a new external dependency:

1. **Define an interface** in `src/<Layer>/<Name>Interface.php`. Keep it tight — only the methods you actually call.
2. **Provide a default concrete** (`Curl<X>Client`, `File<X>`, `Null<X>`) alongside.
3. **Inject via constructor** with `private readonly Type $name`.
4. **Default to a safe no-op** (`NullCache`) where appropriate so the dep is optional.
5. **Add `#[\Override]`** on every interface method in the concrete implementation.

Do not pull in Guzzle, PSR-7, PSR-18, PSR-6, or PSR-16 wrappers. The two in-house interfaces are deliberately minimal. If a consumer wants PSR-18, they write an adapter outside this package.

---

## 7. Framework integration boundary

- `src/` — **library**. No CLI, no echo, no `exit`, no `$_SERVER`, no `$argv`. No framework. No globals.
- `bin/gpx` — **CLI boundary**. Reads `$argv`, prints to stdout, calls `exit`. Wires concrete `FileCache` + `CurlHttpClient` into the library classes. This is the only place that may do I/O setup.
- `tests/` — **PHPUnit**. May use fixtures from `tests/fixtures/`. May not hit the network.

If you find yourself wanting to add CLI flags inside `src/`, stop. Add it to `bin/gpx` instead and pass the parsed values into library classes.

---

## 8. Mutation policy

| Class type           | Returns                    | Mutates input |
|----------------------|----------------------------|---------------|
| `Data/*` VO          | n/a (constructor only)     | never         |
| `Parser/*`           | new `ParsedGpx` / XML str. | never         |
| `Processor/*`        | new `ParsedGpx`            | never         |
| `Analyzer/*`         | new stats/report VO        | never         |
| `External/*` client  | array / VO / null          | never         |
| `Cache/*`            | string / null / void / bool | own storage only |
| `Http/*`             | string / null              | none          |

If a method has no return type other than `void`, it must not be in `Processor/` or `Analyzer/`.

---

## 9. Test requirements (PHPUnit 11)

- Tests live in `tests/Unit/`. Namespace `Beljic\GpxTools\Tests\Unit`.
- Class name = `<ClassUnderTest>Test`.
- Extend `PHPUnit\Framework\TestCase`.
- `declare(strict_types=1);` at top.
- One behavior per test method. Method name reads as a sentence: `testCalculatesDistanceFromFixture`.
- Use `tests/fixtures/sample.gpx` for parser/stats tests; do not generate large fixtures inline unless needed.
- **No network**. For `External/Nominatim/*` and `External/Overpass/*` tests, classify or stub:
  - `PlaceClassifier` is pure — test directly with hand-built arrays (see `PlaceClassifierTest`).
  - `NominatimClient` / `OverpassClient` — inject a stub `HttpClientInterface` returning canned JSON, paired with `NullCache`.
- **No trivial tests** — skip `testClassExists`, getter round-trips, string concat. Cover behavior: parsing edge cases, elevation noise filter, moving-time threshold, enum factories, classifier mappings.
- Use `assertEqualsWithDelta` for floats (see `TrackStatsCalculatorTest`). Do not compare floats with `assertSame` / `assertEquals`.

Do not run the test suite yourself unless asked — the user runs `./vendor/bin/phpunit` and reports back.

---

## 10. Response format after a patch

When you finish a code change, reply with this structure. Skip empty sections.

```
Changed
- <file:line> — <one-line what>

Why
- <root cause or motivation, 1-2 bullets>

Compatibility risks
- <public API changes, new required params, behavior shifts — or "none">

Tests to run
- ./vendor/bin/phpunit
- <specific test class if you added one>

Follow-ups (optional)
- <named, specific problems you saw but did not touch>
```

No preamble. No restating the prompt. No trailing summary.

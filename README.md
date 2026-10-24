# dev-tools

Development checks for the SugarCommerce family and the apps built on it: a docblock coverage report that can gate a build, a headless-Chrome smoke test that loads every page and fails on any console error, and small real demo media — WAV, PNG, PDF, ZIP — made in code, the same bytes on every run.

Install it as a dev dependency. Nothing here runs in production.

## Why

**"Add docblocks" never finishes as a habit.** It finishes as a list. `docblocks` prints every class, interface, trait, enum, function and method with nothing written above it, across the whole tree at once, and exits 1 while the list is not empty — so the day somebody adds an undocumented method is the day the build says so, not the day a reviewer happens to notice.

It tokenises rather than pattern-matches, because what fools a regular expression is exactly what a modern codebase is full of: `Foo::class` contains the `class` keyword, `use function Foo\{ bar };` contains `function`, an attribute like `#[\NoDiscard( '…' )]` sits between a docblock and the method it documents, and closures and anonymous classes have no name to document.

**Unit tests do not run a page's scripts against its own markup.** A template test renders HTML, a JS test imports a module, and neither notices a `const items` inside `render()` that shadows the function `items()` and throws on every page. Only a browser does. `smoke` seeds a scratch database, starts the app's own server, opens every page in headless Chrome and reads the console. An HTTP 200 says nothing; a console with no errors says a lot more.

It is built around the ways this goes wrong:

- **A fixed port** tests whatever an earlier run left listening, against a database already deleted. The port is asked of the OS.
- **A pipe from Chrome** never closes: its helper processes outlive the browser and hold it open. Each page logs to a file.
- **Waiting for Chrome to exit.** `--dump-dom` prints when virtual time runs out and headless Chrome can linger after. Each page has a deadline, and stragglers holding its profile are killed.
- **One shared profile** makes Chromes queue on its lock. Each page gets its own, so pages load side by side.
- **A PHP warning** means a page half-rendered, even if every script it got ran cleanly. The server's error log is read too.

## Features

- 📝 **`docblocks`** — every undocumented declaration as `path:line  name`, then `N missing`; exit 1 while any are.
- 🧩 **Attribute- and closure-aware** — steps over `#[…]` (nested brackets and all), skips `::class`, `use function`, closures and anonymous classes, and keeps line numbers true.
- 🌐 **`smoke`** — seed, serve, load each page in headless Chrome, fail on `Uncaught`, `Error` or `Refused` in the console.
- 🔌 **Generic** — the seed command, the server command and the pages are arguments; pages can be printed by a command run after seeding, for addresses that depend on seeded data.
- 🧹 **Self-cleaning** — a scratch directory per run, removed afterwards unless `--keep`; free port; per-page profiles; deadlines.
- 🪵 **PHP error log** — read after the run, expected lines ignorable by text or regex, the rest tallied commonest first.
- 🎵 **`DemoMedia`** — a seed's files as real media, chosen by name: a chord, artwork, a one-page PDF, a ZIP of stems. Byte-identical on every run.

## Requirements

PHP 8.5+ with GD. `smoke` needs Chrome or Chromium, and `pkill` (macOS, Linux). `DemoMedia` uses [sugarcommerce/text-pdf](https://github.com/sugarcommerce/text-pdf) and [sugarcommerce/zip-stream](https://github.com/sugarcommerce/zip-stream), installed with it.

## Installation

```bash
composer require --dev sugarcommerce/dev-tools
```

## Docblocks

```bash
vendor/bin/docblocks src bin        # roots default to src
```

```
src/Http/Thing.php:42  render

1 missing
```

Exits 0 when nothing is missing and 1 otherwise. From PHP:

```php
use SugarCommerce\DevTools\Docblocks;

$missing = Docblocks::scan( [ 'src' ] );   // [ [ 'file' => …, 'line' => 42, 'name' => 'render' ], … ]
Docblocks::in( $source );                    // one piece of source
echo Docblocks::report( $missing );
```

It only asks whether a docblock is there, not what it says.

## Smoke

```bash
vendor/bin/smoke \
    --env=DB_PATH={work}/smoke.db --env=LOCAL_DEV=1 \
    --seed='php bin/seed.php --fresh' \
    --serve='php -d error_log={work}/php-errors -S 127.0.0.1:{port} -t public router.php' \
    --php-errors={work}/php-errors --ignore='Stripe API 401' \
    --page=/ --page=/orders \
    --pages-from='bin/smoke-pages.sh'
```

```
ok   /
ok   /orders
FAIL /s/demo
     "Uncaught TypeError: items is not a function", source: http://127.0.0.1:61234/assets/cart.js (179)
```

| Option | |
|---|---|
| `--seed=CMD` | Run once, first. Its output is saved to `{work}/seed.txt`; a non-zero exit stops the run and prints it. |
| `--serve=CMD` | The server, with `{port}` (and `{work}`) filled in. Waited on until it accepts a connection. |
| `--page=PATH` | A page to load. Repeats; a bare argument is a page too. Always a path on the server under test: one without a leading `/` is given one. |
| `--pages-from=CMD` | Run after the seed; prints one path per line. Gets `SMOKE_WORK`, `SMOKE_SEED` and `SMOKE_PORT`. |
| `--env=NAME=VALUE` | Set for every command. Repeats; `{work}` is filled in. |
| `--php-errors=FILE` | An error log to read after the run. Any line left fails it. |
| `--ignore=TEXT` | A log line that is expected: plain text, or `/a regex/`. Repeats. |
| `--chrome=PATH` | Otherwise `$CHROME`, then the macOS app, then `chromium`/`google-chrome` on the `PATH`. |
| `--host`, `--port` | `127.0.0.1`, and a free port. |
| `--parallel=6` | Pages loaded at once. |
| `--budget=3000` | Milliseconds of virtual time per page. |
| `--timeout=20` | Seconds before a page's browser is killed. |
| `--keep` | Leave the scratch directory (logs, database) and print where it is. |

`--seed`, `--serve` and `--pages-from` are shell commands, run as written: put only your own commands there, never anything a page or a request supplied. What comes back — page paths, console messages, log lines — is treated as data: a path cannot point the browser at another host, and control characters are replaced before anything is printed.

Exits 0 when every page is clean, 1 on any console error, PHP log line or failed seed, and 2 when it could not run (no Chrome, a server that did not start, a bad option).

## Demo media

A seed that uploads text files named `.wav` and `.pdf` gives every player and preview in a local install nothing to show. `DemoMedia` makes the real thing, small, in code — no binary blobs in the repository:

```php
use SugarCommerce\DevTools\DemoMedia;

$media = new DemoMedia(
    note: 'Acme demo file — made by bin/seed.php, not a real product.',   // under each PDF's heading
    creator: 'Acme seed',                                                 // the PDF's Creator
    placeholder: "Acme seed file\n{name}\nPlaceholder content.\n",          // any other extension, and each ZIP's README
);

$body = $media->for( 'Midnight Drive - Master.wav' );
$key  = hash( 'sha256', $body );
```

| Name | Bytes |
|---|---|
| `*.wav` | Three seconds of 16-bit mono at 22,050 Hz: a chord whose root comes from the name, faded in and out; pulsed when the name contains "beat". |
| `*.png` | 600 × 600 artwork in the name's colours, with the name across it. |
| `*.pdf` | One page headed with the name: licence terms when it contains "licence", a short guide otherwise. |
| `*.zip` | A folder named for the archive: three one-second stems and a README. |
| anything else | The placeholder, with `{name}` filled in. |

The same name gives the same bytes on every run — the PDF's creation date and the ZIP's timestamps are fixed (`DemoMedia::MADE`, 2026-01-01, or `made:`), and nothing is random — so a seed that stores files by their digest writes the same keys over the same objects instead of leaving a new set behind each time. Different names give different files.

Each kind is there on its own too, for content of your choosing: `DemoMedia::wav( 440.0, 1.0, beat: true )`, `DemoMedia::png( 'Cover', 300 )`, `$media->pdf( $title, $paragraphs )`, `$media->zip( [ 'a.txt' => 'A' ] )`, `$media->text( $name )`.

## Testing

```bash
composer install
composer test
```

80 tests. The docblock scanner against every kind of declaration, modifiers, stacked attributes with brackets inside them, `::class`, closures, anonymous classes, multi-line `use function` and line numbers, and output byte-identical to the script it replaced. The smoke checker's option parsing, Chrome discovery order, free ports, console and error-log parsing, and a whole run end to end — seed, environment, a real PHP server and a stand-in browser — that reports one clean page and one failing one. Demo media read the way a player reads it: a RIFF header that agrees with its length, a PNG signature and IHDR, a one-page PDF with its fixed date, a ZIP walked from its end-of-central-directory record to every member and its CRC — and the same bytes twice for every kind.

## License

GPL-2.0-or-later

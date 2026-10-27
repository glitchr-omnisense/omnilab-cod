# omnilab/cod

The Crystallography Open Database (`https://www.crystallography.net/cod/`, its `result` search
endpoint) for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): crystal structures
by COD id - formula, unit cell with its uncertainties, space group, Z, temperature, R factors,
publication and DOI -, as entities; the CIF as a file; a search of the entries' metadata. No key.

## Installation

```sh
composer require omnilab/cod
```

```php
use Omnilab\Cod\CodSourceFactory;

$cod = (new CodSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'throttle' => 0.5,                 // optional: seconds between two calls
]);
```

## Credentials

None: COD is open, no key, no account.

## Options

The factory's options, with the defaults it applies (`CodSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.5` | no | seconds left between two calls: COD publishes no rate, and answered 403 to eight calls in ten seconds |
| `base_uri` | `string` | `https://www.crystallography.net/cod/` | no | COD's site (`result` and `{id}.cif` are joined to it), or a mirror's |

## Capabilities

`capabilities()` answers `entity`, `search`, `open`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a COD id (seven digits, `cod:`, its page, its CIF's address), whatever COD flags it (duplicate, errors, theoretical); another scheme refused before any call |
| `search` | yes | every entry whose metadata hold the text (publication, compound's and mineral's names), 100 a page at most, two calls a page; `total` the number of ids |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | yes | a file of COD's site (a CIF, `.hkl` reflection data), streamed; another site's address refused before any call |
| `structure` | no | the structure is the CIF (`cif()`), not an image |
| `cross_references` | no | none: COD gives no other base's id; the entry it duplicates is `related('duplicate_of')` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Cod\CodSource`: `cif(Identifier|string $id): Asset`, the CIF
of a structure as a file to download (`chemical/x-cif`, role `data`), without a call; `open()`
reads it.

## The scheme

A structure is named in the core's `cod` scheme by its seven digits (`1000000`), read from
`cod:1000000`, its page `crystallography.net/cod/1000000.html` and its CIF's address. Wikidata
keeps COD ids in P9824 (on 1,599 items on 2026-10-15), which omnilab/wikidata reads as a related
thing (`related('structure')`: a compound's structures are not the compound), so the `Collector`
does not follow them into COD; `$cod->entity($item->related('structure')->value(Scheme::COD))`
reads one.

## Which API

COD's search endpoint `crystallography.net/cod/result` (its parameters on the COD wiki's "RESTful
API" page):

| Method | Calls |
|---|---|
| `entity()` | `result?id={id}&format=json&include_duplicates=1&include_errors=1&include_theoretical=1`: the row of COD's database for that id, whatever COD flags it (a duplicate, an entry with errors, a theoretical structure: COD's search leaves them out otherwise, and 1000000 is a duplicate) |
| `search()` | `result?text={text}&format=lst` (every id whose metadata hold the text: publication, compound's and mineral's names), cut into pages here, then `result?id={ids}&format=json&...` for the page's rows: two calls a page; `total` is the number of ids |
| `cif()` | no call: the CIF's address, `crystallography.net/cod/{id}.cif` (outside the contract) |
| `open()` | that address (or any of COD's), streamed |

An unknown id is an empty list (`null`); a text nothing matches an empty answer (an empty page);
an error page a `ProviderException` with its title.

## What an entity holds

`id` (`cod:9008565`), `label` (the mineral's name, else the common name, else the systematic
name, else the formula), `synonyms` (the common name when the label is the mineral's), `domain`
chemistry, `kind` `crystal structure`, `url` (its page), and:

| Key | COD | Value |
|---|---|---|
| `chemical_formula`, `calculated_formula`, `cell_formula` | formula, calcformula, cellformula | Hill order, spaced as COD writes it: `C5 H17 Al N2 O8 P2` |
| `elements` | nel | how many distinct elements |
| `mineral`, `systematic_name` | mineral, chemname | text |
| `space_group`, `space_group_hall`, `space_group_number` | sg, sgHall, sgNumber | `F d -3 m :1`, `F 4d 2 3 -1d`, 227 |
| `cell_a`, `cell_b`, `cell_c` | a, b, c (siga...) | Å, with its uncertainty |
| `cell_alpha`, `cell_beta`, `cell_gamma` | alpha, beta, gamma | °, with its uncertainty |
| `cell_volume` | vol (sigvol) | Å³ |
| `z`, `z_prime` | Z, Zprime | numbers |
| `cell_temperature`, `diffraction_temperature` | celltemp, diffrtemp | K |
| `cell_pressure`, `diffraction_pressure` | cellpressure, diffrpressure | kPa (CIF's unit) |
| `method`, `radiation`, `wavelength` | method, radType, wavelength | text, Å |
| `r_obs`, `r_all` | Robs, Rall | numbers |
| `title`, `authors`, `journal`, `year`, `volume`, `issue`, `pages`, `doi` | the publication | text, year a number |
| `flag` (one each), `status`, `revised` | flags, status, date | `has coordinates`... |
| `cif` | - | the CIF's address |

`identifiers`: its own alone. `related`: `duplicate_of` (the COD entry it duplicates). COD gives no
identifier of another base (no InChIKey, no CAS): the `Collector` has nothing to follow.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Cod\CodSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$cod = (new CodSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$silicon = $cod->entity('cod:9008565');
echo $silicon->label, ' (', $silicon->id, '): ', $silicon->value('space_group'), ', a = ', $silicon->property('cell_a'), ', ', $silicon->value('journal'), ' ', $silicon->value('year'), "\n";

$cif = $cod->cif('9008565');
echo 'CIF ', $cif->href, ': ', strlen($cod->open($cif)->getContent()), " bytes\n";

$page = $cod->search(new Query('caffeine', limit: 3));
printf("%d entries mention caffeine; the first %d:\n", $page->total, count($page));
foreach ($page as $entry) {
    echo '  ', $entry->id, '  ', $entry->label, '  ', $entry->value('space_group'), "\n";
}
```

```
Silicon (cod:9008565): F d -3 m :1, a = 5.4307 Å, Crystal Structures 1963
CIF https://www.crystallography.net/cod/9008565.cif: 5110 bytes
117 entries mention caffeine; the first 3:
  cod:1505182  caffeine 1-hydroxy-2-naphthoic acid  P 1 21/n 1
  cod:1505183  caffeine 3-hydroxy-2-naphthoic acid  P 1 21/c 1
  cod:1505184  C19 H18 N4 O5  P -1
```

(as answered on 2026-10-27: four calls, half a second apart)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        cod: { factory: cod, options: { mailto: '%env(OMNILAB_MAILTO)%', throttle: 0.5 } }
```

`cif()` is a method of `CodSource`, not of the interface the bundle injects: check the instance
before calling it.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call: an identifier of another scheme given to `entity()` or `cif()`, and `open()` of
another site's address (`NotSupportedException`). An unknown id is an empty list, null; a text
nothing matches an empty page; COD's error page a `ProviderException` with its title, its 403
past its rate a `ProviderException` with status 403 (not a `RateLimitedException`: COD does not
answer 429). `open()` makes its call directly: neither the throttle nor the status mapping apply
to it, the response's status is the caller's to check.

## Verified, and not

Against the real service on 2026-10-15: silicon (9008565), the aluminophosphate 1000000 (a
duplicate of 2011331, its cell's uncertainties, its DOI), an unknown id, the search for "caffeine"
(117 entries) in two pages of three, a text nothing matches, silicon's CIF. The fixtures in
`Tests/Fixtures/` are those answers (the CIF cut to its head). COD's server was slow and uneven that
day: a call took 0.3 to 7.6 s, a few connections were dropped without an answer, and a run of eight
calls in ten seconds got a 403 ("Forbidden") on the eighth - hence the default `throttle` of
0.5 s. Again on 2026-10-27: silicon, its CIF (5,110 bytes), the search for "caffeine" (117
entries; the example above). Not verified: COD's mirrors; reflection data (`.hkl`); the other search parameters (formula,
elements, cell ranges), which this package does not use yet.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Crystallography Open Database. COD and its data are dedicated to the public domain
(CC0); its users acknowledge the original authors of the structural data (each entry's publication
is in its properties) and cite COD as its "Citing COD" page says, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-cod#terms-of-use) says. Whoever runs the
package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-cod#responsibility) section.

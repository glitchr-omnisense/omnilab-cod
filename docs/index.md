# omnilab/cod

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

## The scheme

A structure is named in the core's `cod` scheme by its seven digits (`1000000`), read from
`cod:1000000`, its page `crystallography.net/cod/1000000.html` and its CIF's address. Wikidata
keeps COD ids in P9824 (on 1,599 items on 2026-10-15), which omnilab/wikidata does not read yet: no
`Collector` path leads here for now.

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

## Verified, and not

Against the real service on 2026-10-15: silicon (9008565), the aluminophosphate 1000000 (a
duplicate of 2011331, its cell's uncertainties, its DOI), an unknown id, the search for "caffeine"
(117 entries) in two pages of three, a text nothing matches, silicon's CIF. The fixtures in
`Tests/Fixtures/` are those answers (the CIF cut to its head). COD's server was slow and uneven that
day: a call took 0.3 to 7.6 s, a few connections were dropped without an answer, and a run of eight
calls in ten seconds got a 403 ("Forbidden") on the eighth - hence the default `throttle` of
0.5 s. Not verified: COD's mirrors; reflection data (`.hkl`); the other search parameters (formula,
elements, cell ranges), which this package does not use yet.

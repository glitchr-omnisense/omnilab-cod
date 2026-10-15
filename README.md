# omnilab/cod

The Crystallography Open Database for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab):
a crystal structure by its COD id - its names, formula, unit cell (lengths, angles and volume, with
their uncertainties), space group, Z, temperature, radiation, R factors, its publication (title,
authors, journal, year, pages, DOI) and flags; the entry it duplicates as a related thing; its CIF
as a file -; a search of the entries' metadata. No key.

```php
$cod = (new CodSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$silicon = $cod->entity('9008565');             // or 'cod:9008565', its page, its CIF's address
$silicon->label;                                // Silicon
$silicon->value('space_group');                 // F d -3 m :1 (number 227)
$silicon->property('cell_a');                   // 5.4307 Å
$cod->entity('1000000')->property('cell_beta'); // 95.147 ± 0.001 °
$cif = $cod->cif('9008565');                    // an Asset: https://www.crystallography.net/cod/9008565.cif
$cod->open($cif)->getContent();                 // the CIF itself
$cod->search(new Query('caffeine', limit: 10)); // 117 entries, by id
```

```yaml
omnilab:
    sources:
        cod:
            factory: cod
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                throttle: 0.5                  # COD publishes no rate, and once answered 403 to eight calls in ten seconds
                base_uri: 'https://www.crystallography.net/cod/'   # or a mirror's
```

`cif()` is outside the contract: an `Entity` holds no file, so the CIF is given as an `Asset` by
this method (and as the `cif` property's address); `open()` reads it.

## Terms of use

"All data in the COD and the database itself are dedicated to the public domain and licensed under
the CC0 License. Users of the data should acknowledge the original authors of the structural
data" ([crystallography.net/cod](https://www.crystallography.net/cod/)): each entry's publication
is in its properties. COD asks to be cited as its "Citing COD" page says. No rate is published.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Crystallography Open Database. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License (of this package's code): MIT.

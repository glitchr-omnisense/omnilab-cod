<?php

namespace Omnilab\Cod;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Asset;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The Crystallography Open Database (Vilnius University), an open-access
 * collection of crystal structures of organic, inorganic, metal-organic
 * compounds and minerals: a structure by its COD id ("1000000",
 * "cod:9008565", its page or its CIF's address) - its names, formula, unit
 * cell (lengths, angles, volume with their uncertainties), space group
 * (Hermann-Mauguin, Hall, number), Z, temperature and pressure, radiation,
 * R factors, its publication (title, authors, journal, year, pages, DOI)
 * and its flags; the entry it duplicates as a related thing; its CIF as a
 * file (cif(), outside the contract: an Entity holds no asset; open()
 * reads it) -; a search of the entries' metadata by text.
 *
 * One call an entry; a search page two (the ids that match, then their
 * rows). No key, no published rate; the data are in the public domain
 * (CC0), "users should acknowledge the original authors".
 */
final class CodSource extends HttpSource
{
    public const BASE_URI = 'https://www.crystallography.net/cod/';

    /** The most entries a search page holds. */
    public const MAX_LIMIT = 100;

    /** Every entry, those COD flags (duplicates, errors, theoretical structures) included: an id names one. */
    private const ALL = ['include_duplicates' => 1, 'include_errors' => 1, 'include_theoretical' => 1];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'cod';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::OPEN];
    }

    /**
     * A structure by its COD id ("1000000", "cod:1000000", its page, its
     * CIF's address); another base's identifier is refused before any
     * call; null when COD has no such entry.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::COD) ?? throw NotSupportedException::identifier('cod', (string) $id);
        $rows = $this->getJson('result', ['id' => $identifier->value, 'format' => 'json'] + self::ALL) ?? [];
        foreach ($rows as $row) {
            if (\is_array($row) && (string) ($row['file'] ?? '') === $identifier->value) {
                return $this->toEntity($row);
            }
        }

        return null;
    }

    /**
     * The entries whose metadata hold the text (a compound's or a
     * mineral's name, an author, a title's words), the duplicates and
     * erroneous ones left out as COD does, in COD's order (by id); a domain
     * other than chemistry answers nothing. Two calls a page: the ids that
     * match (all of them, cut here), then the page's rows.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::CHEMISTRY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $ids = array_values(array_filter(array_map('trim', preg_split('~\R~', (string) $this->get('result', ['text' => $text, 'format' => 'lst'])) ?: []), static fn (string $id) => (bool) preg_match('~^\d{7}$~', $id)));
        $start = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_LIMIT, $query->limit));
        $slice = \array_slice($ids, $start, $limit);
        $items = [];
        if ($slice) {
            $rows = [];
            foreach ($this->getJson('result', ['id' => implode(',', $slice), 'format' => 'json'] + self::ALL) ?? [] as $row) {
                $rows[(string) ($row['file'] ?? '')] = $row;
            }
            foreach ($slice as $id) {
                if (isset($rows[$id]) && null !== ($entity = $this->toEntity($rows[$id]))) {
                    $items[] = $entity;
                }
            }
        }

        return new Page($items, $start + $limit < \count($ids) ? (string) ($start + $limit) : null, \count($ids));
    }

    /**
     * The CIF of a structure, as a file to download (outside the
     * contract: an Entity holds no asset). Makes no call.
     */
    public function cif(Identifier|string $id): Asset
    {
        $identifier = self::identify($id, Scheme::COD) ?? throw NotSupportedException::identifier('cod', (string) $id);

        return new Asset('cif', rtrim($this->baseUri, '/').'/'.$identifier->value.'.cif', 'chemical/x-cif', ['data'], 'CIF of COD '.$identifier->value);
    }

    /**
     * A COD file read - a CIF (cif(), or its address), a structure's
     * reflection data (".hkl") - streamed, not read; an address of
     * another site is refused before any call.
     */
    public function open(Asset|string $file): ResponseInterface
    {
        $href = $file instanceof Asset ? $file->href : $file;
        if (!str_starts_with($href, rtrim($this->baseUri, '/').'/')) {
            throw NotSupportedException::operation('cod', 'open a file of another site ('.$href.')');
        }

        return $this->http->request('GET', $href, ['headers' => $this->headers]);
    }

    /** @param array<string, mixed> $row a row of COD's result */
    private function toEntity(array $row): ?Entity
    {
        $id = Identifier::tryOf(Scheme::COD, (string) ($row['file'] ?? ''));
        if (null === $id) {
            return null;
        }
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'cod');
            }
        };
        $number = static fn (string $key, ?string $unit, ?string $sigma = null): ?Quantity => is_numeric($row[$key] ?? null)
            ? new Quantity(0 + $row[$key], $unit, is_numeric($row[$sigma ?? 'sig'.$key] ?? null) ? (float) $row[$sigma ?? 'sig'.$key] : null)
            : null;
        // COD writes a formula between dashes, Hill-ordered, spaced: "- C5 H17 Al N2 O8 P2 -".
        $formula = static fn (?string $f): ?string => null === $f ? null : (self::text(trim($f, " -")) ?: null);

        $add('chemical_formula', 'Chemical formula (sum)', $formula($row['formula'] ?? null));
        $add('calculated_formula', 'Formula calculated from the atoms', $formula($row['calcformula'] ?? null));
        $add('cell_formula', 'Formula of the unit cell', $formula($row['cellformula'] ?? null));
        $add('elements', 'Distinct elements', is_numeric($row['nel'] ?? null) ? (int) $row['nel'] : null);
        $add('mineral', 'Mineral', self::text($row['mineral'] ?? null));
        $add('systematic_name', 'Systematic name', self::text($row['chemname'] ?? null));
        $add('space_group', 'Space group (Hermann-Mauguin)', self::text($row['sg'] ?? null));
        $add('space_group_hall', 'Space group (Hall)', self::text($row['sgHall'] ?? null));
        $add('space_group_number', 'Space group number', is_numeric($row['sgNumber'] ?? null) ? (int) $row['sgNumber'] : null);
        foreach (['a', 'b', 'c'] as $axis) {
            $add('cell_'.$axis, 'Cell length '.$axis, $number($axis, 'Å'));
        }
        foreach (['alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ'] as $angle => $greek) {
            $add('cell_'.$angle, 'Cell angle '.$greek, $number($angle, '°'));
        }
        $add('cell_volume', 'Cell volume', $number('vol', 'Å³'));
        $add('z', 'Formula units per cell (Z)', is_numeric($row['Z'] ?? null) ? 0 + $row['Z'] : null);
        $add('z_prime', 'Formula units per asymmetric unit (Z\')', is_numeric($row['Zprime'] ?? null) ? 0 + $row['Zprime'] : null);
        $add('cell_temperature', 'Temperature of the cell measurement', $number('celltemp', 'K'));
        $add('diffraction_temperature', 'Temperature of the diffraction measurement', $number('diffrtemp', 'K'));
        $add('cell_pressure', 'Pressure of the cell measurement', $number('cellpressure', 'kPa'));
        $add('diffraction_pressure', 'Pressure of the diffraction measurement', $number('diffrpressure', 'kPa'));
        $add('method', 'Method', self::text($row['method'] ?? null));
        $add('radiation', 'Radiation', self::text($row['radType'] ?? $row['radiation'] ?? null));
        $add('wavelength', 'Wavelength', $number('wavelength', 'Å', 'none'));
        $add('r_obs', 'R factor (observed reflections)', is_numeric($row['Robs'] ?? null) ? (float) $row['Robs'] : null);
        $add('r_all', 'R factor (all reflections)', is_numeric($row['Rall'] ?? null) ? (float) $row['Rall'] : null);
        $add('title', 'Publication', self::text($row['title'] ?? null));
        $add('authors', 'Authors', self::text($row['authors'] ?? null));
        $add('journal', 'Journal', self::text($row['journal'] ?? null));
        $add('year', 'Year', is_numeric($row['year'] ?? null) ? (int) $row['year'] : null);
        $add('volume', 'Volume', self::text($row['volume'] ?? null));
        $add('issue', 'Issue', self::text($row['issue'] ?? null));
        $add('pages', 'Pages', null === ($first = self::text($row['firstpage'] ?? null)) ? null : $first.(null === ($last = self::text($row['lastpage'] ?? null)) ? '' : '-'.$last));
        $add('doi', 'DOI', self::text($row['doi'] ?? null));
        foreach (array_filter(array_map('trim', explode(',', (string) ($row['flags'] ?? '')))) as $flag) {
            $add('flag', 'Flag', $flag);
        }
        $add('status', 'Status', self::text($row['status'] ?? null));
        $add('revised', 'Last revised', self::text($row['date'] ?? null));
        $add('cif', 'CIF', $this->cif($id)->href);

        $common = self::text($row['commonname'] ?? null);
        $mineral = self::text($row['mineral'] ?? null);
        $label = $mineral ?? $common ?? self::text($row['chemname'] ?? null) ?? $formula($row['formula'] ?? null) ?? 'COD '.$id->value;
        $duplicate = Identifier::tryOf(Scheme::COD, (string) ($row['duplicateof'] ?? ''));

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::CHEMISTRY,
            kind: 'crystal structure',
            synonyms: array_values(array_unique(array_filter([$common], static fn (?string $n) => null !== $n && $n !== $label))),
            properties: $properties,
            url: $id->url(),
            source: 'cod',
            related: null === $duplicate ? [] : ['duplicate_of' => Identifiers::of($duplicate)],
        );
    }
}

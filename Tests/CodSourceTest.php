<?php

namespace Omnilab\Cod\Tests;

use Omnilab\Cod\CodSource;
use Omnilab\Cod\CodSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.crystallography.net/cod on 2026-10-16:
 * silicon (9008565, Wyckoff 1963) and an aluminophosphate (1000000, a
 * duplicate of 2011331), an unknown id (an empty list), the ids whose
 * metadata hold "caffeine" (117) and the rows of its first two pages of
 * three, a text nothing matches (an empty answer), the head of silicon's
 * CIF.
 */
final class CodSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null): CodSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            self::assertStringStartsWith(CodSource::BASE_URI, $url);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $call = rawurldecode(substr($url, \strlen(CodSource::BASE_URI)));
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '9008565.cif' === $call => '9008565.cif',
                isset($query['text']) => 'caffeine' === $query['text'] ? 'search-caffeine.lst' : 'search-notfound.lst',
                default => match ($query['id'] ?? null) {
                    '9008565' => 'result-9008565.json',
                    '1000000' => 'result-1000000.json',
                    '1505182,1505183,1505184' => 'result-caffeine-0.json',
                    '1505186,1514629,1514630' => 'result-caffeine-3.json',
                    default => 'result-9999999.json',
                },
            };
            if (str_starts_with($call, 'result?id=')) {
                self::assertSame(['1', '1', '1'], [$query['include_duplicates'], $query['include_errors'], $query['include_theoretical']], 'an id names an entry, whatever COD flags');
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new CodSourceFactory($http))->create(['throttle' => 0]);
        self::assertInstanceOf(CodSource::class, $source);

        return $source;
    }

    public function testSiliconItsCellAndSpaceGroup(): void
    {
        $silicon = $this->source()->entity('9008565');

        self::assertCount(1, $this->calls);
        self::assertSame('cod:9008565', $silicon->id->key());
        self::assertSame('Silicon', $silicon->label);
        self::assertSame(Domain::CHEMISTRY, $silicon->domain);
        self::assertSame('crystal structure', $silicon->kind);
        self::assertSame('https://www.crystallography.net/cod/9008565.html', $silicon->url);
        self::assertSame('Si', $silicon->value('chemical_formula'));
        self::assertSame('Si8', $silicon->value('cell_formula'));
        self::assertSame('Silicon', $silicon->value('mineral'));
        self::assertSame('F d -3 m :1', $silicon->value('space_group'));
        self::assertSame(227, $silicon->value('space_group_number'));
        self::assertEquals(new Quantity(5.4307, 'Å'), $silicon->value('cell_a'));
        self::assertEquals(new Quantity(90, '°'), $silicon->value('cell_alpha'));
        self::assertEquals(new Quantity(160.165, 'Å³'), $silicon->value('cell_volume'));
        self::assertSame(8, $silicon->value('z'));
        self::assertEquals(new Quantity(300, 'K'), $silicon->value('diffraction_temperature'));
        self::assertSame('Wyckoff, R. W. G.', $silicon->value('authors'));
        self::assertSame('Crystal Structures', $silicon->value('journal'));
        self::assertSame(1963, $silicon->value('year'));
        self::assertSame('7-83', $silicon->value('pages'));
        self::assertSame('https://www.crystallography.net/cod/9008565.cif', $silicon->value('cif'));
        self::assertSame([], $silicon->related);
    }

    public function testAnEntryWithUncertaintiesADoiAndTheEntryItDuplicates(): void
    {
        $entry = $this->source()->entity('https://www.crystallography.net/cod/1000000.html');

        self::assertSame('cod:1000000', $entry->id->key());
        self::assertSame('C5 H17 Al N2 O8 P2', $entry->label, 'no name: its formula');
        self::assertEquals(new Quantity(7.8783, 'Å', 0.0002), $entry->value('cell_a'));
        self::assertEquals(new Quantity(95.147, '°', 0.001), $entry->value('cell_beta'));
        self::assertSame('7.8783 ± 0.0002 Å', (string) $entry->property('cell_a'));
        self::assertEquals(new Quantity(296, 'K', 2.0), $entry->value('cell_temperature'));
        self::assertSame('MoKα', $entry->value('radiation'));
        self::assertEquals(new Quantity(0.71073, 'Å'), $entry->value('wavelength'));
        self::assertSame(0.0584, $entry->value('r_obs'));
        self::assertSame('10.1107/S0108270100008532', $entry->value('doi'));
        self::assertSame('Acta Crystallographica, Section C', $entry->value('journal'));
        self::assertSame('has coordinates', $entry->value('flag'));
        self::assertSame(['cod:2011331'], $entry->related('duplicate_of')->keys());
    }

    public function testUnknownIsNullOtherBasesRefusedBeforeAnyCall(): void
    {
        self::assertNull($this->source()->entity(Identifier::of(Scheme::COD, '9999999')));
        self::assertCount(1, $this->calls);
        $this->calls = [];
        foreach (['silicon', '100000', 'mp-149', 'pubchem.cid:2519'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail($id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchTwoCallsAPage(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('caffeine', limit: 3));

        self::assertSame(['result?text=caffeine&format=lst', 'result?id=1505182,1505183,1505184&format=json&include_duplicates=1&include_errors=1&include_theoretical=1'], $this->calls);
        self::assertSame(117, $page->total);
        self::assertSame('3', $page->next);
        self::assertSame(['cod:1505182', 'cod:1505183', 'cod:1505184'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame('caffeine 1-hydroxy-2-naphthoic acid', $page->first()->label);
        self::assertSame('C19 H18 N4 O5', $page->first()->value('chemical_formula'));
        self::assertSame('10.1021/mp070004b', $page->first()->value('doi'));
        $next = $source->search(new Query('caffeine', limit: 3, cursor: $page->next));
        self::assertSame(['cod:1505186', 'cod:1514629', 'cod:1514630'], array_map(static fn ($e) => $e->id->key(), $next->items));
        self::assertSame('6', $next->next);

        $none = $source->search(new Query('nothingmatchesthis'));
        self::assertCount(0, $none);
        self::assertSame(0, $none->total);
        self::assertCount(0, $source->search(new Query('caffeine', Domain::BIOLOGY)));
    }

    public function testTheCifAsAFileOutsideTheContract(): void
    {
        $source = $this->source();
        $cif = $source->cif('cod:9008565');
        self::assertSame([], $this->calls, 'no call');
        self::assertSame('https://www.crystallography.net/cod/9008565.cif', $cif->href);
        self::assertSame('chemical/x-cif', $cif->type);
        self::assertTrue($cif->hasRole('data'));
        self::assertStringContainsString('data_9008565', $source->open($cif)->getContent());
        try {
            $source->open('https://example.org/a.cif');
            self::fail('another site');
        } catch (NotSupportedException) {
        }
    }

    public function testErrorsAndCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::OPEN], $this->source()->capabilities());
        self::assertSame(0.5, (new CodSourceFactory())->createConfig()->get('throttle'));
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('403 Forbidden');
        $this->source(new MockResponse('<html><head><title>403 Forbidden</title></head></html>', ['http_code' => 403]))->search(new Query('caffeine'));
    }
}

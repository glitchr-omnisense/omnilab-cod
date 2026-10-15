<?php

namespace Omnilab\Cod;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The Crystallography Open Database: crystal structures by COD id, their
 * cell, space group and publication, their CIF; a search - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0.5                    # seconds between two calls: COD publishes no rate, and answered 403 to eight calls in ten seconds
 *     base_uri: 'https://www.crystallography.net/cod/'   # or a mirror's
 */
final class CodSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'cod',
            'omnilab.required_options' => [],
            'throttle' => 0.5,
            'base_uri' => CodSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new CodSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', CodSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}

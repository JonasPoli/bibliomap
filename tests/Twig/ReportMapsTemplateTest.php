<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Os mapas dos relatórios são desenhados só com as formas geográficas e os marcadores do projeto.
 * O CARTO passou a exigir chave (todos os azulejos viraram "API KEY REQUIRED") e nenhum serviço de
 * azulejos pode voltar a ser a base dos mapas sem chave e decisão própria.
 */
class ReportMapsTemplateTest extends TestCase
{
    public function testReportTemplatesDoNotLoadTileLayers(): void
    {
        $found = [];
        $finder = (new Finder())->files()->in(dirname(__DIR__, 2) . '/templates/report')->name('*.twig');

        foreach ($finder as $file) {
            foreach (explode("\n", $file->getContents()) as $n => $line) {
                if (str_contains($line, 'cartocdn') || str_contains($line, 'L.tileLayer(')) {
                    $found[] = $file->getRelativePathname() . ':' . ($n + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found, 'Camada de azulejos de terceiros nos mapas dos relatórios');
    }

    public function testMapsFrameTheirArea(): void
    {
        $countries = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/report/countries.html.twig');
        $geography = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/report/geography_report.html.twig');

        $this->assertStringContainsString('WORLD_BOUNDS', $countries);
        $this->assertSame(3, substr_count($countries, '.fitBounds(WORLD_BOUNDS)'), 'Os três mapas de países enquadram o mundo');
        $this->assertStringContainsString('geoLayerRegions.getBounds()', $geography);
        $this->assertStringContainsString('citiesMap.fitBounds(BRAZIL_BOUNDS)', $geography);
    }
}

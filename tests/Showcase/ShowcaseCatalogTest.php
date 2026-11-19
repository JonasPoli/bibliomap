<?php

namespace App\Tests\Showcase;

use App\Showcase\ShowcaseCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O catálogo real da vitrine da home (config/showcase/recursos.yaml) com os prints de public/images/recursos/.
 * As regras estão em ShowcaseCatalog::validate(): campos obrigatórios, id único, categoria existente,
 * imagem WebP de até 250 KB com sufixo de tema, os dois temas em cada categoria e nenhum texto proibido.
 */
class ShowcaseCatalogTest extends TestCase
{
    private ShowcaseCatalog $catalog;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->catalog = new ShowcaseCatalog($root . '/config/showcase/recursos.yaml', $root . '/public');
    }

    public function testCatalogIsValid(): void
    {
        $this->assertSame([], $this->catalog->validate(), 'Problemas no catálogo da vitrine');
    }

    public function testEveryResourceAndImageReachesTheHome(): void
    {
        $raw = $this->catalog->raw();
        $sections = $this->catalog->sections();

        $images = array_sum(array_map(fn($r) => count((array) ($r['imagens'] ?? [])), $raw['recursos']));
        $this->assertSame(count($raw['recursos']), $sections['total_recursos'], 'Recurso do catálogo que não chega à home');
        $this->assertSame($images, $sections['total_imagens'], 'Imagem do catálogo que não chega à home');
    }

    public function testEveryPrintInThePublicFolderIsUsed(): void
    {
        $used = [];
        foreach ($this->catalog->raw()['recursos'] as $resource) {
            foreach ((array) ($resource['imagens'] ?? []) as $image) {
                $used[] = $image['arquivo'];
            }
        }
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/public/' . ShowcaseCatalog::IMAGE_DIR . '/*') ?: []);

        $this->assertSame([], array_values(array_diff($files, $used)), 'Print em public/images/recursos/ que nenhum recurso usa');
    }

    public function testImagesHaveSize(): void
    {
        foreach ($this->catalog->sections()['categorias'] as $category) {
            foreach ($category['recursos'] as $resource) {
                foreach ($resource['imagens'] as $image) {
                    $this->assertGreaterThan(300, $image['largura'], $image['src']);
                    $this->assertGreaterThan(150, $image['altura'], $image['src']);
                }
            }
        }
    }
}

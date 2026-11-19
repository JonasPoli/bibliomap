<?php

namespace App\Tests\Showcase;

use App\Showcase\ShowcaseCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Comportamento do ShowcaseCatalog sobre um catálogo pequeno de fixture
 * (o catálogo real da home é conferido no ShowcaseCatalogTest).
 */
class ShowcaseCatalogFixtureTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/showcase';

    private function catalog(?string $file = null): ShowcaseCatalog
    {
        return new ShowcaseCatalog($file ?? self::DIR . '/recursos.yaml', self::DIR . '/public');
    }

    public function testKeepsFileOrderAndSkipsEmptyCategories(): void
    {
        $sections = $this->catalog()->sections();

        $this->assertSame(['segunda'], array_column($sections['categorias'], 'id'));
        $this->assertSame(['grafico-b', 'grafico-a'], array_column($sections['categorias'][0]['recursos'], 'id'));
        $this->assertSame(2, $sections['total_recursos']);
        $this->assertSame(3, $sections['total_imagens']);
    }

    public function testAddsImageSizeThemeAndPublicPath(): void
    {
        $image = $this->catalog()->sections()['categorias'][0]['recursos'][0]['imagens'][0];

        $this->assertSame('/images/recursos/grafico-claro.webp', $image['src']);
        $this->assertSame(40, $image['largura']);
        $this->assertSame(20, $image['altura']);
        $this->assertSame('claro', $image['tema']);
        $this->assertSame('recurso-grafico-b', $this->catalog()->sections()['categorias'][0]['recursos'][0]['ancora']);
    }

    public function testEscapesTextAndKeepsBold(): void
    {
        $html = $this->catalog()->sections()['categorias'][0]['recursos'][0]['descricao_html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>destaque</strong>', $html);
    }

    public function testFixtureIsValid(): void
    {
        $this->assertSame([], $this->catalog()->validate());
    }

    public function testReportsEachProblemWithResourceAndField(): void
    {
        $data = Yaml::parseFile(self::DIR . '/recursos.yaml');
        $data['recursos'][1]['id'] = 'grafico-b';                       // id repetido
        $data['recursos'][0]['exemplo'] = '';                            // campo vazio
        $data['recursos'][0]['imagens'][0]['arquivo'] = 'sumiu-claro.webp'; // imagem ausente
        $data['recursos'][0]['como'] = 'Abra o link do Sci-Hub';        // texto proibido
        $data['recursos'][1]['categoria'] = 'inexistente';

        $file = tempnam(sys_get_temp_dir(), 'vitrine') . '.yaml';
        file_put_contents($file, Yaml::dump($data, 6));
        try {
            $errors = implode("\n", $this->catalog($file)->validate());
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString("Recurso 'grafico-b': id repetido", $errors);
        $this->assertStringContainsString("Recurso 'grafico-b' sem o campo 'exemplo'", $errors);
        $this->assertStringContainsString("imagem 'sumiu-claro.webp' não existe", $errors);
        $this->assertStringContainsString("texto proibido 'sci-hub'", $errors);
        $this->assertStringContainsString("categoria 'inexistente' não existe", $errors);
    }

    public function testCategoryWithOneThemeOnlyIsReported(): void
    {
        $data = Yaml::parseFile(self::DIR . '/recursos.yaml');
        $data['recursos'][0]['imagens'] = [$data['recursos'][0]['imagens'][1]]; // só escuro nos dois recursos

        $file = tempnam(sys_get_temp_dir(), 'vitrine') . '.yaml';
        file_put_contents($file, Yaml::dump($data, 6));
        try {
            $errors = $this->catalog($file)->validate();
        } finally {
            @unlink($file);
        }

        $this->assertContains("Categoria 'segunda': precisa de prints no tema claro e no escuro", $errors);
    }
}

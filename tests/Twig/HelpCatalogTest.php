<?php

namespace App\Tests\Twig;

use App\Twig\HelpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

class HelpCatalogTest extends TestCase
{
    private HelpExtension $help;

    protected function setUp(): void
    {
        $this->help = new HelpExtension(dirname(__DIR__, 2) . '/config/help', false);
    }

    public function testEveryEntryHasAllSections(): void
    {
        $catalog = $this->help->getCatalog();
        $this->assertNotEmpty($catalog);

        foreach ($catalog as $key => $entry) {
            foreach (['title', 'what', 'how', 'not', 'example'] as $field) {
                $this->assertNotEmpty(trim((string) ($entry[$field] ?? '')), "Ajuda '$key' sem o campo '$field'");
            }
        }
    }

    public function testKeysAreUniqueAcrossFiles(): void
    {
        $seen = [];
        foreach (glob(dirname(__DIR__, 2) . '/config/help/*.yaml') as $file) {
            preg_match_all('/^([a-z0-9_.\-]+):\s*$/m', file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $this->assertArrayNotHasKey($key, $seen, "Chave '$key' duplicada em " . basename($file) . ' e ' . ($seen[$key] ?? ''));
                $seen[$key] = basename($file);
            }
        }
    }

    public function testEveryTemplateKeyExists(): void
    {
        $catalog = $this->help->getCatalog();
        $missing = [];

        $finder = (new Finder())->files()->in(dirname(__DIR__, 2) . '/templates')->name('*.twig');
        foreach ($finder as $file) {
            preg_match_all("/help\\(\\s*'([^']+)'/", $file->getContents(), $m);
            foreach ($m[1] as $key) {
                if (!isset($catalog[$key])) {
                    $missing[] = $file->getRelativePathname() . ': ' . $key;
                }
            }
        }

        $this->assertSame([], $missing, 'Chaves de ajuda usadas nos templates mas ausentes do catálogo');
    }

    public function testRendersEscapedPopover(): void
    {
        $catalog = $this->help->getCatalog();
        $html = $this->help->renderHelp(array_key_first($catalog));

        $this->assertStringContainsString('data-bm-help', $html);
        $this->assertStringContainsString('bm-help-example', html_entity_decode($html));
        $this->assertSame('', $this->help->renderHelp('chave.inexistente'));
    }
}

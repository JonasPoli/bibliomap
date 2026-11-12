<?php

namespace App\Tests\Service;

use App\Service\SiteSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A imagem OG vai para public/uploads/seo: só JPG, PNG e WebP de verdade entram,
 * e a extensão gravada vem da lista (nunca do arquivo enviado).
 */
class SiteSettingsOgImageTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
            @rmdir(dirname($f));
        }
    }

    #[DataProvider('acceptedImages')]
    public function testAcceptsRealImagesAndFixesTheExtension(string $format, string $expectedExtension): void
    {
        // Nome enganoso de propósito: a extensão do arquivo enviado não conta
        $path = $this->image($format, 'foto.php');

        $this->assertSame($expectedExtension, SiteSettingsService::validateOgImage($path, filesize($path)));
    }

    public static function acceptedImages(): array
    {
        return ['jpeg' => ['jpeg', 'jpg'], 'png' => ['png', 'png'], 'webp' => ['webp', 'webp']];
    }

    #[DataProvider('refusedContents')]
    public function testRefusesAnythingThatIsNotAnAcceptedImage(string $name, string $content): void
    {
        $path = $this->file($name, $content);

        $this->expectException(\InvalidArgumentException::class);
        SiteSettingsService::validateOgImage($path, filesize($path));
    }

    public static function refusedContents(): array
    {
        return [
            'script PHP com nome de imagem'  => ['foto.jpg', "<?php echo 'x'; ?>\n"],
            'PHP atrás de cabeçalho GIF'     => ['foto.png', "GIF89a<?php echo 'x'; ?>"],
            'SVG com script'                 => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'HTML'                           => ['pagina.png', '<!doctype html><html><body><script>alert(1)</script></body></html>'],
            'texto simples'                  => ['nota.webp', 'apenas texto'],
        ];
    }

    public function testRefusesGifEvenThoughItIsAnImage(): void
    {
        $path = $this->image('gif', 'animacao.gif');

        $this->expectException(\InvalidArgumentException::class);
        SiteSettingsService::validateOgImage($path, filesize($path));
    }

    public function testRefusesEmptyAndOversizedFiles(): void
    {
        $path = $this->image('png', 'ok.png');

        foreach ([0, SiteSettingsService::OG_IMAGE_MAX_BYTES + 1] as $size) {
            try {
                SiteSettingsService::validateOgImage($path, $size);
                $this->fail("Tamanho {$size} deveria ser recusado.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function image(string $format, string $name): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($img),
            'png'  => imagepng($img),
            'webp' => imagewebp($img),
            'gif'  => imagegif($img),
        };
        return $this->file($name, (string) ob_get_clean());
    }

    private function file(string $name, string $content): string
    {
        $dir = sys_get_temp_dir() . '/bm_og_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }
}

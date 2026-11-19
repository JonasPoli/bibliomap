<?php

namespace App\Tests\Service\Import;

use App\Service\Import\ZipUploadExtractor;
use PHPUnit\Framework\TestCase;

class ZipUploadExtractorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bm_zip_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir . '/out');
        foreach (glob($this->dir . '/out/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/out');
        @rmdir($this->dir);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->dir . '/' . uniqid() . '.zip';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            str_ends_with($name, '/') ? $z->addEmptyDir(rtrim($name, '/')) : $z->addFromString($name, $content);
        }
        $z->close();

        return $path;
    }

    public function testUmCsv(): void
    {
        $r = (new ZipUploadExtractor())->extract($this->zip(['search_result.csv' => "a,b\n1,2\n"]), $this->dir . '/out');
        $this->assertSame('csv', $r['extension']);
        $this->assertSame('search_result.csv', $r['innerName']);
        $this->assertStringStartsWith($this->dir . '/out/import_', $r['path']);
        $this->assertSame("a,b\n1,2\n", file_get_contents($r['path']));
    }

    public function testIgnoraMetadadosDoMacos(): void
    {
        $r = (new ZipUploadExtractor())->extract($this->zip([
            'pasta/' => '', 'pasta/x.csv' => 'x', '__MACOSX/pasta/._x.csv' => 'lixo', 'pasta/._x.csv' => 'lixo', '.DS_Store' => 'lixo',
        ]), $this->dir . '/out');
        $this->assertSame('x', file_get_contents($r['path']));
    }

    public function testDoisArquivosSaoRecusados(): void
    {
        $this->expectExceptionMessage('O ZIP deve conter um único arquivo exportado');
        (new ZipUploadExtractor())->extract($this->zip(['a.csv' => 'a', 'b.csv' => 'b']), $this->dir . '/out');
    }

    public function testFormatoNaoLidoEhRecusado(): void
    {
        $this->expectExceptionMessage('não é um formato lido');
        (new ZipUploadExtractor())->extract($this->zip(['programa.exe' => 'MZ']), $this->dir . '/out');
    }

    public function testNomeComCaminhoRelativoNaoEscapaDaPasta(): void
    {
        $r = (new ZipUploadExtractor())->extract($this->zip(['../../fora.csv' => 'x']), $this->dir . '/out');
        $this->assertSame(realpath($this->dir . '/out'), realpath(dirname($r['path'])));
        $this->assertFileDoesNotExist(dirname($this->dir) . '/fora.csv');
    }

    public function testArquivoAcimaDoLimite(): void
    {
        $this->expectExceptionMessage('passa de 600 MB');
        (new ZipUploadExtractor(1024))->extract($this->zip(['grande.csv' => str_repeat('x', 5000)]), $this->dir . '/out');
    }
}

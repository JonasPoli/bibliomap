<?php

namespace App\Service\Import;

/**
 * Abre o ZIP enviado na importação (a BDTD entrega a exportação compactada). Aceita só um arquivo de formato lido
 * dentro dele, ignorando pastas e os metadados do macOS, e o extrai com nome gerado pelo sistema: o nome que vem
 * no ZIP nunca vira caminho.
 */
class ZipUploadExtractor
{
    public const INNER_EXTENSIONS = ['csv', 'txt', 'nbib', 'ris', 'xls', 'json'];

    public function __construct(private readonly int $maxBytes = 600 * 1024 * 1024) {}

    /**
     * @return array{path: string, extension: string, innerName: string}
     * @throws \RuntimeException com a mensagem para o usuário
     */
    public function extract(string $zipPath, string $destDir): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('O servidor não tem suporte a ZIP. Descompacte o arquivo e envie o que está dentro dele.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('O ZIP não pôde ser aberto. Confira se o download terminou e envie de novo.');
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $base = basename(str_replace('\\', '/', $name));
                if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with($base, '._') || $base === '.DS_Store') {
                    continue;
                }
                $entries[] = $stat;
            }

            if (count($entries) !== 1) {
                throw new \RuntimeException(count($entries) === 0
                    ? 'O ZIP está vazio. Ele deve conter um único arquivo exportado.'
                    : 'O ZIP deve conter um único arquivo exportado.');
            }
            $stat = $entries[0];
            $innerName = basename(str_replace('\\', '/', (string) $stat['name']));
            $ext = strtolower(pathinfo($innerName, PATHINFO_EXTENSION));
            if (!in_array($ext, self::INNER_EXTENSIONS, true)) {
                throw new \RuntimeException(sprintf('O ZIP contém "%s", que não é um formato lido. O ZIP deve conter um único arquivo exportado.', $innerName));
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw new \RuntimeException('O ZIP está protegido por senha. Descompacte o arquivo e envie o que está dentro dele.');
            }
            if ((int) ($stat['size'] ?? 0) > $this->maxBytes) {
                throw new \RuntimeException('O arquivo dentro do ZIP passa de 600 MB.');
            }

            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException('Não foi possível gravar o arquivo extraído.');
            }
            $target = $destDir . '/' . uniqid('import_') . '.' . $ext;
            $in = $zip->getStream((string) $stat['name']);
            if ($in === false) {
                throw new \RuntimeException('O arquivo dentro do ZIP não pôde ser lido.');
            }
            $out = fopen($target, 'wb');
            $written = 0;
            try {
                while (!feof($in)) {
                    $chunk = fread($in, 1 << 20);
                    if ($chunk === false) {
                        break;
                    }
                    $written += strlen($chunk);
                    if ($written > $this->maxBytes) {
                        throw new \RuntimeException('O arquivo dentro do ZIP passa de 600 MB.');
                    }
                    fwrite($out, $chunk);
                }
            } catch (\RuntimeException $e) {
                fclose($out);
                @unlink($target);
                throw $e;
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
            }
            fclose($out);

            return ['path' => $target, 'extension' => $ext, 'innerName' => $innerName];
        } finally {
            $zip->close();
        }
    }
}

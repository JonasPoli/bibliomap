<?php

namespace App\Service\Import;

/**
 * Leitor de um formato que a base entrega fora de UTF-8 (ex.: o "XLS" da BRAPCI, tabela HTML em Windows-1252).
 * Quando um leitor que reconhece o arquivo declara a codificação, o envio não recusa o arquivo pelo teste de UTF-8:
 * o próprio leitor converte ao ler.
 */
interface DeclaresEncodingInterface
{
    /** Codificação do arquivo (ex.: "Windows-1252"), ou null se ele já está em UTF-8. */
    public function sourceEncoding(string $filePath): ?string;
}

<?php

namespace App\Service\Import;

/**
 * Avisos do leitor para a prévia: um aviso informativo (ex.: "este formato vem sem acentos") e um aviso que
 * impede a importação (ex.: CSV sem identificador). Com o aviso que impede, a prévia não oferece "Confirmar" e o
 * envio de vários arquivos ignora o arquivo.
 */
interface PreviewNoticesInterface
{
    public function previewNotice(string $filePath): ?string;

    public function blockingNotice(string $filePath): ?string;
}

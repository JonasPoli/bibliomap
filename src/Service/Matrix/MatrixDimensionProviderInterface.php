<?php

namespace App\Service\Matrix;

interface MatrixDimensionProviderInterface
{
    public function getKey(): string;
    public function getLabel(): string;
    public function getCategory(): string;

    /**
     * Extracts normalized value strings for a given document.
     * @param array $docData Document data array with pre-fetched relations
     * @param bool $useThesaurus Whether to use Thesaurus concept labels when available
     * @return string[]
     */
    public function extractValues(array $docData, bool $useThesaurus = true): array;
}

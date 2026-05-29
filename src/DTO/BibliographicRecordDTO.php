<?php

namespace App\DTO;

class BibliographicRecordDTO
{
    public string $source = '';
    public ?string $externalId = null;
    public ?string $title = null;
    public ?string $abstractText = null;
    public ?int $year = null;
    public ?string $publicationDate = null;
    public ?string $documentType = null;
    public ?string $doi = null;
    public ?string $pmid = null;
    public ?string $isbn = null;
    public ?string $issn = null;
    public ?string $url = null;
    public ?string $language = null;
    public ?string $sourceTitle = null;
    public ?string $volume = null;
    public ?string $issue = null;
    public ?string $pageStart = null;
    public ?string $pageEnd = null;
    public ?string $publisher = null;
    public ?int $citedBy = null;
    public ?string $openAccessStatus = null;
    public ?string $publicationStage = null;
    public ?string $rawData = null;

    /** @var string[] */
    public array $authorNames = [];

    /** @var string[] */
    public array $authorKeywords = [];

    /** @var string[] */
    public array $indexedKeywords = [];

    /** @var string[] */
    public array $affiliations = [];

    /** @var string[] */
    public array $references = [];

    /** @var string[] */
    public array $countries = [];

    /** @var string[] */
    public array $institutions = [];
}

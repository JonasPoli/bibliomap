<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716124956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE academic_database (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, acronym VARCHAR(100) NOT NULL, url VARCHAR(500) DEFAULT NULL, logo VARCHAR(500) DEFAULT NULL, file_formats JSON DEFAULT NULL, signature_columns JSON DEFAULT NULL, description LONGTEXT DEFAULT NULL, import_instructions LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_F6638E39512D8851 (acronym), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE qualis_journal_academic_database (qualis_journal_id INT NOT NULL, academic_database_id INT NOT NULL, INDEX IDX_4A2CE54B47DF50A9 (qualis_journal_id), INDEX IDX_4A2CE54B3C409EFE (academic_database_id), PRIMARY KEY (qualis_journal_id, academic_database_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE qualis_journal_academic_database ADD CONSTRAINT FK_4A2CE54B47DF50A9 FOREIGN KEY (qualis_journal_id) REFERENCES qualis_journal (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE qualis_journal_academic_database ADD CONSTRAINT FK_4A2CE54B3C409EFE FOREIGN KEY (academic_database_id) REFERENCES academic_database (id) ON DELETE CASCADE');

        // Seeds
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("Scopus", "scopus", "https://www.scopus.com", "/img/Scopus_logo.svg", \'["csv"]\', \'["EID", "Authors", "Cited by", "Author Keywords"]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("Web of Science", "wos", "https://www.webofscience.com", "/img/clarivate-logo.svg", \'["txt"]\', \'[]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("Lens.org", "lens", "https://www.lens.org/lens/scholar", "/img/IOI-Device-onDark.png", \'["csv"]\', \'["Lens ID", "Publication Year", "Author/s", "Citing Works Count"]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("PubMed", "pubmed", "https://pubmed.ncbi.nlm.nih.gov", "/img/US-NLM-PubMed-Logo.svg", \'["csv", "nbib", "xml"]\', \'["PMID", "Title", "Authors", "Citation", "Journal/Book", "Publication Year"]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("OpenAlex", "openalex", "https://openalex.org/works", "/img/OpenAlex_logo_2021.svg", \'["csv"]\', \'[]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("Crossref", "crossref", "https://search.crossref.org", "/img/idH-UrdJOS_1780373130233.png", \'["csv", "ris"]\', \'[]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("SciELO", "scielo", "https://scielo.org", "/img/scielo-logo.svg", \'["csv", "ris"]\', \'[]\')');
        $this->addSql('INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns) VALUES ("Genérico", "generic", NULL, NULL, \'["ris", "bib", "csv", "xlsx", "xml"]\', \'[]\')');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE qualis_journal_academic_database DROP FOREIGN KEY FK_4A2CE54B47DF50A9');
        $this->addSql('ALTER TABLE qualis_journal_academic_database DROP FOREIGN KEY FK_4A2CE54B3C409EFE');
        $this->addSql('DROP TABLE academic_database');
        $this->addSql('DROP TABLE qualis_journal_academic_database');
    }
}

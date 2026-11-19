<?php

namespace App\Showcase;

use App\Text\InlineFormatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Vitrine de recursos da home pública.
 *
 * O conteúdo fica em config/showcase/recursos.yaml: as categorias (na ordem em que aparecem) e os
 * recursos, cada um com os textos e os prints em public/images/recursos/. O nome de cada print termina
 * em -claro.webp ou -escuro.webp, o tema do sistema em que ele foi tirado.
 *
 * Os textos aceitam **negrito** e são escapados pelo InlineFormatter, como os da ajuda contextual.
 */
final class ShowcaseCatalog
{
    public const IMAGE_DIR = 'images/recursos';
    public const MAX_IMAGE_BYTES = 250 * 1024;

    /** Campos obrigatórios de cada recurso. */
    public const RESOURCE_FIELDS = ['id', 'categoria', 'titulo', 'descricao', 'onde', 'mostra', 'como', 'porque', 'exemplo'];

    /** Campos de texto que viram HTML (escape mais **negrito**). */
    public const TEXT_FIELDS = ['descricao', 'mostra', 'como', 'porque', 'exemplo'];

    public const IMAGE_FIELDS = ['arquivo', 'legenda', 'alt'];

    /** Nada disso pode aparecer num texto público (dados de conta, link de pirataria). */
    public const FORBIDDEN_WORDS = ['sci-hub', 'scihub', 'impersona', '@'];

    private ?array $raw = null;
    private ?array $sections = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/showcase/recursos.yaml')]
        private readonly string $file,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
        private readonly InlineFormatter $formatter = new InlineFormatter(),
    ) {}

    /** @return array{categorias: array<string, array>, recursos: list<array>} */
    public function raw(): array
    {
        if ($this->raw === null) {
            $data = is_file($this->file) ? (Yaml::parseFile($this->file) ?? []) : [];
            $this->raw = [
                'categorias' => (array) ($data['categorias'] ?? []),
                'recursos'   => array_values((array) ($data['recursos'] ?? [])),
            ];
        }

        return $this->raw;
    }

    /**
     * Categorias na ordem do catálogo, cada uma com os seus recursos prontos para o template.
     *
     * @return array{categorias: list<array>, total_recursos: int, total_imagens: int}
     */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $raw = $this->raw();
        $byCategory = [];
        $totalImages = 0;

        foreach ($raw['recursos'] as $resource) {
            $category = (string) ($resource['categoria'] ?? '');
            if (!isset($raw['categorias'][$category])) {
                continue;
            }

            $images = [];
            foreach ((array) ($resource['imagens'] ?? []) as $image) {
                $file = (string) ($image['arquivo'] ?? '');
                $path = $this->imagePath($file);
                if ($file === '' || !is_file($path)) {
                    continue;
                }
                [$width, $height] = (getimagesize($path) ?: [0, 0]);
                $images[] = [
                    'src'     => '/' . self::IMAGE_DIR . '/' . basename($file),
                    'largura' => (int) $width,
                    'altura'  => (int) $height,
                    'legenda' => (string) ($image['legenda'] ?? ''),
                    'alt'     => (string) ($image['alt'] ?? ''),
                    'tema'    => self::themeOf($file),
                ];
            }
            if ($images === []) {
                continue;
            }
            $totalImages += count($images);

            $item = [
                'id'      => (string) $resource['id'],
                'ancora'  => 'recurso-' . $resource['id'],
                'titulo'  => (string) $resource['titulo'],
                'onde'    => (string) ($resource['onde'] ?? ''),
                'exporta' => array_values(array_map('strval', (array) ($resource['exporta'] ?? []))),
                'imagens' => $images,
            ];
            foreach (self::TEXT_FIELDS as $field) {
                $item[$field . '_html'] = $this->formatter->format((string) ($resource[$field] ?? ''));
            }

            $byCategory[$category][] = $item;
        }

        $categories = [];
        foreach ($raw['categorias'] as $id => $category) {
            if (empty($byCategory[$id])) {
                continue;
            }
            $categories[] = [
                'id'             => (string) $id,
                'ancora'         => 'categoria-' . $id,
                'titulo'         => (string) ($category['titulo'] ?? $id),
                'rotulo'         => (string) ($category['rotulo'] ?? $category['titulo'] ?? $id),
                'descricao_html' => $this->formatter->format((string) ($category['descricao'] ?? '')),
                'icone'          => (string) ($category['icone'] ?? 'bi-grid'),
                'recursos'       => $byCategory[$id],
            ];
        }

        return $this->sections = [
            'categorias'     => $categories,
            'total_recursos' => array_sum(array_map(fn($c) => count($c['recursos']), $categories)),
            'total_imagens'  => $totalImages,
        ];
    }

    /**
     * Problemas do catálogo, um por linha, citando o recurso e o campo ou arquivo. Lista vazia = catálogo válido.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $raw = $this->raw();
        $errors = [];

        if ($raw['categorias'] === []) {
            $errors[] = 'Catálogo sem categorias';
        }
        foreach ($raw['categorias'] as $id => $category) {
            foreach (['titulo', 'rotulo', 'descricao', 'icone'] as $field) {
                if (trim((string) ($category[$field] ?? '')) === '') {
                    $errors[] = sprintf("Categoria '%s' sem o campo '%s'", $id, $field);
                }
            }
        }
        if ($raw['recursos'] === []) {
            $errors[] = 'Catálogo sem recursos';
        }

        $seen = [];
        $themesByCategory = [];
        $countByCategory = [];
        foreach ($raw['recursos'] as $i => $resource) {
            $id = trim((string) ($resource['id'] ?? ''));
            $label = $id !== '' ? $id : '#' . ($i + 1);

            foreach (self::RESOURCE_FIELDS as $field) {
                if (trim((string) ($resource[$field] ?? '')) === '') {
                    $errors[] = sprintf("Recurso '%s' sem o campo '%s'", $label, $field);
                }
            }
            if ($id !== '' && !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id)) {
                $errors[] = sprintf("Recurso '%s': o id deve ter só letras minúsculas sem acento, números e hífens", $label);
            }
            if ($id !== '' && isset($seen[$id])) {
                $errors[] = sprintf("Recurso '%s': id repetido", $id);
            }
            $seen[$id] = true;

            $category = (string) ($resource['categoria'] ?? '');
            if ($category !== '' && !isset($raw['categorias'][$category])) {
                $errors[] = sprintf("Recurso '%s': categoria '%s' não existe", $label, $category);
            }
            $countByCategory[$category] = ($countByCategory[$category] ?? 0) + 1;

            foreach (self::TEXT_FIELDS as $field) {
                $text = mb_strtolower((string) ($resource[$field] ?? ''));
                foreach (self::FORBIDDEN_WORDS as $word) {
                    if (str_contains($text, $word)) {
                        $errors[] = sprintf("Recurso '%s', campo '%s': texto proibido '%s'", $label, $field, $word);
                    }
                }
            }

            $images = (array) ($resource['imagens'] ?? []);
            if ($images === []) {
                $errors[] = sprintf("Recurso '%s' sem imagem", $label);
            }
            foreach ($images as $j => $image) {
                foreach (self::IMAGE_FIELDS as $field) {
                    if (trim((string) ($image[$field] ?? '')) === '') {
                        $errors[] = sprintf("Recurso '%s', imagem %d sem o campo '%s'", $label, $j + 1, $field);
                    }
                }
                $file = (string) ($image['arquivo'] ?? '');
                if ($file === '') {
                    continue;
                }
                $theme = self::themeOf($file);
                if ($theme === null) {
                    $errors[] = sprintf("Recurso '%s': o arquivo '%s' deve terminar em -claro.webp ou -escuro.webp", $label, $file);
                } else {
                    $themesByCategory[$category][$theme] = true;
                }
                $path = $this->imagePath($file);
                if (!is_file($path)) {
                    $errors[] = sprintf("Recurso '%s': imagem '%s' não existe em public/%s", $label, $file, self::IMAGE_DIR);
                    continue;
                }
                if (filesize($path) > self::MAX_IMAGE_BYTES) {
                    $errors[] = sprintf("Recurso '%s': imagem '%s' tem mais de 250 KB", $label, $file);
                }
                if ((getimagesize($path)['mime'] ?? '') !== 'image/webp') {
                    $errors[] = sprintf("Recurso '%s': imagem '%s' não é WebP", $label, $file);
                }
            }
        }

        // Toda categoria com mais de um recurso mostra prints dos dois temas
        foreach ($countByCategory as $category => $count) {
            if ($count > 1 && isset($raw['categorias'][$category]) && count($themesByCategory[$category] ?? []) < 2) {
                $errors[] = sprintf("Categoria '%s': precisa de prints no tema claro e no escuro", $category);
            }
        }

        return $errors;
    }

    public static function themeOf(string $file): ?string
    {
        return preg_match('/-(claro|escuro)\.webp$/', $file, $m) ? $m[1] : null;
    }

    private function imagePath(string $file): string
    {
        // Só o nome do arquivo: nada de subir de pasta a partir do catálogo
        return $this->publicDir . '/' . self::IMAGE_DIR . '/' . basename($file);
    }
}

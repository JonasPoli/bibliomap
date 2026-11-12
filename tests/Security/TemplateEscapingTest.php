<?php

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Regressão dos pontos em que texto de outros usuários (título de projeto, nome, termo, cor de grupo)
 * chegava ao HTML sem escape.
 */
class TemplateEscapingTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    public function testProjectCopyFlashIsEscaped(): void
    {
        $source = self::template('admin/user_projects.html.twig');
        $this->assertDoesNotMatchRegularExpression('/\{\{\s*message\s*\|\s*raw\s*\}\}/', $source);
        $this->assertStringContainsString('{{ message }}', $source);

        // O mesmo trecho, renderizado com o autoescape padrão do projeto
        $twig = new Environment(new ArrayLoader(['flash' => '<div>{{ message }}</div>']), ['autoescape' => 'html']);
        $html = $twig->render('flash', ['message' => sprintf('Projeto "%s" copiado com sucesso para %s!', self::PAYLOAD, self::PAYLOAD)]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testCopyFlashMessageHasNoHtml(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controller/AdminProjectController.php');
        $this->assertStringNotContainsString('<strong>', $controller);
    }

    public function testKeywordTreemapTooltipEscapesTheTerm(): void
    {
        $source = self::template('report/keywords.html.twig');

        $this->assertStringContainsString('function escHtml(', $source);
        $this->assertSame(2, substr_count($source, 'const term = escHtml(info.name);'));
        $this->assertStringNotContainsString('const term = info.name;', $source);
    }

    public function testClassificationReportOnlyUsesHexColors(): void
    {
        $source = self::template('report/classification_report.html.twig');

        $this->assertStringContainsString('function safeColor(', $source);
        $this->assertStringContainsString('const c = safeColor(g.color);', $source);
        // A cor crua do banco não pode mais ir direto para groupColors
        $this->assertDoesNotMatchRegularExpression('/\?\s*g\.color\s*:\s*PALETTE/', $source);
    }

    #[DataProvider('reportTemplates')]
    public function testJsonInScriptEscapesTags(string $file): void
    {
        $source = self::template($file);
        $this->assertStringNotContainsString('json_encode|raw', $source, 'use json_encode com JSON_HEX_TAG e JSON_HEX_AMP');
    }

    public static function reportTemplates(): array
    {
        $cases = [];
        foreach (['annual_production', 'authors', 'classification_report', 'countries', 'documents', 'general',
            'geography_report', 'institutions', 'keyword_evolution_chart', 'keywords', 'search', 'sources'] as $name) {
            $cases[$name] = ["report/{$name}.html.twig"];
        }
        return $cases;
    }

    public function testJsonFlagsNeutraliseScriptBreakout(): void
    {
        $flags = 'constant(\'JSON_HEX_TAG\') b-or constant(\'JSON_HEX_AMP\') b-or constant(\'JSON_UNESCAPED_UNICODE\')';
        $twig = new Environment(new ArrayLoader(['js' => "<script>const d = {{ data|json_encode({$flags})|raw }};</script>"]), ['autoescape' => 'html']);
        $data = ['term' => '</script><!--<script>' . self::PAYLOAD . ' & ação'];
        $html = $twig->render('js', ['data' => $data]);

        $json = substr($html, strlen('<script>const d = '), -strlen(';</script>'));
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertSame($data, json_decode($json, true), 'o JavaScript recebe o mesmo valor');
    }

    private static function template(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
    }
}

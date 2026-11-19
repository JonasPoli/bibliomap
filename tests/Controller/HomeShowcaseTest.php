<?php

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Entity\User;
use App\Showcase\ShowcaseCatalog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Seção "Recursos" da home pública (vitrine de recursos) e o redirecionamento de quem está logado.
 */
class HomeShowcaseTest extends WebTestCase
{
    public function testVisitorSeesEveryResourceOfTheCatalog(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $root = dirname(__DIR__, 2);
        $sections = (new ShowcaseCatalog($root . '/config/showcase/recursos.yaml', $root . '/public'))->sections();

        $this->assertCount(1, $crawler->filter('section#recursos'));
        $this->assertCount($sections['total_recursos'], $crawler->filter('section#recursos article.resource[id^="recurso-"]'));
        $this->assertCount(count($sections['categorias']), $crawler->filter('.showcase-category'));
        $this->assertCount(count($sections['categorias']) + 1, $crawler->filter('.showcase-filter .chip'));
        $this->assertCount(1, $crawler->filter('nav#mainNav a[href="#recursos"]'), 'Link "Recursos" no menu');

        // Número de recursos do hero vem do catálogo
        $this->assertSame((string) $sections['total_recursos'], trim($crawler->filter('.hero-stats .stat-value')->first()->text()));

        // Imagens com texto alternativo, dimensões e carregamento sob demanda; nenhuma categoria escondida no HTML
        $crawler->filter('section#recursos .window-shot img')->each(function ($img) {
            $this->assertNotSame('', (string) $img->attr('alt'));
            $this->assertGreaterThan(0, (int) $img->attr('width'));
            $this->assertGreaterThan(0, (int) $img->attr('height'));
            $this->assertSame('lazy', $img->attr('loading'));
        });
        $this->assertCount(0, $crawler->filter('.showcase-category[hidden]'));

        // Exemplo: a Lei de Bradford com os quatro blocos de texto e as duas telas
        $bradford = $crawler->filter('#recurso-lei-de-bradford');
        $this->assertCount(1, $bradford);
        $this->assertSame('Periódicos e Lei de Bradford', trim($bradford->filter('h4')->text()));
        $this->assertCount(3, $bradford->filter('.facts dd'));
        $this->assertCount(1, $bradford->filter('.example p'));
        $this->assertCount(2, $bradford->filter('.variant'));
    }

    public function testLoggedUserIsRedirectedToProjects(): void
    {
        $user = new User();
        $user->setEmail('pesquisadora@example.org');
        $user->setRoles(['ROLE_USER']);

        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())->method('generate')->with('app_projects_index')->willReturn('/projects');

        $container = new Container();
        $container->set('security.token_storage', $tokens);
        $container->set('router', $router);

        $controller = new HomeController();
        $controller->setContainer($container);

        $root = dirname(__DIR__, 2);
        $response = $controller->index(new ShowcaseCatalog($root . '/config/showcase/recursos.yaml', $root . '/public'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/projects', $response->getTargetUrl());
    }
}

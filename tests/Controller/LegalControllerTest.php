<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalControllerTest extends WebTestCase
{
    public function testTheNoticeIsPublicAndCarriesTheTmdbCredit(): void
    {
        $client = static::createClient();   // not logged in: the page must not redirect to the login form

        $client->request('GET', '/legal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Legal notice');
        $this->assertSelectorTextContains('main', 'This product uses the TMDB API but is not endorsed or certified by TMDB.');
        $this->assertSelectorExists('main a[href="https://www.themoviedb.org/"][rel~="noopener"]');
    }

    public function testTheLoginPageLinksToTheNotice(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/legal"]');
    }

    public function testTheHomePageLinksToTheNotice(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/legal"]');
    }
}

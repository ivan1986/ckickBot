<?php

namespace App\MessageHandler;

use App\Message\UpdateUrl;
use App\Service\BotSelector;
use App\Service\ClientFactory;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

#[AsMessageHandler]
final class UpdateUrlHandler
{
    #[Required] public ClientFactory $clientFactory;
    #[Required] public BotSelector $botSelector;

    public function __invoke(UpdateUrl $message): void
    {
        $client = $this->clientFactory->getOrCreateBrowser(!$message->debug);

        // load bot chat
        $page = $client->request('GET', 'https://web.telegram.org' . $message->url);
        sleep(2);
        $client->waitFor('div', 5);;
        sleep(2);

        // open miniapp
        $client->executeScript('document.getElementsByClassName("new-message-bot-commands-view")[0].click();');
        sleep(2);

        // click Launch
        try {
            $page->findElement(WebDriverBy::cssSelector('iframe'));;
        } catch (NoSuchElementException $e) {
            $client->executeScript('document.getElementsByClassName("popup-button")[0].click();');
            sleep(2);
        }

        $iframe = $page->findElement(WebDriverBy::cssSelector('iframe'));
        $src = $iframe->getAttribute('src');
        if ($message->debug) {
            echo $src . PHP_EOL;
        }
        sleep(2);

        $client->executeScript('document.getElementsByClassName("animated-close-icon")[1].click();');
        sleep(2);

        //if (!$message->debug) {
            $this->botSelector->getBot($message->name)->saveUrl($client, $src);
        //}
    }
}

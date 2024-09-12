<?php

namespace App\MessageHandler;

use App\Message\UpdateUrl;
use App\Service\BotSelector;
use App\Service\ClientFactory;
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
        $client = $this->clientFactory->getOrCreateBrowser('default', false);

        // load bot chat
        $page = $client->request('GET', 'https://web.telegram.org' . $message->url);
        sleep(2);
        $client->waitFor('div', 5);;
        sleep(2);

        // open miniapp
        $client->executeScript('document.getElementsByClassName("new-message-bot-commands-view")[0].click();');
        sleep(2);
        $iframe = $page->findElement(WebDriverBy::cssSelector('iframe'));
        $src = $iframe->getAttribute('src');
        sleep(2);

        $client->executeScript('document.getElementsByClassName("animated-close-icon")[1].click();');
        sleep(2);

        $this->botSelector->getBot($message->name)->saveUrl($src);

        $this->clientFactory->closeBrowser('default');
    }
}

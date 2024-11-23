<?php

namespace App\MessageHandler;

use App\Message\UpdateUrl;
use App\Message\UpdateUrlUser;
use App\Service\BotSelector;
use App\Service\ProfileService;
use Carbon\Carbon;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

#[AsMessageHandler]
final class UpdateUrlHandler
{
    #[Required] public LoggerInterface $logger;
    #[Required] public ProfileService $clientFactory;
    #[Required] public BotSelector $botSelector;

    public function __invoke(UpdateUrlUser $message): void
    {
        $bot = $this->botSelector->getBot($message->name);
        $bot->setProfile($message->profile);
        $this->logger->info('Update url for {profile}: {bot}', [
            'profile' => $message->profile,
            'bot' => $message->name
        ]);

        $client = $this->clientFactory->getOrCreateBrowser(
            $message->profile,
            headless: !$message->debug,
            proxy: $bot->getProxy()
        );

        // load bot chat
        $page = $client->request('GET', 'https://web.telegram.org/k/#@' . $bot->getTgBotName());
        sleep(2);
        $client->waitFor('div', 5);;
        sleep(2);

        // click Start if exist
        try {
            $page->findElement(WebDriverBy::cssSelector('.chat-input-control'));
            $client->executeScript('document.querySelector(".chat-input-control span").click();');
        } catch (NoSuchElementException $e) {
        }

        $client->waitForVisibility('.new-message-bot-commands', 50);;

        // open miniapp
        $client->executeScript('document.getElementsByClassName("new-message-bot-commands-view")[0].click();');
        sleep(2);

        $bot->runInTg($client);

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

        if ($src) { // А вдруг телеграм помер
            $bot->saveUrl($client, $src);
            $bot->UCSet('TgUrlUpdate', Carbon::now()->getTimestamp());
            $this->logger->info('Update url for {profile}: {bot} success', [
                'profile' => $message->profile,
                'bot' => $message->name
            ]);
        } else {
            $this->logger->error('Update url for {profile}: {bot} fail get url', [
                'profile' => $message->profile,
                'bot' => $message->name
            ]);
        }
    }
}

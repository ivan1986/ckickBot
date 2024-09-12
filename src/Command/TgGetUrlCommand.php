<?php

namespace App\Command;

use App\Service\ClientFactory;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Panther\Client;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'tg:getUrl',
    description: 'Add a short description for your command',
)]
class TgGetUrlCommand extends Command
{
    #[Required] public ClientFactory $clientFactory;

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->clientFactory->getOrCreateBrowser('default', false);

        $url = '/k/#@FactoraBot';

        $page = $client->request('GET', 'https://web.telegram.org' . $url);
        sleep(2);
        $client->waitFor('div', 5);;
        sleep(2);

        $client->executeScript('document.getElementsByClassName("new-message-bot-commands-view")[0].click();');
        sleep(2);
        $iframe = $page->findElement(WebDriverBy::cssSelector('iframe'));
        $src = $iframe->getAttribute('src');
        var_dump($src);
        sleep(2);
        $client->executeScript('document.getElementsByClassName("animated-close-icon")[1].click();');
        sleep(2);

        $this->clientFactory->closeBrowser('default');

        return Command::SUCCESS;
    }
}

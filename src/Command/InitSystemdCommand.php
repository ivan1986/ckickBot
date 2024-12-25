<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Environment;

#[AsCommand(
    name: 'init:systemd',
    description: 'For all profiles init bot url',
)]
class InitSystemdCommand extends Command
{
    #[Required] public Environment $twig;
    protected string $path;

    /**
     * @param string $path
     */
    public function __construct(#[Autowire('%kernel.project_dir%')] string $path)
    {
        parent::__construct();
        $this->path = $path;
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-group', 'u', InputOption::VALUE_REQUIRED, 'User and group')
            ->addOption('install', null, InputOption::VALUE_NONE, 'User and group')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$user, $group] = explode(':', $input->getOption('user-group'));

        $context = [
            'user' => $user,
            'group' => $group,
            'dir' => $this->path,
        ];

        $units = [
            'click-bot.service' => 'click-bot.service',
            'click-bot-browser.service' => 'click-bot-browser.service',
            'scheduler.service' => 'click-bot-scheduler.service',
            'custom-easy-watch.service' => 'click-bot-easy-watch.service',
            'http.service' => 'click-bot-http.service',
        ];

        foreach ($units as $name => $file) {
            $unit = $this->twig->render('init/' .$name . '.twig', $context);
            if ($input->getOption('install')) {
                file_put_contents('/etc/systemd/system/' . $file, $unit);
            } else {
                echo 'cat <<EOF > /etc/systemd/system/' . $file . PHP_EOL .
                    $unit . PHP_EOL .
                    'EOF' . PHP_EOL . PHP_EOL;
            }
        }

        return Command::SUCCESS;
    }
}


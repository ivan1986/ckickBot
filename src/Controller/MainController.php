<?php

namespace App\Controller;

use App\Model\ActionState;
use App\Service\BotSelector;
use App\Service\CacheService;
use App\Service\ProfileService;
use Carbon\Carbon;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;

class MainController extends AbstractController
{
    #[Required] public ProfileService $profileService;
    #[Required] public BotSelector $botSelector;
    #[Required] public CacheService $cacheService;

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('main/index.html.twig', [
            'profiles' => $this->profileService->list(),
            'bots' => $this->botSelector->getNames(),
            'bs' => $this->botSelector,
        ]);
    }

    #[Route('/user/{profile}', name: 'userIndex')]
    public function userIndex(string $profile)
    {

    }

    #[Route('/bot/{bot}', name: 'botIndex')]
    public function botIndex(string $bot)
    {

    }

    public function botBlock(string $title, string $profile, string $bot)
    {
        $botObj = $this->botSelector->getBot($bot);
        $botObj->setProfile($profile);
        $status = $this->cacheService->hGetAll($botObj->userKey('status'));
        $run = $this->cacheService->hGetAll($botObj->userKey('run'));
        foreach ($run as $k=>$v) {
            $run[$k] = str_replace(' ago', '', Carbon::createFromTimestamp($v)->diffForHumans());
        }

        $actionsTable = '';
        if ($actions = $botObj->getActions()) {
            $actionsTable = '<table class="table table-responsive table-nowrap">';
            foreach ($actions as $name => $actionStatusDto)
            {
                $actionsTable .= <<<TR
                    <tr>
                        <td>{$name}</td>
                        <td>{$actionStatusDto->timeAgo(ActionState::START)}</td>
                        <td>{$actionStatusDto->timeAgo(ActionState::CHANGE)}</td>
                        <td>{$actionStatusDto->lastStatus()}</td>
                    </tr>
                TR;
            }
            $actionsTable .= '</table>';
        }

        return $this->render('main/block/bot.html.twig', [
            'bs' => $this->botSelector,
            'title' => $title,
            'bot' => $botObj,
            'profile' => $profile,
            'status' => $status,
            'run' => $run,
            'actionsTable' => $actionsTable,
        ]);
    }

    #[Route('/toggle/{profile}/{bot}', name: 'toggle_bot')]
    public function toggle(string $profile, string $bot,  Request $r): Response
    {
        $this->botSelector->toggle($profile, $bot, $r->get('value'));
        return new Response('ok');
    }
}

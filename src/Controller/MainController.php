<?php

namespace App\Controller;

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

    public function botBlock(string $profile, string $bot)
    {
        $botObj = $this->botSelector->getBot($bot);
        $botObj->setProfile($profile);
        $status = $this->cacheService->hGetAll($botObj->userKey('status'));
        $run = $this->cacheService->hGetAll($botObj->userKey('run'));
        foreach ($run as $k=>$v) {
            $run[$k] = str_replace(' ago', '', Carbon::createFromTimestamp($v)->diffForHumans());
        }

        return $this->render('main/block/bot.html.twig', [
            'status' => $status,
            'run' => $run,
        ]);
    }

    #[Route('/toggle/{profile}/{bot}', name: 'toggle_bot')]
    public function toggle(string $profile, string $bot,  Request $r): Response
    {
        $this->botSelector->toggle($profile, $bot, $r->get('value'));
        return new Response('ok');
    }
}

<?php

namespace App\Controller;

use App\Service\AdminService;
use App\Service\ProfileService;
use App\Service\ProxyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Service\Attribute\Required;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Required] public AdminService $adminService;
    #[Required] public ProfileService $profileService;

    #[Route('/proxy', name: 'admin-setup-proxy')]
    public function proxy(Request $r)
    {
        $this->adminService->initEmptyUrls();
        if ($r->isMethod('POST')) {
            $proxies = $r->get('proxy');
            foreach ($proxies as $profile => $proxy) {
                $this->profileService->setProxy($profile, $proxy);
            }
            return $this->redirectToRoute('admin-setup-proxy');
        }

        return $this->render('admin/proxy.html.twig', [
            'proxies' => $this->profileService->getAllProxy(),
        ]);
    }

    #[Route('/init-urls', name: 'admin-init-urls')]
    public function initUrls()
    {
        $this->adminService->initEmptyUrls();

        return $this->redirectToRoute('home');
    }

    #[Route('/clear-profile-cache', name: 'admin-clear-profile-cache')]
    public function clearProfileCache()
    {
        $this->adminService->clearProfileCache();

        return $this->redirectToRoute('home');
    }
}

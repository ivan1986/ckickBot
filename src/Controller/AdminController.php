<?php

namespace App\Controller;

use App\Service\AdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Service\Attribute\Required;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Required] public AdminService $adminService;

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

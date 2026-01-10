<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

if (class_exists(\EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController::class)) {
    abstract class _EasyAdminDashboardBase extends \EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController
    {
    }
} else {
    abstract class _EasyAdminDashboardBase extends AbstractController
    {
    }
}

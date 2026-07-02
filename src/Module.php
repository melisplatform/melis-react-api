<?php

namespace MelisReactApi;

use Laminas\Mvc\ModuleRouteListener;
use Laminas\Mvc\MvcEvent;

class Module
{
    public function onBootstrap(MvcEvent $e)
    {
        $eventManager        = $e->getApplication()->getEventManager();
        $moduleRouteListener = new ModuleRouteListener();
        $moduleRouteListener->attach($eventManager);

        // NB : la préservation des capacités d'outils (section <meliscore_tool_capabilities>) à la
        // sauvegarde par l'ANCIEN BO est désormais assurée PAR LES MODULES concernés (modularité) :
        //  - USER  → MelisCore\Listener\MelisReactApiCapabilityPreserveUserListener
        //  - RÔLE  → MelisSmallBusiness\Listener\MelisReactApiCapabilityPreserveRoleListener
        // Le moteur de capacités (Service\Capabilities) reste ici, générique.
    }

    public function getConfig()
    {
        return include __DIR__ . '/../config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    // Module.php lives in src/, so __DIR__ === src/ →
                    // MelisReactApi\Controller\Foo maps to src/Controller/Foo.php.
                    __NAMESPACE__ => __DIR__,
                ],
            ],
        ];
    }
}

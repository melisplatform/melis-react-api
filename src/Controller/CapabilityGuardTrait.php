<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisReactApi\Service\Capabilities;

/**
 * Garde de CAPACITÉ partagée par les contrôleurs react-api (droits avancés « par composant »).
 *
 * À appeler APRÈS `denyUnlessAccess()` (l'accès-outil) : vérifie qu'une action interne
 * (list/create/edit/delete/export/…) est permise pour l'user courant. Default-allow ;
 * admin (`usr_admin`) bypass. 403 sinon. Lit le XML EFFECTIF (`getAuthRights()` → user OU rôle).
 * La classe utilisatrice doit définir `const MELIS_KEY` et fournir `jsonResponse()` + `getServiceManager()`.
 * Cf. MelisReactApi\Service\Capabilities + `<module>/config/react.capabilities.php`.
 */
trait CapabilityGuardTrait
{
    private function denyUnlessCan(string $cap): ?HttpResponse
    {
        try {
            $sm   = $this->getServiceManager();
            $auth = $sm->get('MelisCoreAuth');
            if (!empty($auth->getIdentity()->usr_admin)) {
                return null; // admin : tout permis
            }
            $xml = $auth->getAuthRights(); // XML effectif (user OU rôle)
            if (!Capabilities::isAllowed($sm->get('config'), $xml, self::MELIS_KEY, $cap)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Forbidden (capability: ' . $cap . ')'], 403);
            }
        } catch (\Throwable) {
        }
        return null;
    }
}

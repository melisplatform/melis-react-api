<?php

namespace MelisReactApi\Service;

/**
 * Capacités d'outil (système de droits « avancés » du back-office React).
 *
 * Couche AUTONOME, indépendante de `app.interface.php` et du rendu legacy : juste de la donnée
 * plate + un resolver. Elle est SUBORDONNÉE à l'accès-outil (`MelisCoreRights::canAccess`) qui,
 * lui, ne change pas — ici on ne décide que des COMPOSANTS INTERNES d'un outil déjà autorisé
 * (voir la liste / créer / éditer / supprimer, et plus tard des clés custom).
 *
 * Déclaration : chaque module déclare, par `melisKey` d'outil, les capacités qui EXISTENT, via la
 * clé de config mergée `melisReactToolCapabilities` (tous les outils n'ont pas tout) :
 *   'melisReactToolCapabilities' => ['melis_core_announcement_tool' => ['list','create','edit','delete']]
 *
 * Stockage par user/rôle : une SECTION DÉDIÉE dans le XML `usr_rights`/`urole_rights`, séparée de
 * l'existant (deny-list) — l'ancien BO ne la lit pas ; un listener la préserve à son save :
 *   <meliscore_tool_capabilities>
 *     <tool key="melis_core_announcement_tool"><deny>delete</deny></tool>
 *   </meliscore_tool_capabilities>
 *
 * Sémantique DEFAULT-ALLOW : absence de donnée = tout permis (compat totale ; un user/role legacy
 * sans la section garde le CRUD complet, et une capacité ajoutée plus tard est permise par défaut).
 */
class Capabilities
{
    public const SECTION   = 'meliscore_tool_capabilities';
    public const CONFIG_KEY = 'melisReactToolCapabilities';

    /** Déclaration mergée : [ toolKey => [caps...] ]. */
    public static function declared(array $appConfig): array
    {
        $d = $appConfig[self::CONFIG_KEY] ?? [];
        return is_array($d) ? $d : [];
    }

    /**
     * Extrait le bloc `<meliscore_tool_capabilities>…</meliscore_tool_capabilities>` d'un XML de
     * droits (chaîne brute), pour le préserver à la sauvegarde legacy. '' si absent/vide.
     */
    public static function extractSection(string $rightsXml): string
    {
        if ($rightsXml === '') {
            return '';
        }
        $start = strpos($rightsXml, '<' . self::SECTION);
        if ($start === false) {
            return '';
        }
        $closeTag = '</' . self::SECTION . '>';
        $end = strpos($rightsXml, $closeTag, $start);
        if ($end === false) {
            return ''; // section vide/auto-fermante : rien d'utile à préserver
        }
        return substr($rightsXml, $start, $end - $start + strlen($closeTag));
    }

    /** Capacités retirées (deny-list) pour un outil, lues dans le XML de droits. */
    public static function deniedFor(?string $rightsXml, string $toolKey): array
    {
        if (empty($rightsXml)) {
            return [];
        }
        $obj = @simplexml_load_string(trim($rightsXml));
        if ($obj === false || !isset($obj->{self::SECTION})) {
            return [];
        }
        $denied = [];
        foreach ($obj->{self::SECTION}->tool as $tool) {
            if ((string) $tool['key'] !== $toolKey) {
                continue;
            }
            foreach ($tool->deny as $deny) {
                $denied[] = (string) $deny;
            }
        }
        return $denied;
    }

    /**
     * Une capacité est-elle permise pour cet outil ?
     * - outil non déclaré → pas de modèle de capacités → permis (non gardé).
     * - capacité non déclarée pour l'outil → permis (rien à garder).
     * - sinon : permis SSI pas dans la deny-list.
     */
    public static function isAllowed(array $appConfig, ?string $rightsXml, string $toolKey, string $cap): bool
    {
        $caps = self::declared($appConfig)[$toolKey] ?? null;
        if ($caps === null || !in_array($cap, $caps, true)) {
            return true;
        }
        return !in_array($cap, self::deniedFor($rightsXml, $toolKey), true);
    }

    /**
     * Carte des capacités PERMISES par outil, pour l'utilisateur courant (payload /me).
     * Passer `$rightsXml = null` (ex. admin) ⇒ tout permis.
     */
    public static function allowedForUser(array $appConfig, ?string $rightsXml): array
    {
        $out = [];
        foreach (self::declared($appConfig) as $toolKey => $caps) {
            if (!is_array($caps)) {
                continue;
            }
            $denied = self::deniedFor($rightsXml, $toolKey);
            $out[$toolKey] = array_values(array_filter($caps, static fn ($c) => !in_array($c, $denied, true)));
        }
        return $out;
    }
}

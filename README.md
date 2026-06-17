# MelisReactApi

Module Laminas du back-office React de [Melis Platform](https://www.melistechnology.com).

Expose les endpoints JSON consommés par l'application React (`ui-react` de MelisCore),
sous la route `/melis/react-api/*` — format `{ success, data, error? }`, authentifiés via
`MelisCoreAuth`.

Endpoints principaux : `/me`, `/menu`, `/langs`, `/assets`, `/react-modules` (découverte des
briques des modules actifs), `/roles`, et les endpoints Users du cœur MelisCore.

> Module chargé via `config/application.config.php` (clé `modules`), **pas** via
> `config/melis.module.load.php` (que l'outil Modules réécrirait).

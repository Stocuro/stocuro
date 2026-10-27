<?php
/**
 * "Het Team"-onderdeel dat eerst op het
 * Gecombineerde Nieuws-dashboard stond, nu een eigen
 * endpoint met een eigen scherm-recht ('team') -- gebruikt binnen het
 * "Team & Prestaties"-dashboard naast Leaderboard, zie TEAM_TABS in
 * assets/js/app.js. Alleen lezen, zie includes/nieuws_dashboard.php voor
 * getTeamOverzicht() zelf (ongewijzigd).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/nieuws_dashboard.php';

apiRequireLogin();
apiRequireScherm('team');
apiTry(fn() => getTeamOverzicht());

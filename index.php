<?php
/**
 * Migratie 042, op verzoek van Harm: eenvoudige "waar moet ik heen"-router
 * voor STANDALONE installaties (Docker/Synology/eigen Linux-server) die
 * verder niets anders in dezelfde webroot hebben staan. Zonder dit
 * bestand kwam iemand die net het ZIP-bestand had uitgepakt en de kale
 * domeinnaam/IP opende op een lege pagina of een 404 terecht, zonder te
 * weten dat install.php bestaat.
 *
 * BEWUST NIET meegeleverd bij de gecombineerde stocuro.com/One.com-opzet
 * (app + stocuro-landing samen in dezelfde webroot, zie README.md "Why
 * the flat folder structure..."): dat is precies de situatie waarvoor
 * de dashboardpagina destijds van index.php naar app.php is hernoemd,
 * om een naamsbotsing met de landingspagina's index.html te voorkomen.
 * Dit bestand zou die botsing weer terugbrengen. Bij die specifieke
 * combinatie-installatie hoort dit bestand dus VERWIJDERD te worden na
 * het uitpakken -- zie README.md stap 7 (One.com-installatiegids) en
 * DOCKER.md voor de precieze instructie per scenario.
 *
 * Logica is bewust hetzelfde als install.php's eigen fase-bepaling
 * (state, geen sessievlaggen): staat install.lock er nog niet, dan is de
 * installatie nog niet compleet -> naar install.php. Staat install.lock
 * er wel, dan is de installatie klaar -> naar login.php (dat zelf al
 * meteen doorstuurt naar app.php als er al een ingelogde sessie is, zie
 * login.php regel 5-8).
 */

declare(strict_types=1);

if (file_exists(__DIR__ . '/install.lock')) {
    header('Location: login.php');
} else {
    header('Location: install.php');
}
exit;

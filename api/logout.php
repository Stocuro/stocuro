<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';

doLogout();
jsonResponse(['success' => true]);

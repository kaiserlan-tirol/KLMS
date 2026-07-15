<?php
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

// Set default timezone
date_default_timezone_set('Europe/Vienna');

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Force manual loading of .env files to ensure they override Symfony CLI environment
if (class_exists(Dotenv::class)) {
    $dotenv = new Dotenv();
    $projectDir = __DIR__ . '/../';
    
    // Get current environment
    $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
    
    // Load .env files in order (later files override earlier ones)
    $envFiles = [
        $projectDir.'.env',
        $projectDir.'.env.local',
        $projectDir.'.env.'.$env,
        $projectDir.'.env.'.$env.'.local',
    ];
    
    foreach ($envFiles as $envFile) {
        if (file_exists($envFile)) {
            $dotenv->loadEnv($envFile);
        }
    }
    
    // Force correct DATABASE_URL from .env.local (override Symfony CLI detection)
    if ($env === 'dev' && file_exists($projectDir.'.env.local')) {
        // Read the DATABASE_URL from .env.local manually
        $envLocalContent = file_get_contents($projectDir.'.env.local');
        if (preg_match('/^DATABASE_URL=(.+)$/m', $envLocalContent, $matches)) {
            $databaseUrl = trim($matches[1], '"\'');
            $_ENV['DATABASE_URL'] = $databaseUrl;
            putenv('DATABASE_URL=' . $databaseUrl);
        }
    }
}

if (!function_exists('pdie')) {
  function pdie($var) {
      echo '<pre>';
      print_r($var);
      echo '</pre>';
      die();
  }
}

return function (array $context) {
    if ($context['APP_ENV'] == 'dev') {
        error_reporting(E_ALL ^ E_DEPRECATED);
        // ini_set('memory_limit', '256M');
    }

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

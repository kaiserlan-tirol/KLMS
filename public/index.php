<?php
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

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
        ini_set('memory_limit', '256M');
    }

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

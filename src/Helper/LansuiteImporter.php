<?php

namespace App\Helper;

class LansuiteImporter
{
    public static function getFilteredUsersFromExports($hsBasePath): array {
      // ls_users table data as php array (phpmyadmin php export)
      require_once($hsBasePath . 'ls_user.php');

      $newsletterUsersCsvPath = $hsBasePath . 'newsletter_user_export.csv';
      // parse csv, first line contains the header names, build array map with headers as key with semicolon delimiter and double quotes
      $activeNewsletterEmails = [
        'wolfgang.janes@groovie.biz',
        'tomkreinig@gmx.at',
        'nicola.zoppello@yahoo.de',
        'kaisermann@hotmail.de',
        'manuel.binder1@gmx.at',
        'enoidura@gmail.com',
        'aufschnaiterhep@gmail.com',
      ];
      $inActiveNewsletterEmails = [];
      if (($handle = fopen($newsletterUsersCsvPath, 'r')) !== false) {
        $header = fgetcsv($handle, 0, ';');
  
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
          $row = array_combine($header, $data);
          if ($row['active'] == 1) {
            $activeNewsletterEmails[] = $row['email'];
          } else {
            $inActiveNewsletterEmails[] = $row['email'];
          }
        }
  
        fclose($handle);
      }
    
      echo "Active Newsletter Emails: " . count($activeNewsletterEmails) . "\n";
      echo "Inactive Newsletter Emails: " . count($inActiveNewsletterEmails) . "\n";

      // filter active newsletter users
      $filteredUsers = array_filter($ls_user, function ($user) use ($activeNewsletterEmails, $inActiveNewsletterEmails) {
        $isActiveNewletterUser = in_array($user['email'], $activeNewsletterEmails);
        if (!$isActiveNewletterUser) {
          echo "User Newsletter inactive: " . $user['email'] . "\n";
          $isNotInInaActive = !in_array($user['email'], $inActiveNewsletterEmails);
          if ($isNotInInaActive) {
            echo "User not in active or inactive newsletter: " . $user['email'] . "\n";
          }
        }
        return $isActiveNewletterUser;
      });

      return $filteredUsers;
    }

    public static function getRandomString($n)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
    
        for ($i = 0; $i < $n; $i++) {
            $index = rand(0, strlen($characters) - 1);
            $randomString .= $characters[$index];
        }
    
        return $randomString;
    }
}

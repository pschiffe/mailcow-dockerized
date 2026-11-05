<?php

function roundcube_sso_mailbox_allowed(PDO $pdo, string $username): bool {
  $stmt = $pdo->prepare("SELECT `mailbox`.`attributes`
    FROM `mailbox`
    INNER JOIN `domain` ON `mailbox`.`domain` = `domain`.`domain`
    WHERE `mailbox`.`username` = :username
      AND `mailbox`.`active` = '1'
      AND `domain`.`active` = '1'
      AND `mailbox`.`kind` NOT REGEXP 'location|thing|group'");
  $stmt->execute([':username' => $username]);
  $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
  $attributes = $mailbox ? json_decode($mailbox['attributes'], true) : null;
  return $mailbox !== false && ($attributes['shared'] ?? '0') != '1';
}

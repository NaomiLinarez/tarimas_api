<?php
require_once 'config.php';
$hash = password_hash('admin1234', PASSWORD_DEFAULT);
$db = getDB();
$db->prepare("UPDATE usuarios SET password_hash = ? WHERE usuario = 'admin'")->execute([$hash]);
echo "Listo. Hash actualizado: " . $hash;

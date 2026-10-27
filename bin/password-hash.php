<?php
// Offline helper for hosts without a terminal. Run locally, then import the hash privately.
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
fwrite(STDERR,"Password (input hidden on a terminal): ");
$tty=function_exists('stream_isatty')&&stream_isatty(STDIN); if($tty) system('stty -echo');
try { $password=rtrim(fgets(STDIN)?:'',"\r\n"); } finally { if($tty) { system('stty echo'); fwrite(STDERR,"\n"); } }
echo Pivot\Auth::password($password)."\n";

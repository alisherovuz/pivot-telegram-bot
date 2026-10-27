<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$email=mb_strtolower(trim($argv[1]??'')); $role=$argv[2]??'owner';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['owner','reviewer'],true)) { fwrite(STDERR,"Usage: php bin/create-admin.php email owner|reviewer\nPassword is read from standard input (not command arguments).\n"); exit(1); }
fwrite(STDERR,"Password (12–72 bytes; input hidden on a terminal): ");
$tty=function_exists('stream_isatty') && stream_isatty(STDIN);
if($tty) system('stty -echo');
try { $password=rtrim(fgets(STDIN)?:'',"\r\n"); } finally { if($tty) { system('stty echo'); fwrite(STDERR,"\n"); } }
try { $hash=Pivot\Auth::password($password); $db->run('INSERT INTO admins(email,password_hash,role) VALUES(?,?,?)',[$email,$hash,$role]); echo "Admin created.\n"; }
catch(Throwable) { fwrite(STDERR,"Could not create account. Check password length, email uniqueness and database.\n"); exit(1); }

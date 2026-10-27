<?php
declare(strict_types=1);
// Build a deployable archive from an explicit allowlist. Never package local credentials or user data.
if(PHP_SAPI!=='cli') exit(1);
$root=dirname(__DIR__); $out=$root.'/dist/pivot-deployment.zip';
if(!is_file($root.'/vendor/autoload.php')) { fwrite(STDERR,"Install locked dependencies first.\n"); exit(1); }
@mkdir($root.'/dist',0700,true);
$zip=new ZipArchive(); if($zip->open($out,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create ZIP');
$files=[];
foreach(['app','public','bin','database','docs','vendor'] as $dir) {
    $walk=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS), static fn($file)=>!$file->isLink() && !in_array($file->getFilename(),['.git','.github','.DS_Store'],true)));
    foreach($walk as $file) if($file->isFile()) $files[]=substr($file->getPathname(),strlen($root)+1);
}
array_push($files,'config/example.php','composer.json','composer.lock','README.md','.htaccess'); sort($files);
$manifest=[];
foreach($files as $file) { $zip->addFile($root.'/'.$file,'pivot/'.$file); $manifest[$file]=hash_file('sha256',$root.'/'.$file); }
$zip->addFromString('pivot/MANIFEST.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$zip->addEmptyDir('pivot/storage/private'); $zip->addEmptyDir('pivot/storage/cache'); $zip->close();
file_put_contents($out.'.sha256',hash_file('sha256',$out).'  '.basename($out)."\n");
echo 'Created '.$out.' ('.filesize($out)." bytes)\n";

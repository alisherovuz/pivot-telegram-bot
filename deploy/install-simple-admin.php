<?php
declare(strict_types=1);
// CLI-only updater. Includes no credentials and performs no Telegram requests.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
$root=realpath($argv[1]??'');
if(!$root || !is_file($root.'/composer.json') || (json_decode(file_get_contents($root.'/composer.json'),true)['name']??'')!=='pivot/telegram-bot') {
    fwrite(STDERR,"Usage: php install.php /absolute/path/to/pivot\nTarget must be the Pivot project root.\n"); exit(1);
}
$files=['app/Telegram.php','app/Queue.php','app/AdminBot.php','app/Bot.php'];
$manifest=json_decode(file_get_contents(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($files as $file) {
    $source=__DIR__.'/files/'.$file;
    if(!is_file($source) || hash_file('sha256',$source)!==($manifest[$file]['after']??'')) {
        fwrite(STDERR,"Update integrity check failed. Download the update again.\n"); exit(1);
    }
}
$lock=null; $locked=false; $applied=[]; $staged=[]; $backup=null;
try {
    require $root.'/app/bootstrap.php';
    // Coordinate with the original cron implementation before replacing shared code.
    $lock='pivot-cron-'.hash('sha256',$config['dsn']);
    if((int)$db->value('SELECT GET_LOCK(?,2)',[$lock])!==1) {
        fwrite(STDERR,"Cron is currently running. No changes made; retry this command shortly.\n"); exit(2);
    }
    $locked=true;
    $already=true;
    foreach($files as $file) {
        $target=$root.'/'.$file;
        if(is_link($target)) throw new RuntimeException('Unexpected symlink: '.$file);
        $current=is_file($target)?hash_file('sha256',$target):null;
        if($current!==$manifest[$file]['after']) $already=false;
        if($current!==$manifest[$file]['before'] && $current!==$manifest[$file]['after']) throw new RuntimeException('Unexpected local changes: '.$file);
    }
    if($already) { echo "Simple admin update is already installed. No changes made.\n"; exit; }
    $backup=rtrim($config['storage'],'/').'/upgrades/simple-admin-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    if(!mkdir($backup,0700,true)) throw new RuntimeException('Cannot create private backup');
    foreach($files as $file) {
        $target=$root.'/'.$file;
        if(is_file($target)) {
            $dir=dirname($backup.'/'.$file); if(!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Cannot create backup directory');
            if(!copy($target,$backup.'/'.$file)) throw new RuntimeException('Cannot back up '.$file);
            chmod($backup.'/'.$file,0600);
        }
        $temp=tempnam(dirname($target),'.pivot-upgrade-');
        if($temp===false) throw new RuntimeException('Cannot stage '.$file);
        $staged[$file]=$temp;
        if(!copy(__DIR__.'/files/'.$file,$temp) || !chmod($temp,0644) || hash_file('sha256',$temp)!==$manifest[$file]['after']) throw new RuntimeException('Cannot stage '.$file);
    }
    // Install dependencies first and enable the bot admin route last.
    foreach($files as $file) {
        if(!rename($staged[$file],$root.'/'.$file)) throw new RuntimeException('Cannot replace '.$file);
        unset($staged[$file]); $applied[]=$file;
        if(function_exists('opcache_invalidate')) @opcache_invalidate($root.'/'.$file,true);
    }
    echo "Simple admin update installed successfully.\nConfiguration, database records and uploaded files were preserved.\nSend /admin in your private bot chat to open the simpler menu. Existing admin access and cron are unchanged.\nBackups are in your private storage/upgrades directory.\n";
} catch(Throwable $e) {
    $rollbackOk=true;
    foreach(array_reverse($applied) as $file) {
        $target=$root.'/'.$file;
        if(is_file($backup.'/'.$file)) {
            $temp=tempnam(dirname($target),'.pivot-rollback-');
            if($temp===false || !copy($backup.'/'.$file,$temp) || !chmod($temp,0644) || !rename($temp,$target)) $rollbackOk=false;
        } elseif(is_file($target) && !unlink($target)) $rollbackOk=false;
    }
    // Never print exception text that might contain database credentials.
    fwrite(STDERR,'Update failed ('.get_class($e).'). '.($rollbackOk?'Applied files were restored.':'Some files could not be restored; preserve the private backup.')."\n");
    exit(1);
} finally {
    foreach($staged as $temp) if(is_file($temp)) unlink($temp);
    if($locked) $db->run('SELECT RELEASE_LOCK(?)',[$lock]);
}

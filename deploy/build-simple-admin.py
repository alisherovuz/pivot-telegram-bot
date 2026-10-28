#!/usr/bin/env python3
"""Build the small, credential-free update against the deployed base release."""
import hashlib, json, pathlib, subprocess, zipfile
root=pathlib.Path(__file__).resolve().parent.parent
base='cc326f1367eeaf33f03b63f758e18aa7ece20764'
files=['app/Telegram.php','app/Queue.php','app/AdminBot.php','app/Bot.php']
manifest={}
out=root/'dist/pivot-simple-admin.zip'
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
    def put(name,data):
        info=zipfile.ZipInfo('pivot-simple-admin/'+name)
        info.create_system=3
        info.external_attr=0o100644 << 16
        info.compress_type=zipfile.ZIP_DEFLATED
        z.writestr(info,data)
    for name in files:
        data=(root/name).read_bytes()
        old=subprocess.run(['git','show',base+':'+name],cwd=root,capture_output=True)
        if old.returncode: raise RuntimeError('Cannot read base file: '+name)
        manifest[name]={'before':hashlib.sha256(old.stdout).hexdigest() if old.returncode==0 else None,'after':hashlib.sha256(data).hexdigest()}
        put('files/'+name,data)
    put('manifest.json',(json.dumps(manifest,indent=2)+'\n').encode())
    put('install.php',(root/'deploy/install-simple-admin.php').read_bytes())
    put('GUIDE.md',(root/'docs/SIMPLE-ADMIN.md').read_bytes())
    put('README.txt',b'Extract this folder, then run: php install.php /absolute/path/to/pivot\nCLI only. Preserves private config, database and uploads. Keeps cron for retries.\n')
(root/'dist/pivot-simple-admin.zip.sha256').write_text(hashlib.sha256(out.read_bytes()).hexdigest()+'  '+out.name+'\n')
print(f'Created {out.name}: {out.stat().st_size} bytes')

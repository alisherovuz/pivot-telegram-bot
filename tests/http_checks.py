#!/usr/bin/env python3
"""HTTP tests against the separate fake-transport test server. Run tests/run.php first."""
import http.cookiejar, json, re, urllib.request, urllib.parse, urllib.error, zipfile, io
BASE='http://127.0.0.1:8081'
count=0
class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar()
        self.http=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar))
    def get(self,path='/', data=None, headers=None, raw=None):
        body=raw if raw is not None else urllib.parse.urlencode(data).encode() if data is not None else None
        request=urllib.request.Request(BASE+path,data=body,headers=headers or {})
        try: r=self.http.open(request)
        except urllib.error.HTTPError as err: r=err
        return r.status,r.read(),r.headers
    def token(self,path='/?page=login'):
        status,body,h=self.get(path)
        assert status==200,(status,body)
        return re.search(rb'name="csrf" value="([^"]+)"',body).group(1).decode()
    def login(self,email,password):
        return self.get('/?page=login',{'action':'login','csrf':self.token(),'email':email,'password':password})
def check(ok,label):
    global count
    assert ok,label
    count+=1; print('PASS',label)
anon=Client()
status,body,h=anon.get('/?page=download&id=1'); check(status==401,'attachment requires login')
status,body,h=anon.get('/?page=export&format=xlsx'); check(status==401,'export requires login')
status,body,h=anon.get('/config/local.php'); check(status==404,'private configuration outside document root')
status,body,h=anon.get('/storage/private/example.bin'); check(status==404,'private storage outside document root')
status,body,h=anon.get('/?page=login'); check(status==200 and b'Sign in to Pivot' in body,'login page renders')
check("frame-ancestors 'none'" in h['Content-Security-Policy'],'security headers present')
check('HttpOnly' in h.get('Set-Cookie','') or all(c.has_nonstandard_attr('HttpOnly') for c in anon.jar),'session cookies are HttpOnly')
status,body,h=anon.get('/?page=login',{'action':'login','email':'owner@example.test','password':'TestOwner!12345','csrf':'wrong'}); check(status==403,'HTTP invalid CSRF rejected')
status,body,h=anon.get('/webhook.php',raw=b'{}',headers={'Content-Type':'application/json'}); check(status==403,'webhook missing secret rejected')
secret={'Content-Type':'application/json','X-Telegram-Bot-Api-Secret-Token':'test-webhook-secret-000000000000000000'}
status,body,h=anon.get('/webhook.php',raw=b'{broken',headers=secret); check(status==400,'malformed webhook rejected')
status,body,h=anon.get('/webhook.php',raw=b'{}',headers=secret); check(status==400,'webhook missing update ID rejected')
update={'update_id':900001,'message':{'message_id':900001,'chat':{'id':900001,'type':'private'},'from':{'id':900001},'text':'/start'}}
for i in range(2):
    status,body,h=anon.get('/webhook.php',raw=json.dumps(update).encode(),headers=secret); check(status==200 and json.loads(body)['ok'],'webhook accepts and acknowledges duplicate safely '+str(i))
status,body,h=anon.get('/webhook.php',raw=b'x'*1048577,headers=secret); check(status==413,'oversized webhook rejected')
owner=Client(); status,body,h=owner.login('owner@example.test','TestOwner!12345'); check(status==200 and b'Good ideas deserve' in body,'owner HTTP login and dashboard')
for page in ['applications','application&id=1','statistics','queue','settings','team','account']:
    status,body,h=owner.get('/?page='+page); check(status==200 and b'Fatal error' not in body,'admin page renders: '+page)
status,body,h=owner.get('/?page=download&id=1'); check(status==200 and body==b'Fake private attachment: project101','authorized original attachment bytes preserved')
check(h['Content-Type']=='application/octet-stream' and 'attachment;' in h['Content-Disposition'],'download forced as attachment')
status,body,h=owner.get('/?page=export&format=xlsx'); check(status==200 and zipfile.is_zipfile(io.BytesIO(body)),'HTTP genuine XLSX export')
status,body,h=owner.get('/?page=export&format=pdf&id=1'); check(status==200 and body.startswith(b'%PDF-'),'HTTP individual PDF export')
status,body,h=owner.get('/?page=export&format=pdf&status=new'); check(status==200 and body.startswith(b'%PDF-'),'HTTP filtered PDF report')
status,body,h=owner.get('/?page=export&scope=statistics&format=xlsx'); check(status==200 and zipfile.is_zipfile(io.BytesIO(body)),'HTTP statistics export')
status,body,h=owner.get('/?page=applications&from=2026-02-31'); check(status==422,'invalid date filter returns validation error')
reviewer=Client(); status,body,h=reviewer.login('reviewer@example.test','TestReviewer!12345'); check(status==200 and b'Good ideas deserve' in body,'reviewer HTTP login')
for page in ['settings','team']:
    status,body,h=reviewer.get('/?page='+page); check(status==403,'reviewer denied '+page)
status,body,h=reviewer.get('/?page=dashboard',{'action':'admin_create','csrf':reviewer.token('/?page=account'),'email':'bad@example.test','password':'UnsafeAccount!123','role':'owner'}); check(status==403,'reviewer cannot forge owner action')
status,body,h=reviewer.get('/?page=application&id=1',{'action':'note','csrf':reviewer.token('/?page=account'),'id':1,'body':'<script>alert("xss")</script>'}); check(status==200 and b'&lt;script&gt;' in body and b'<script>' not in body,'stored note HTML escaped')
status,body,h=owner.get('/?page=dashboard',{'action':'logout','csrf':owner.token('/?page=account')}); check(status==200 and b'Sign in to Pivot' in body,'logout returns login')
status,body,h=owner.get('/?page=download&id=1'); check(status==401,'logout revokes attachment access')
with open('.dev/test-output/http-result.json','w') as f: json.dump({'passed':count,'failed':0},f)
print(f'\n{count} HTTP assertions passed. No live Telegram requests.')

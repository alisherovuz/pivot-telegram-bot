<?php
declare(strict_types=1);
namespace Pivot;
final class Auth {
    public function __construct(private Db $db) {}
    public static function session(bool $secure): void {
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
        session_name('pivot_admin'); session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>'/']); session_start();
        if(!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public static function csrf(string $token): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$token); }
    public function current(): ?array {
        if(!isset($_SESSION['admin_id'])) return null;
        if(time()-($_SESSION['last_active']??0)>1800 || time()-($_SESSION['created_at']??0)>28800) { self::logout(); return null; }
        $a=$this->db->one('SELECT * FROM admins WHERE id=? AND active=1',[$_SESSION['admin_id']]);
        if(!$a || $a['session_version']!==($_SESSION['session_version']??null)) { self::logout(); return null; }
        $_SESSION['last_active']=time(); return $a;
    }
    public function login(string $email,string $password,string $ip): bool {
        $key=hash('sha256',$ip); $email=mb_strtolower(trim($email));
        $this->db->run('INSERT INTO login_attempts(identity_hash,attempts,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(window_started<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),window_started=IF(window_started<DATE_SUB(NOW(),INTERVAL 15 MINUTE),NOW(),window_started)',[$key]);
        if((int)$this->db->value('SELECT attempts FROM login_attempts WHERE identity_hash=?',[$key])>10) return false;
        $a=$this->db->one('SELECT * FROM admins WHERE email=? AND active=1',[$email]);
        $valid=password_verify($password,$a['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if(!$a || !$valid) return false;
        if(password_needs_rehash($a['password_hash'],PASSWORD_DEFAULT)) $this->db->run('UPDATE admins SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$a['id']]);
        session_regenerate_id(true); $_SESSION=['admin_id'=>$a['id'],'session_version'=>$a['session_version'],'created_at'=>time(),'last_active'=>time(),'csrf'=>bin2hex(random_bytes(32))];
        $this->db->run('DELETE FROM login_attempts WHERE identity_hash=?',[$key]); $this->db->audit((int)$a['id'],null,'login','Successful login'); return true;
    }
    public static function logout(): void { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); }
    public static function owner(array $admin): void { if($admin['role']!=='owner') throw new \DomainException('Owner access required'); }
    public static function password(string $password): string { if(mb_strlen($password)<12 || strlen($password)>72) throw new \InvalidArgumentException('Password must be 12–72 bytes'); return password_hash($password,PASSWORD_DEFAULT); }
}

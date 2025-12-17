<?php
if (!defined('DATALIFEENGINE')) die("Hacking attempt!");

include_once (DLEPlugins::Check(ENGINE_DIR . '/data/spamguard_config.php'));

if(!$sg_config['status']) return;
$user_ip = $_IP;

if(isset($_POST['register'])) {
    
    // StopForumSpam Check
    if($sg_config['sfs_api']) {
        $email = urlencode($_POST['email']);
        $ip = urlencode($user_ip);
        $username = urlencode($_POST['name']);
        
        $api_url = "http://api.stopforumspam.org/api?ip=$ip&email=$email&username=$username&f=json";
        
        // Use curl for better reliability
        if(function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $response = @file_get_contents($api_url);
        }
        
        if($response) {
            $data = json_decode($response, true);
            if(isset($data['success']) && $data['success'] == 1) {
                if($data['ip']['appears'] || $data['email']['appears'] || $data['username']['appears']) {
                     if(!function_exists('sg_log_spam')) {
                        function sg_log_spam($reason, $data = '') {
                            global $db, $user_ip, $_TIME;
                            if(!$_TIME) $_TIME = time(); // fallback
                            $db->query("INSERT INTO " . PREFIX . "_spam_logs (ip, date, username, reason, data) VALUES ('$user_ip', '$_TIME', 'Guest', '$reason', '$data')");
                        }
                     }
                     sg_log_spam("StopForumSpam Match", "IP: {$data['ip']['frequency']}, Email: {$data['email']['frequency']}, User: {$data['username']['frequency']}");
                     msg("error", "Hata", "Spam veritabanında kaydınız bulundu. Kayıt olamazsınız.");
                }
            }
        }
    }

    if($sg_config['ban_disposable']) {
        $email = strtolower(trim($_POST['email']));
        $domain = substr(strrchr($email, "@"), 1);
        $banned_domains = explode(",", $sg_config['banned_domains']);
        if(in_array($domain, $banned_domains)) {
             msg("error", "Hata", "Kullandığınız e-posta servisinin bu siteye kayıt olması engellenmiştir.");
        }
    }
}
?>
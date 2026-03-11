<?php
if (!defined('DATALIFEENGINE')) die("Hacking attempt!");

include_once (DLEPlugins::Check(ENGINE_DIR . '/data/spamguard_config.php'));

if(!$sg_config['status']) return;

global $db, $_TIME;
$user_ip = get_ip(); 

// 1. Güvenli Log Tutma Fonksiyonu (SQL Injection Korumalı)
if(!function_exists('sg_log_spam')) {
    function sg_log_spam($reason, $data = '') {
        global $db, $user_ip, $_TIME;
        if(!$_TIME) $_TIME = time(); 
        
        $reason_safe = $db->safesql($reason);
        $data_safe = $db->safesql($data);
        
        $db->query("INSERT INTO " . PREFIX . "_spam_logs (ip, date, username, reason, data) VALUES ('$user_ip', '$_TIME', 'Guest', '$reason_safe', '$data_safe')");
    }
}

// =========================================================================
// Panelden Gelen Ayarları Motora Bağlama (Proxy ve Süre)
// =========================================================================

// Whitelist Kontrolü - IP veya email listede varsa tüm kontroller atlanır
$sg_whitelisted = false;
if(isset($_POST['register']) || isset($_POST['submit_reg'])) {
    $check_ip = $db->safesql($user_ip);
    $wl_ip = $db->super_query("SELECT id FROM " . PREFIX . "_spam_whitelist WHERE type='ip' AND data='$check_ip'");
    if($wl_ip['id']) {
        $sg_whitelisted = true;
    } elseif(!empty($_POST['email'])) {
        $check_email = $db->safesql(strtolower(trim($_POST['email'])));
        $check_domain = $db->safesql(substr(strrchr($check_email, "@"), 1));
        $wl_email = $db->super_query("SELECT id FROM " . PREFIX . "_spam_whitelist WHERE (type='email' AND data='$check_email') OR (type='domain' AND data='$check_domain')");
        if($wl_email['id']) $sg_whitelisted = true;
    }
}

// 2. Form Gönderildiğinde Güvenlik Duvarları Devreye Girer
if(!$sg_whitelisted && (isset($_POST['register']) || isset($_POST['submit_reg']))) {

    // Proxy / VPN Engelleme (Admin Panelinden Aktif Edildiyse)
    // NOT: HTTP_X_FORWARDED_FOR ve HTTP_CLIENT_IP listeden çıkarıldı.
    // Bu headerlar Cloudflare, load balancer gibi meşru sistemlerde de gelir,
    // açık bırakılırsa tüm ziyaretçiler bloklanır.
    if(intval($sg_config['block_proxy']) == 1) {
        $proxy_headers = array('HTTP_VIA', 'HTTP_X_CAME_FROM', 'HTTP_PROXY_CONNECTION', 'HTTP_FORWARDED');
        foreach($proxy_headers as $header) {
            if(!empty($_SERVER[$header])) {
                sg_log_spam("Proxy/VPN Engeli", "Tespit Edilen Header: {$header}");
                msg("error", "Güvenlik Engeli", "VPN veya Proxy bağlantısı ile kayıt yapılamaz.");
            }
        }
    }

    // Zaman Kontrolü (Admin Panelinden Gelen Min. Süreye Göre)
    // Hidden field yöntemi: Session'dan daha güvenilir.
    // Bot direkt POST atsa bile form_time ya boş gelir ya da 0 olur, yakalanır.
    $min_time = intval($sg_config['min_reg_time']);
    if($min_time > 0) {
        $form_time = intval($_POST['mws_form_time'] ?? 0);
        if(!$form_time || (time() - $form_time) < $min_time) {
            $elapsed = $form_time ? (time() - $form_time) : 0;
            sg_log_spam("Hiz Limiti Asildi", "Gecen sure: {$elapsed} sn, Min: {$min_time} sn");
            msg("error", "Güvenlik Engeli", "Formu doldurmak için çok az zaman harcandı. Lütfen tekrar deneyin.");
        }
    }
    
    // HoneyPot Kontrolü (Görünmez Tuzak)
    if(!empty($_POST['mws_token'])) {
        sg_log_spam("Honeypot Yakalamasi", "Gizli form alani dolduruldu.");
        msg("error", "Güvenlik Engeli", "Spam korumasına takıldınız. Bot olmadığınızı doğrulayamadık.");
    }

    // Manuel Domain Blokajı
    if(!empty($_POST['email'])) {
        $bad_patterns = ['seoautomation', 'verifiedlinklist', 'goodmail', 'thailandtravel', 'automationpro'];
        $user_email = strtolower(trim($_POST['email']));
        foreach($bad_patterns as $pattern) {
            if(strpos($user_email, $pattern) !== false) {
                sg_log_spam("Kara Liste Domain", "Kullanilan Email: {$user_email}");
                msg("error", "Spam Engeli", "Bu e-posta servisi ile kayıt yasaktır kanka. Hadi başka kapıya!");
            }
        }
    }

    // Orijinal StopForumSpam Kontrolü (HTTPS)
    if($sg_config['sfs_api']) {
        $email = urlencode($_POST['email']);
        $ip = urlencode($user_ip);
        $username = urlencode($_POST['name']);
        
        $api_url = "https://api.stopforumspam.org/api?ip=$ip&email=$email&username=$username&f=json";
        
        if(function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $response = @file_get_contents($api_url);
        }
        
        if($response) {
            $data = json_decode($response, true);
            if(isset($data['success']) && $data['success'] == 1) {
                $ip_appears = isset($data['ip']['appears']) ? $data['ip']['appears'] : false;
                $email_appears = isset($data['email']['appears']) ? $data['email']['appears'] : false;
                $user_appears = isset($data['username']['appears']) ? $data['username']['appears'] : false;

                if($ip_appears || $email_appears || $user_appears) {
                     $ip_freq = isset($data['ip']['frequency']) ? $data['ip']['frequency'] : 0;
                     $email_freq = isset($data['email']['frequency']) ? $data['email']['frequency'] : 0;
                     $user_freq = isset($data['username']['frequency']) ? $data['username']['frequency'] : 0;
                     
                     sg_log_spam("StopForumSpam Match", "IP: {$ip_freq}, Email: {$email_freq}, User: {$user_freq}");
                     msg("error", "Hata", "Spam veritabanında kaydınız bulundu. Kayıt olamazsınız.");
                }
            }
        }
    }

    // Geçici Mail Kontrolü
    if(intval($sg_config['ban_disposable']) == 1) {
        $email = strtolower(trim($_POST['email']));
        $domain = substr(strrchr($email, "@"), 1);
        $banned_domains = explode(",", $sg_config['banned_domains']);
        if(in_array($domain, $banned_domains)) {
             sg_log_spam("Gecici Mail Denemesi", "Engellenen Domain: {$domain}");
             msg("error", "Hata", "Kullandığınız e-posta servisinin bu siteye kayıt olması engellenmiştir.");
        }
    }
}
?>

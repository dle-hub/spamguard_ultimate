<?php
/*
=====================================================
 DLE SpamGuard Ultimate v2.5.0
-----------------------------------------------------
 StopForumSpam Integration
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) || !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( $member_id['user_group'] != 1 ) {
	msg( "error", "Hata", "Bu bölüme erişim yetkiniz yok." );
}

// Config Load
if(file_exists(ENGINE_DIR . '/data/spamguard_config.php')) {
    include_once (DLEPlugins::Check(ENGINE_DIR . '/data/spamguard_config.php'));
} else {
    $sg_config = array(
        'status' => '1', 
        'sfs_api' => '', 
        'block_proxy' => '1', 
        'min_reg_time' => '5', 
        'ban_disposable' => '1', 
        'log_days' => '30',
        'banned_domains' => "tempmail.com,throwawaymail.com,mailinator.com,yopmail.com,10minutemail.com,guerrillamail.com,sharklasers.com,getairmail.com,mail.ru,bk.ru,list.ru,inbox.ru,internet.ru"
    );
}
if(!isset($sg_config['banned_domains'])) $sg_config['banned_domains'] = "tempmail.com,throwawaymail.com,mailinator.com,yopmail.com,10minutemail.com,guerrillamail.com,sharklasers.com,getairmail.com,mail.ru,bk.ru,list.ru,inbox.ru,internet.ru";

// Helper: Hash Check
function sg_check_hash() {
    global $dle_login_hash;
    if( $_REQUEST['user_hash'] == "" OR $_REQUEST['user_hash'] != $dle_login_hash ) {
        die( "Hacking attempt! (Hash Mismatch)" );
    }
}

// Helper: Avatar
function sg_get_avatar($foto) {
    global $config;
    if(empty($foto)) return "engine/skins/images/noavatar.png";
    if ( count(explode("@", $foto)) == 2 ) return 'https://www.gravatar.com/avatar/' . md5(trim($foto)) . '?s=50';
    if ( strpos($foto, "http") === 0 || strpos($foto, "//") === 0 ) return $foto;
    return $config['http_home_url'] . "uploads/fotos/" . $foto;
}

// Main Logic
$active_tab = "dashboard_tab";
if(isset($_REQUEST['tab']) && $_REQUEST['tab']) $active_tab = $_REQUEST['tab'];

// --- ACTIONS ---

// Save Settings
if( isset($_POST['action']) && $_POST['action'] == "save" ) {
    sg_check_hash();
	$save_con = $_POST['save_con'];
	$save_con['status'] = intval($save_con['status']);
	$save_con['block_proxy'] = intval($save_con['block_proxy']);
	$save_con['ban_disposable'] = intval($save_con['ban_disposable']);
	$save_con['min_reg_time'] = intval($save_con['min_reg_time']);
	$save_con['log_days'] = intval($save_con['log_days']);
    $save_con['sfs_api'] = trim(strip_tags(stripslashes($save_con['sfs_api'])));
    $save_con['banned_domains'] = $sg_config['banned_domains'];
	
    $handler = fopen( ENGINE_DIR . '/data/spamguard_config.php', "w" );
    fwrite( $handler, "<?php 

//SpamGuard Configurations

$sg_config = array (
" );
	foreach ( $save_con as $name => $value ) fwrite( $handler, "'{$name}' => \"{$value}\",\n" );
	fwrite( $handler, ");\n\n?>" );
	fclose( $handler );
	clear_cache();
    header("Location: ?mod=spamguard&tab=general_tab&msg=saved"); die();
}

// Save Emails
if( isset($_POST['save_emails']) ) {
    sg_check_hash();
    $domains = $_POST['banned_domains_list'];
    $domains = str_replace(array("\r\n", "\r", "\n"), ",", $domains);
    $domain_array = explode(",", $domains);
    $clean_domains = array();
    foreach($domain_array as $d) {
        $d = trim(strtolower(strip_tags($d)));
        if($d) $clean_domains[] = $d;
    }
    $final_domain_string = implode(",", array_unique($clean_domains));
    $sg_config['banned_domains'] = $final_domain_string;
	
    $handler = fopen( ENGINE_DIR . '/data/spamguard_config.php', "w" );
    fwrite( $handler, "<?php 

//SpamGuard Configurations

$sg_config = array (
" );
	foreach ( $sg_config as $name => $value ) fwrite( $handler, "'{$name}' => \"{$value}\",\n" );
	fwrite( $handler, ");\n\n?>" );
	fclose( $handler );
	clear_cache();
    header("Location: ?mod=spamguard&tab=email_tab&msg=saved"); die();
}

// Block IP
if(isset($_POST['do_block_ip'])) {
    sg_check_hash();
    $ip = $db->safesql(trim($_POST['ip_add']));
    $b_descr = $db->safesql(trim($_POST['descr']));
    
    // Date handling
    $this_time = 0; $days = 0;
    if(trim($_POST['date'])) {
        $this_time = strtotime($_POST['date']);
        $days = 1;
    }

    if($ip) {
        $count = $db->super_query("SELECT count(*) as count FROM " . USERPREFIX . "_banned WHERE ip ='$ip'");
        if(!$count['count']) {
            $db->query("INSERT INTO " . USERPREFIX . "_banned (descr, date, days, ip) VALUES ('$b_descr', '$this_time', '$days', '$ip')");
            @unlink(ENGINE_DIR . '/cache/system/banned.php');
            header("Location: ?mod=spamguard&tab=banned_tab&msg=blocked"); die();
        } else {
             header("Location: ?mod=spamguard&tab=banned_tab&msg=exists"); die();
        }
    }
}
if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'delete_block') {
    if(isset($_REQUEST['user_hash'])) sg_check_hash(); 
    $id = intval($_REQUEST['id']);
    $db->query("DELETE FROM " . USERPREFIX . "_banned WHERE id='$id'");
    @unlink(ENGINE_DIR . '/cache/system/banned.php');
    header("Location: ?mod=spamguard&tab=banned_tab&msg=deleted"); die();
}

// Ban User Function
function banUserFull($uid) {
    global $db, $_TIME;
    $uid = intval($uid);
    $user = $db->super_query("SELECT user_id, name, logged_ip FROM " . USERPREFIX . "_users WHERE user_id='$uid'");
    
    if($user['user_id']) {
        $db->query("UPDATE " . USERPREFIX . "_users SET user_group='5', banned='yes' WHERE user_id='$uid'");
        $db->query("DELETE FROM " . USERPREFIX . "_banned WHERE users_id='$uid'");
        $db->query("INSERT INTO " . USERPREFIX . "_banned (descr, date, days, ip, users_id) VALUES ('Yasaklı Üye: {$user['name']}', '0', '0', '', '$uid')");
        if($user['logged_ip']) {
            $ip = $user['logged_ip'];
            $check = $db->super_query("SELECT id FROM " . USERPREFIX . "_banned WHERE ip='$ip'");
            if(!$check['id']) {
                $db->query("INSERT INTO " . USERPREFIX . "_banned (descr, date, days, ip) VALUES ('Yasaklı Üye IP: {$user['name']}', '$_TIME', '0', '$ip')");
            }
        }
    }
}

// Single User Actions
if(isset($_GET['action'])) {
    if(in_array($_GET['action'], ['ban_user','delete_user','unban_user'])) {
        sg_check_hash();
        $uid = intval($_GET['id']);
        if($uid) {
            if($_GET['action']=='ban_user') banUserFull($uid);
            elseif($_GET['action']=='delete_user') {
                $db->query("DELETE FROM " . USERPREFIX . "_users WHERE user_id='$uid'");
                $db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE userid='$uid'");
                $db->query("DELETE FROM " . USERPREFIX . "_banned WHERE users_id='$uid'");
                $db->query("DELETE FROM " . USERPREFIX . "_pm WHERE user='$uid' OR user_from='$uid'");
            }
            elseif($_GET['action']=='unban_user') {
                $db->query("UPDATE " . USERPREFIX . "_users SET user_group='4', banned='' WHERE user_id='$uid'");
                $db->query("DELETE FROM " . USERPREFIX . "_banned WHERE users_id='$uid'");
            }
            header("Location: ?mod=spamguard&tab=users_tab&msg=mass_success"); die();
        }
    }
}

// MASS ACTIONS
if(isset($_POST['mass_action']) && isset($_POST['selected_users'])) {
    sg_check_hash();
    $action_type = $_POST['action_type'];
    $selected_users = $_POST['selected_users'];
    
    if(is_array($selected_users) && count($selected_users) > 0) {
        foreach($selected_users as $uid) {
            $uid = intval($uid);
            if($action_type == 'ban_user') banUserFull($uid);
            elseif($action_type == 'block_ip_user') {
                 $u = $db->super_query("SELECT logged_ip, name FROM " . USERPREFIX . "_users WHERE user_id='$uid'");
                 if($u['logged_ip']) {
                     $db->query("INSERT INTO " . USERPREFIX . "_banned (descr, date, days, ip) VALUES ('Yasaklı Üye IP: {$u['name']}', '$_TIME', '0', '{$u['logged_ip']}')");
                 }
            }
            elseif($action_type == 'delete_user') {
                 $db->query("DELETE FROM " . USERPREFIX . "_users WHERE user_id='$uid'");
                 $db->query("DELETE FROM " . USERPREFIX . "_banned WHERE users_id='$uid'");
            }
        }
        @unlink(ENGINE_DIR . '/cache/system/banned.php');
        header("Location: ?mod=spamguard&tab=users_tab&msg=mass_success"); die();
    }
}

// GUI Headers
echoheader( "<i class=\"fa fa-shield position-left\"></i><span class=\"text-semibold\">SpamGuard Ultimate</span>", "Gelişmiş Spam Koruması v2.5");

echo <<<HTML
<style>
/* Scoped Styles */
#sg_container .user-stat-icon { font-size: 14px; margin-right: 5px; color: #666; }
#sg_container .user-list-item { padding: 10px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; }
#sg_container .user-avatar { width: 40px; height: 40px; border-radius: 50%; margin-right: 15px; overflow: hidden; }
#sg_container .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
#sg_container .status-indicator { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
#sg_container .status-online { background-color: #4CAF50; }
#sg_container .status-offline { background-color: #ccc; }
#sg_container .rule-list li { margin-bottom: 5px; }
</style>
<script>
    function ChangeOption(obj, selectedOption) {
        $("#sg-navbar-filter li").removeClass('active');
        $(obj).parent().addClass('active');
        $('.tab-content-panel').hide();
        $('#' + selectedOption).show();
        return false;
    }
    function blockIPDirect(ip) {
        if(confirm(ip + " adresini kalıcı olarak engellemek istiyor musunuz?")) {
             $('#quick_block_ip').val(ip); $('#quick_block_form').submit();
        }
    }
    $(function() {
        var params = new URLSearchParams(window.location.search);
        if(params.has('msg')) {
            var msg = params.get('msg');
            setTimeout(function() {
                if(msg == 'saved') DLEPush.success('Ayarlar başarıyla kaydedildi!', 'Başarılı');
                else if(msg == 'blocked') DLEPush.success('IP adresi başarıyla engellendi!', 'Engellendi');
                else if(msg == 'exists') DLEPush.warning('Bu IP adresi zaten engelli listesinde!', 'Uyarı');
                else if(msg == 'deleted') DLEPush.info('Kayıt başarıyla silindi.', 'Bilgi');
                else if(msg == 'mass_success') DLEPush.success('İşlem başarıyla tamamlandı!', 'Bitti');
                var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + window.location.search.replace(/&msg=[^&]*/, "");
                window.history.pushState({path:newUrl},'',newUrl);
            }, 500);
        }
        $('[data-toggle="tooltip"]').tooltip();
        $('.date-picker').datetimepicker({format:'Y-m-d H:i'});
    });
</script>
<form id="quick_block_form" method="post" action="">
    <input type="hidden" name="tab" value="users_tab">
    <input type="hidden" name="do_block_ip" value="1">
    <input type="hidden" name="descr" value="Hızlı Engel (SpamGuard)">
    <input type="hidden" name="ip_add" id="quick_block_ip" value="">
    <input type="hidden" name="user_hash" value="{$dle_login_hash}">
</form>
HTML;

// Navbar
$nav = ['dashboard_tab'=>'','general_tab'=>'','email_tab'=>'','banned_tab'=>'','users_tab'=>'','logs_tab'=>''];
$nav[$active_tab] = 'active';

echo <<<HTML
<div id="sg_container">
<div class="navbar navbar-default navbar-component navbar-xs systemsettings">
	<ul class="nav navbar-nav visible-xs-block">
		<li class="full-width text-center"><a data-toggle="collapse" data-target="#sg-navbar-filter"><i class="fa fa-bars"></i></a></li>
	</ul>
	<div class="navbar-collapse collapse" id="sg-navbar-filter">
		<ul class="nav navbar-nav">
            <li class="{$nav['dashboard_tab']}"><a onclick="ChangeOption(this, 'dashboard_tab');"><i class="fa fa-tachometer"></i> Dashboard</a></li>
			<li class="{$nav['general_tab']}"><a onclick="ChangeOption(this, 'general_tab');"><i class="fa fa-cogs"></i> Genel Ayarlar</a></li>
			<li class="{$nav['email_tab']}"><a onclick="ChangeOption(this, 'email_tab');"><i class="fa fa-envelope"></i> Mail Servisleri</a></li>
            <li class="{$nav['banned_tab']}"><a onclick="ChangeOption(this,'banned_tab');"><i class="fa fa-ban"></i> Yasaklı Liste</a></li>
            <li class="{$nav['users_tab']}"><a onclick="ChangeOption(this,'users_tab');"><i class="fa fa-users"></i> Kullanıcı Analizi</a></li>
			<li class="{$nav['logs_tab']}"><a onclick="ChangeOption(this, 'logs_tab');"><i class="fa fa-list-alt"></i> Loglar</a></li>
		</ul>
	</div>
</div>
HTML;

// 1. DASHBOARD
$style_dashboard = ($active_tab == 'dashboard_tab') ? '' : 'display:none;';
$stat_users = $db->super_query("SELECT count(*) as count FROM " . USERPREFIX . "_users");
$stat_banned = $db->super_query("SELECT count(*) as count FROM " . USERPREFIX . "_banned");
$stat_spam = $db->super_query("SELECT count(*) as count FROM " . PREFIX . "_spam_logs");
$stat_banned_users = $db->super_query("SELECT count(*) as count FROM " . USERPREFIX . "_users WHERE user_group='5' OR banned='yes'");

echo <<<HTML
<div id="dashboard_tab" class="dashboard-panel tab-content-panel" style="{$style_dashboard}">
    <div class="row">
        <div class="col-md-3"><div class="panel bg-teal-400"><div class="panel-body"><div class="heading-elements"><i class="fa fa-users fa-3x opacity-20"></i></div><h3 class="no-margin">{$stat_users['count']}</h3><p class="text-muted text-size-small">Toplam Üye</p></div></div></div>
        <div class="col-md-3"><div class="panel bg-danger-400"><div class="panel-body"><div class="heading-elements"><i class="fa fa-ban fa-3x opacity-20"></i></div><h3 class="no-margin">{$stat_banned['count']}</h3><p class="text-muted text-size-small">Yasaklı IP Sayısı</p></div></div></div>
        <div class="col-md-3"><div class="panel bg-orange-400"><div class="panel-body"><div class="heading-elements"><i class="fa fa-shield fa-3x opacity-20"></i></div><h3 class="no-margin">{$stat_spam['count']}</h3><p class="text-muted text-size-small">Engellenen Spam</p></div></div></div>
        <div class="col-md-3"><div class="panel bg-slate-400"><div class="panel-body"><div class="heading-elements"><i class="fa fa-user-times fa-3x opacity-20"></i></div><h3 class="no-margin">{$stat_banned_users['count']}</h3><p class="text-muted text-size-small">Yasaklı Üyeler</p></div></div></div>
    </div>
    <div class="row">
        <div class="col-md-4">
             <div class="panel panel-info">
                <div class="panel-heading"><h6 class="panel-title"><i class="fa fa-info-circle position-left"></i> Risk Puanlama Sistemi</h6></div>
                <div class="panel-body">
                    <ul class="rule-list">
                        <li><span class="label label-danger">+50 Puan</span> <strong>Hemen Çıkma:</strong> Kayıt tarihi ile son giriş tarihi aynı (Hiç giriş yapmamış gibi).</li>
                        <li><span class="label label-warning">+30 Puan</span> <strong>Hayalet:</strong> Kayıt olduktan sonra 15 dakika içinde çıkmış.</li>
                        <li><span class="label label-default">+20 Puan</span> <strong>Pasif:</strong> Hiçbir haber veya yorum eklememiş.</li>
                        <li><hr style="margin: 10px 0;">
                        <li><span class="label label-danger">Kritik</span> <strong>80+ Puan:</strong> Potansiyel Spam Bot</li>
                        <li><span class="label label-warning">Yüksek</span> <strong>50+ Puan:</strong> Şüpheli Hesap</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel panel-flat">
                <div class="panel-heading"><h6 class="panel-title"><i class="fa fa-user-plus position-left"></i> Son Kayıt Olanlar</h6></div>
                <div class="panel-body" style="padding:0;">
HTML;
    $db->query("SELECT user_id, name, email, reg_date, foto FROM " . USERPREFIX . "_users ORDER BY reg_date DESC LIMIT 5");
    while($row = $db->get_row()){
        $avatar = sg_get_avatar($row['foto']);
        echo '<div class="user-list-item"><div class="user-avatar"><img src="'.$avatar.'"></div><div class="user-details" style="flex-grow:1;"><h6><a href="?mod=editusers&action=edituser&id='.$row['user_id'].'" target="_blank">'.$row['name'].'</a></h6><span>'.$row['email'].'</span></div><div class="text-right"><span class="label label-default">'.date("d.m.Y", $row['reg_date']).'</span></div></div>';
    }
echo <<<HTML
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel panel-flat">
                <div class="panel-heading"><h6 class="panel-title"><i class="fa fa-circle text-success position-left"></i> Çevrimiçi Üyeler</h6></div>
                <div class="panel-body" style="padding:0;">
HTML;
    $online_cutoff = $_TIME - 1200;
    $db->query("SELECT user_id, name, lastdate, foto, logged_ip FROM " . USERPREFIX . "_users WHERE lastdate > '$online_cutoff' ORDER BY lastdate DESC LIMIT 5");
    if($db->num_rows()==0) echo "<div style='padding:20px; text-align:center; color:#999;'>Şu an çevrimiçi üye yok.</div>";
    while($row = $db->get_row()){
        $avatar = sg_get_avatar($row['foto']);
         echo '<div class="user-list-item"><div class="user-avatar"><img src="'.$avatar.'"></div><div class="user-details" style="flex-grow:1;"><h6><a href="?mod=editusers&action=edituser&id='.$row['user_id'].'" target="_blank">'.$row['name'].'</a></h6><span>IP: '.$row['logged_ip'].'</span></div><div class="text-right"><span class="status-indicator status-online"></span></div></div>';
    }
echo <<<HTML
                </div>
            </div>
        </div>
    </div>
</div>
HTML;

// 2. SETTINGS
$style_general = ($active_tab == 'general_tab') ? '' : 'display:none;';
echo "<form action='' method='post'><input type='hidden' name='action' value='save'><input type='hidden' name='user_hash' value='{$dle_login_hash}'><div id='general_tab' class='panel panel-flat tab-content-panel' style='{$style_general}'><div class='panel-body border-bottom'><h6 class='text-semibold no-margin'>Genel Ayarlar</h6></div><table class='table table-striped'>";
echo '<tr><td class="col-xs-6 col-sm-6 col-md-7"><h6 class="media-heading text-semibold">Modül Durumu</h6><div class="text-muted text-size-small hidden-xs">SpamGuard aktif/pasif</div></td><td class="col-xs-6 col-sm-6 col-md-5"><input class="switch" type="checkbox" name="save_con[status]" value="1" '.("$sg_config[status]"?"checked":"").'></td></tr>';
echo '<tr><td class="col-xs-6 col-sm-6 col-md-7"><h6 class="media-heading text-semibold">SFS API Key</h6><div class="text-muted text-size-small hidden-xs">StopForumSpam API anahtarınız</div></td><td class="col-xs-6 col-sm-6 col-md-5"><input type="text" class="form-control" name="save_con[sfs_api]" value="'.$sg_config['sfs_api'].'"></td></tr>';
echo '<tr><td class="col-xs-6 col-sm-6 col-md-7"><h6 class="media-heading text-semibold">Proxy/VPN Engelle</h6><div class="text-muted text-size-small hidden-xs">Bilinen proxy IPlerini durdur</div></td><td class="col-xs-6 col-sm-6 col-md-5"><input class="switch" type="checkbox" name="save_con[block_proxy]" value="1" '.("$sg_config[block_proxy]"?"checked":"").'></td></tr>';
echo '<tr><td class="col-xs-6 col-sm-6 col-md-7"><h6 class="media-heading text-semibold">Min. Kayıt Süresi</h6><div class="text-muted text-size-small hidden-xs">Form doldurma süresi (sn)</div></td><td class="col-xs-6 col-sm-6 col-md-5"><input type="number" class="form-control" style="width:100px" name="save_con[min_reg_time]" value="'.$sg_config['min_reg_time'].'"></td></tr>';
echo '<tr><td class="col-xs-6 col-sm-6 col-md-7"><h6 class="media-heading text-semibold">Log Saklama (Gün)</h6><div class="text-muted text-size-small hidden-xs">Otomatik log temizleme</div></td><td class="col-xs-6 col-sm-6 col-md-5"><input type="number" class="form-control" style="width:100px" name="save_con[log_days]" value="'.$sg_config['log_days'].'"></td></tr>';
echo '<tr><td class="col-xs-6 col-sm-6 col-md-7"><h6 class="media-heading text-semibold">Geçici E-Posta Engeli</h6><div class="text-muted text-size-small hidden-xs">Disposable mailleri engelle</div></td><td class="col-xs-6 col-sm-6 col-md-5"><input class="switch" type="checkbox" name="save_con[ban_disposable]" value="1" '.("$sg_config[ban_disposable]"?"checked":"").'></td></tr>';
echo "</table><div class='panel-footer'><button type='submit' class='btn bg-teal btn-sm btn-raised'><i class='fa fa-floppy-o position-left'></i> Ayarları Kaydet</button></div></div></form>";

$style_email = ($active_tab == 'email_tab') ? '' : 'display:none;';
echo "<form action='' method='post'><input type='hidden' name='save_emails' value='1'><input type='hidden' name='user_hash' value='{$dle_login_hash}'><div id='email_tab' class='panel panel-flat tab-content-panel' style='{$style_email}'><div class='panel-body border-bottom'><h6 class='text-semibold no-margin'>Yasaklı Mail Servisleri</h6></div><div class='panel-body'><textarea name='banned_domains_list' class='form-control' rows='15' style='font-family:monospace;'>".str_replace(",", "\n", $sg_config['banned_domains'] )."</textarea></div><div class='panel-footer'><button type='submit' class='btn bg-primary btn-sm btn-raised'><i class='fa fa-floppy-o position-left'></i> Listeyi Güncelle</button></div></div></form>";

// 3. BANNEDTAB
$style_banned = ($active_tab == 'banned_tab') ? '' : 'display:none;';
$start_from_ban = isset($_REQUEST['start_from']) && $active_tab == 'banned_tab' ? intval($_REQUEST['start_from']) : 0;
$news_per_page = 50; 
$count_ban = $db->super_query("SELECT COUNT(*) as count FROM " . USERPREFIX . "_banned");
$count_all_ban = $count_ban['count'];
// Pagination
$i = $start_from_ban + $news_per_page;
$npp_nav_ban = "";
if ($start_from_ban > 0) { $pre = $start_from_ban - $news_per_page; $npp_nav_ban .= "<li><a href=\"?mod=spamguard&tab=banned_tab&start_from={$pre}\"><<</a></li>"; }
if ($count_all_ban > $i) { $npp_nav_ban .= "<li><a href=\"?mod=spamguard&tab=banned_tab&start_from={$i}\">>></a></li>"; }

echo "<div id='banned_tab' class='panel panel-flat tab-content-panel' style='{$style_banned}'>";
echo "<div class='panel-heading'><h5 class='panel-title'>Yasaklı IP Listesi</h5><div class='heading-elements'><ul class='icons-list'><li><a href='#' onclick="$('#newblock').modal(); return false;"><i class='fa fa-plus-circle'></i> Ekle</a></li></ul></div></div>";
echo "<div class='table-responsive'><table class='table table-striped table-xs table-hover'><thead><tr><th style='width: 250px'>IP</th><th>Kullanıcı (Varsa)</th><th style='width: 200px'>Tarih</th><th>Sebep</th><th style='width: 70px'>&nbsp;</th></tr></thead><tbody>";

$db->query( "SELECT b.*, u.name as username FROM " . USERPREFIX . "_banned b LEFT JOIN " . USERPREFIX . "_users u ON b.users_id = u.user_id ORDER BY b.id DESC LIMIT {$start_from_ban},{$news_per_page}" );
while($row = $db->get_row()) {
     $date_str = ($row['date']) ? date("d.m.Y H:i", $row['date']) : "Süresiz";
     $show_name = "-";
     if($row['username']) $show_name = "<span class='text-semibold text-primary'>{$row['username']}</span>";
     elseif($row['users_id']) $show_name = "<span class='text-muted'>Silinmiş Üye (ID: {$row['users_id']})</span>";
     
     $menu_link = <<<HTML
<div class="btn-group">
  <a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
  <ul class="dropdown-menu text-left dropdown-menu-right">
    <li><a href="?mod=spamguard&tab=banned_tab&action=delete_block&id={$row['id']}&user_hash={$dle_login_hash}" onclick="return confirm('Silmek istediğinize emin misiniz?');"><i class="fa fa-trash-o position-left text-danger"></i>Sil</a></li>
  </ul>
</div>
HTML;
     echo "<tr><td>{$row['ip']}</td><td>{$show_name}</td><td>{$date_str}</td><td>{$row['descr']}</td><td>{$menu_link}</td></tr>";
}
echo "</tbody></table></div>";
echo "</div>";
echo '<div class="modal fade" id="newblock"><div class="modal-dialog"><div class="modal-content"><form method="post" action=""><input type="hidden" name="mod" value="spamguard"><input type="hidden" name="do_block_ip" value="1"><input type="hidden" name="user_hash" value="'.$dle_login_hash.'"><div class="modal-header bg-teal"><button type="button" class="close" data-dismiss="modal">&times;</button><h6 class="modal-title">IP Engelle</h6></div><div class="modal-body"><div class="form-group"><label>IP Adresi</label><input type="text" name="ip_add" class="form-control" required></div><div class="form-group"><label>Bitiş Tarihi</label><div class="row"><div class="col-md-6"><input type="text" name="date" class="form-control date-picker" autocomplete="off" placeholder="Boş bırakılırsa süresiz"></div></div></div><div class="form-group"><label>Sebep</label><textarea name="descr" class="form-control"></textarea></div></div><div class="modal-footer"><button type="submit" class="btn btn-sm bg-teal btn-raised">Ekle</button></div></form></div></div></div>';

// 4. USERS TAB
$start_from = isset($_REQUEST['start_from']) && $active_tab == 'users_tab' ? intval( $_REQUEST['start_from'] ) : 0;
$filter_type = $_REQUEST['filter_user_time'] ?? 'all';
$limit = 25; 
$where_clause = "user_id > 0";

if($filter_type == 'ghosts') $where_clause .= " AND (lastdate = reg_date OR lastdate = 0)";
elseif($filter_type == '30days') $where_clause .= " AND lastdate < " . ($_TIME - (30 * 86400));
elseif($filter_type == 'highrisk') { 
     $ghost_t = $_TIME - (30 * 86400);
     $where_clause .= " AND ((lastdate=reg_date) OR (lastdate<{$ghost_t} AND news_num=0 AND comm_num=0))";
}

$count_q = $db->super_query("SELECT COUNT(*) as count FROM " . USERPREFIX . "_users WHERE $where_clause");
$total_users = $count_q['count'];

$style_users = ($active_tab == 'users_tab') ? '' : 'display:none;';
echo "<div id='users_tab' class='panel panel-flat tab-content-panel' style='{$style_users}'><form action='' method='post' name='usersform' id='usersform'><input type='hidden' name='mod' value='spamguard'><input type='hidden' name='tab' value='users_tab'><input type='hidden' name='start_from' value='{$start_from}'><input type='hidden' name='user_hash' value='{$dle_login_hash}'>";
echo "<div class='panel-body border-bottom'><div class='row'><div class='col-md-3'><select name='filter_user_time' onchange='document.usersform.start_from.value=0; document.usersform.submit();' class='form-control'><option value='all' ".("$filter_type"=='all'?'selected':'').">Tümü</option><option value='ghosts' ".("$filter_type"=='ghosts'?'selected':'').">Ghost Users</option><option value='30days' ".("$filter_type"=='30days'?'selected':'').">30 Gün Pasif</option><option value='highrisk' ".("$filter_type"=='highrisk'?'selected':'').">Yüksek Riskli</option></select></div><div class='col-md-9 text-right'><button type='submit' name='mass_action' value='1' onclick="$('#action_type_input').val('block_ip_user');" class='btn btn-warning btn-sm'>IP Engelle</button> <button type='submit' name='mass_action' value='1' onclick="$('#action_type_input').val('ban_user');" class='btn btn-danger btn-sm'>Banla</button> <button type='submit' name='mass_action' value='1' onclick="if(confirm('Sil?')) { $('#action_type_input').val('delete_user'); return true; } else return false;" class='btn btn-default btn-sm'>Sil</button><input type='hidden' name='action_type' id='action_type_input' value=''></div></div></div>";
echo "<div class='table-responsive'><table class='table table-xs table-striped table-hover'><thead><tr><th style='width:1px;'><input type='checkbox' onclick="$('input[name*=\'selected_users\']').prop('checked', this.checked);"></th><th style='width: 40px'>&nbsp;</th><th>Kullanıcı Detayları</th><th>Aktivite</th><th>Risk Analizi</th><th style='width: 40px'>İşlem</th></tr></thead><tbody>";

$db->query("SELECT user_id, name, email, reg_date, lastdate, logged_ip, news_num, comm_num, banned, user_group, fullname, land, info, foto FROM " . USERPREFIX . "_users WHERE $where_clause ORDER BY reg_date DESC LIMIT {$start_from},{$limit}");
while($user = $db->get_row()) {
    $risk = 0;
    if($user['reg_date'] == $user['lastdate']) $risk += 50;
    if(($user['lastdate'] - $user['reg_date']) < 900) $risk += 30;
    if($user['news_num'] == 0 && $user['comm_num'] == 0) $risk += 20;
    
    $risk_html = "<span class='label label-success' data-toggle='tooltip' title='Risk Puanı: $risk'>Güvenli</span>";
    if($risk >= 80) $risk_html = "<span class='label label-danger' data-toggle='tooltip' title='Risk Puanı: $risk'>Kritik</span>";
    elseif($risk >= 50) $risk_html = "<span class='label label-warning' data-toggle='tooltip' title='Risk Puanı: $risk'>Yüksek</span>";
    
    $status_html = ($user['banned']=='yes' || $user['user_group'] == 5) ? "<span class='label label-danger' style='margin-left:5px'>BANLI</span>" : "<span class='label label-success' style='margin-left:5px'>Aktif</span>";
    
    $reg_date = date("d.m.Y", $user['reg_date']);
    $last_date = ($user['lastdate'] == $user['reg_date']) ? "<span class='text-danger'>Hiç girmedi</span>" : date("d.m.Y", $user['lastdate']);
    if($user['lastdate'] == 0) $last_date = "<span class='text-danger'>Hiç girmedi</span>";
    
    $avatar = sg_get_avatar($user['foto']);
    
    $menu_link = <<<HTML
<div class="btn-group">
    <a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
    <ul class="dropdown-menu text-left dropdown-menu-right">
        <li><a href="?mod=editusers&action=edituser&id={$user['user_id']}" target="_blank"><i class="fa fa-pencil-square-o position-left"></i> Düzenle</a></li>
        <li class="divider"></li>
        <li><a href="?mod=spamguard&tab=users_tab&action=ban_user&id={$user['user_id']}&user_hash={$dle_login_hash}" onclick="return confirm('Banl?');"><i class="fa fa-ban position-left text-warning"></i> Banla</a></li>
        <li><a href="?mod=spamguard&tab=users_tab&action=delete_user&id={$user['user_id']}&user_hash={$dle_login_hash}" onclick="return confirm('Sil?');"><i class="fa fa-trash-o position-left text-danger"></i> Sil</a></li>
    </ul>
</div>
HTML;

    echo "<tr>
    <td><input type='checkbox' name='selected_users[]' value='{$user['user_id']}'></td>
    <td><img src='$avatar' style='width:32px; height:32px; border-radius:50%;'></td>
    <td><b>{$user['name']}</b><div class='text-muted text-size-small'>{$user['email']}</div><div class='text-muted text-size-small'>IP: {$user['logged_ip']}</div></td>
    <td><i class='fa fa-pencil text-muted tip' title='Haber'></i> {$user['news_num']} <i class='fa fa-comments text-muted tip' title='Yorum' style='margin-left:5px'></i> {$user['comm_num']}<div class='text-muted text-size-small'>Kayıt: {$reg_date}</div><div class='text-muted text-size-small'>Son: {$last_date}</div></td>
    <td>{$risk_html}{$status_html}</td>
    <td>{$menu_link}</td></tr>";
}
echo "</tbody></table></div>";

// Smart Pagination
$total_pages = ceil($total_users / $limit);
$current_page = floor($start_from / $limit) + 1;
if($total_pages > 1) {
    echo "<div class='panel-footer'><ul class='pagination pagination-sm'>";
    if($current_page > 1) echo "<li><a href='#' onclick='document.usersform.start_from.value=".("$start_from" - $limit)."; document.usersform.submit(); return false;'>&laquo;</a></li>";
    
    for($i=1; $i<=$total_pages; $i++) {
        if($i==1 || $i==$total_pages || ($i >= $current_page-2 && $i <= $current_page+2)) { 
             $st = ($i-1)*$limit;
             $cls = ($i==$current_page) ? 'active' : '';
             echo "<li class='$cls'><a href='#' onclick='document.usersform.start_from.value=$st; document.usersform.submit(); return false;'>$i</a></li>";
        } elseif($i==$current_page-3 || $i==$current_page+3) {
             echo "<li class='disabled'><span>...</span></li>";
        }
    }
    
    if($current_page < $total_pages) echo "<li><a href='#' onclick='document.usersform.start_from.value=".("$start_from" + $limit)."; document.usersform.submit(); return false;'>&raquo;</a></li>";
    echo "</ul></div>";
}

echo "</form></div>";

// 5. LOGS TAB
$style_logs = ($active_tab == 'logs_tab') ? '' : 'display:none;';
echo "<div id='logs_tab' class='panel panel-flat tab-content-panel' style='{$style_logs}'><div class='panel-body'><h6 class='no-margin'>Loglar</h6></div><table class='table'><thead><tr><th>Tarih</th><th>IP</th><th>Sebep</th></tr></thead><tbody>";
$db->query("SELECT * FROM " . PREFIX . "_spam_logs ORDER BY id DESC LIMIT 50");
while($log = $db->get_row()) { echo "<tr><td>".date("d.m.Y H:i",$log['date'])."</td><td>{$log['ip']}</td><td>{$log['reason']}</td></tr>"; }
echo "</tbody></table></div>";

echo "</div>"; // End sg_container
echofooter();
?>

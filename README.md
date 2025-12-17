# DLE SpamGuard Ultimate

DataLife Engine için geliştirilmiş %100 Native Anti-Spam ve Güvenlik Eklentisi. Gelişmiş Filtreleme, Risk Analizi ve IP Yönetimi.

Bu depo, SpamGuard Ultimate DLE eklentisinin kaynak kodlarını ve kurulum dosyalarını içerir.

## Kurulum

Eklentiyi kurmanın en kolay ve tavsiye edilen yolu, DLE admin panelindeki **Eklenti Yönetimi** bölümünden `spamguard_ultimate.xml` dosyasını yüklemektir.

Detaylı kurulum adımları için [KURULUM.md](KURULUM.md) dosyasına bakınız.

## Kod Yapısı ve Açıklamalar

Eklentinin kullandığı veritabanı şeması, PHP kodları ve bu kodların ne işe yaradığı hakkındaki detaylı teknik açıklamalar için [aciklama.md](aciklama.md) dosyasına başvurabilirsiniz.

Bu depoda ayrıca, XML dosyasında bulunan PHP kodlarının kolayca incelenebilmesi için ayrı dosyalara çıkarılmış halleri de bulunmaktadır:
-   `engine/inc/spamguard.php` - Eklentinin yönetici paneli arayüzü.
-   `engine/modules/spamguard_check.php` - Kullanıcı kayıtları sırasında çalışan spam kontrol mantığı.

## Eklenti Değişikliği

Bu eklenti, çalışabilmesi için `engine/modules/register.php` dosyasına küçük bir kod eklemesi yapar. Bu değişiklik, XML eklentisi yüklendiğinde otomatik olarak uygulanır. Detaylar `aciklama.md` dosyasında mevcuttur.

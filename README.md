# Wp Piso Yedek

Wp Piso Yedek, WordPress sitenizin dosya ve veritabanı yedeklerini güvenli ve yüksek performansla alıp yönetmenizi sağlayan Türkçe yönetim arayüzüne sahip bir eklentidir. Nonce koruması, özel capability kontrolü ve gizli yedek diziniyle güvenliğinizi ön planda tutar.

## Özellikler
- Tam, yalnızca veritabanı veya yalnızca dosya yedekleri oluşturma
- Maksimum yedek sayısı sınırı ve otomatik eski yedek temizleme
- Nonce ve `manage_piso_yedek` capability kontrolü ile yönetici yetkilendirmesi
- Yedekleri `wp-content/uploads/wp-piso-yedek/` dizininde güvenli şekilde saklama
- Veritabanı yedeklerini WPDB ile parçalı (limitli) döngülerle oluşturma
- Dosya arşivlerken akış tabanlı ZipArchive kullanımı ve cache klasörleri hariç tutma
- Zaman damgalı, nonce korumalı indirme bağlantıları
- Basit log kaydı ve (veritabanı) geri yükleme desteği

## Kurulum
1. Eklenti dosyalarını zip haline getirin veya GitHub üzerinden indirin.
2. WordPress yönetici panelinde **Eklentiler > Yeni Ekle > Eklenti Yükle** adımlarını izleyip zip dosyasını yükleyin.
3. Eklentiyi etkinleştirin. Yönetici rolü otomatik olarak `manage_piso_yedek` yeteneğine sahip olacaktır.

## Kullanım
- **Wp Piso Yedek > Yedekler:** Alınmış yedeklerin listesini görüntüleyin, indirin, silin veya veritabanı geri yüklemesi yapın.
- **Wp Piso Yedek > Yeni Yedek:** Tam, yalnızca veritabanı veya yalnızca dosya yedeklerinden birini seçip hemen başlatın.
- **Wp Piso Yedek > Ayarlar:** Maksimum saklanacak yedek sayısını ve isteğe bağlı zamanlama bilgisini belirleyin; yedek dizinini görüntüleyin.

## Güvenlik Notları
- Yedekler hassas bilgiler içerir; kimseyle paylaşmayın ve yalnızca güvenilir ortamlarda indirin.
- İndirme bağlantıları nonce ve zaman damgası ile korunur, yalnızca yönetici yetkisine sahip kullanıcılar erişebilir.
- Yedek dizininde .htaccess ve index.php dosyaları oluşturularak doğrudan erişim sınırlandırılır (Apache).

## Lisans
GPL v2 veya daha üstü.

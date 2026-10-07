<?php
declare(strict_types=1);

/** Denetim kaydı eylemleri ve ekrandaki adları. Yeni eylem = buraya bir satır. */
const AUDIT_ACTIONS = [
    'login'              => 'Giriş',
    'login_failed'       => 'Hatalı giriş',
    'login_locked'       => 'Giriş kilidi',
    'logout'             => 'Çıkış',
    'login_auto'         => 'Giriş (kayıtlı cihaz)',
    'remember_reuse'     => 'Şüpheli cihaz çerezi',
    'sessions_revoked'   => 'Tüm cihazlardan çıkarıldı',
    'user_create'        => 'Kullanıcı açıldı',
    'user_update'        => 'Kullanıcı düzenlendi',
    'user_password'      => 'Şifre değişti',
    'user_activate'      => 'Kullanıcı aktif',
    'user_deactivate'    => 'Kullanıcı pasif',
    'import'             => 'Liste yüklendi',
    'import_phones'      => 'Telefonlar güncellendi',
    'person_create'      => 'Kişi eklendi',
    'export'             => 'Excel indirildi',
    'event_create'       => 'Liste açıldı',
    'event_activate'     => 'Aktif liste değişti',
    'event_rename'       => 'Liste adı değişti',
    'supporters_import'  => 'Bizimkiler yüklendi',
    'supporter_link'     => 'Bizimkiler eşleşme',
    'supporter_unlink'   => 'Bizimkiler eşleşme kaldırıldı',
    'supporters_clear'   => 'Bizimkiler silindi',
    'vote_reset'         => 'Oy sayacı sıfırlandı',
    'gifts_import'       => 'Hediye listesi yüklendi',
    'gifts_clear'        => 'Hediye listesi silindi',
    'gift_add'           => 'Hediye kişi eklendi',
];

/**
 * Denetim kaydı yazar. ŞİFRE ya da şifre hash'i $detail'e KONMAZ.
 * Kayıt yazılamazsa asıl işlem bozulmasın: hata loglanır, istisna yutulur.
 */
function audit(string $action, ?string $target = null, array $detail = [], ?int $userId = null): void
{
    try {
        $userId ??= current_user()['id'] ?? null;
        q(
            'INSERT INTO audit_log (user_id, action, target, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $userId,
                $action,
                $target !== null ? mb_substr($target, 0, 190) : null,
                $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                client_ip_bin(),
            ]
        );
    } catch (Throwable $e) {
        error_log('audit yazılamadı: ' . $e->getMessage());
    }
}

<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect(home_path());
}

$error = null;
$username = '';
$remember = true; // telefonda tarayıcı kapanınca tekrar şifre sormasın (ortak cihazda işareti kaldırın)
if (is_post()) {
    csrf_verify();
    $username = (string) ($_POST['username'] ?? '');
    $remember = ($_POST['remember'] ?? '') === '1';
    $result = attempt_login($username, (string) ($_POST['password'] ?? ''), $remember);
    if ($result === 'ok') {
        redirect(home_path());
    }
    $error = $result === 'locked'
        ? 'Çok fazla hatalı deneme. 15 dakika sonra tekrar deneyin.'
        : 'Kullanıcı adı veya şifre hatalı.';
}

render_header('Giriş');
?>
<main class="login-wrap">
  <form method="post" class="card login-card" autocomplete="on" novalidate>
    <?= csrf_field() ?>
    <h1 class="login-title"><?= e(config('app_name', 'Katılım Takip')) ?></h1>
    <p class="muted">Size verilen kullanıcı adı ve şifre ile giriş yapın.</p>

    <?php if ($error): ?>
      <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <label class="field">
      <span>Kullanıcı adı</span>
      <input class="input" type="text" name="username" value="<?= e($username) ?>" required
             autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="username" autofocus>
    </label>
    <label class="field">
      <span>Şifre</span>
      <input class="input" type="password" name="password" required autocomplete="current-password">
    </label>
    <label class="check">
      <input type="checkbox" name="remember" value="1"<?= $remember ? ' checked' : '' ?>>
      <span>Bu cihazda oturumum açık kalsın <small class="muted">(<?= e((int) config('remember_days', 30)) ?> gün; ortak/başkasının telefonunda işareti kaldırın)</small></span>
    </label>
    <button type="submit" class="btn btn-primary btn-lg btn-block">Giriş yap</button>
  </form>
</main>
<?php render_footer(); ?>

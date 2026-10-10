<?php
// =============================================================================
// EVOLVIX GLOBAL · Instalación en un solo paso
//
// Abre este archivo en tu navegador (https://tudominio.com/instalar.php)
// después de subir los archivos. Te pide:
//   1. Los datos de la base de datos MySQL que te dio Hostalia. Comprueba que
//      conectan, crea las tablas (sql/01-esquema.sql) y guarda api/config.php.
//   2. Tu cuenta de administrador.
//
// Por seguridad, en cuanto existe cualquier cuenta este script ya no hace
// nada: aunque alguien encontrara esta dirección, no podría usarla.
// Aun así, cuando termines, BORRA este archivo del servidor.
// =============================================================================

require_once __DIR__ . '/api/config.php';

const EVOLVIX_RUTA_CONFIG = __DIR__ . '/api/config.php';
const EVOLVIX_RUTA_ESQUEMA = __DIR__ . '/sql/01-esquema.sql';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function configRellenada(): bool {
  return strpos(EVOLVIX_DB_HOST, 'PON-AQUI') === false && strpos(EVOLVIX_DB_NOMBRE, 'PON-AQUI') === false;
}

function conectar(string $host, string $nombre, string $usuario, string $clave): PDO {
  $host = trim($host);
  $puerto = '';
  if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $puerto = ';port=' . $m[2]; }
  return new PDO('mysql:host=' . $host . $puerto . ';dbname=' . trim($nombre) . ';charset=utf8mb4', trim($usuario), $clave, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

// Explica en cristiano los errores de conexión más habituales.
function explicaError(PDOException $e): string {
  $m = $e->getMessage();
  if (stripos($m, 'getaddrinfo') !== false || stripos($m, 'Unknown MySQL server host') !== false)
    return 'No se encuentra el servidor de la base de datos. Revisa el campo «Servidor».';
  if (stripos($m, 'Access denied') !== false)
    return 'Hostalia rechaza el usuario o la contraseña de MySQL. Revísalos (y que el usuario esté asignado a esa base de datos).';
  if (stripos($m, 'Unknown database') !== false)
    return 'Esa base de datos no existe. Revisa el campo «Nombre de la base de datos».';
  if (stripos($m, 'timed out') !== false || stripos($m, 'Connection refused') !== false)
    return 'El servidor no responde. Revisa el servidor y el puerto.';
  return 'No se ha podido conectar.';
}

// Ejecuta sql/01-esquema.sql sentencia a sentencia. Se puede repetir sin
// riesgo: todo usa IF NOT EXISTS / WHERE NOT EXISTS.
function creaTablas(PDO $pdo): void {
  $sql = file_get_contents(EVOLVIX_RUTA_ESQUEMA);
  if ($sql === false) throw new RuntimeException('No encuentro sql/01-esquema.sql: súbelo también.');
  $sin = preg_replace('/^\s*--.*$/m', '', $sql);
  foreach (preg_split('/;\s*$/m', $sin) as $sentencia) {
    $sentencia = trim($sentencia);
    if ($sentencia === '') continue;
    $st = $pdo->query($sentencia);
    if ($st) $st->closeCursor();
  }
}

function contenidoConfig(string $host, string $nombre, string $usuario, string $clave): string {
  $v = fn($s) => var_export(trim($s), true);
  $c = var_export($clave, true);
  return <<<PHP
<?php
// =============================================================================
// EVOLVIX GLOBAL · Configuración de la base de datos
// Generado por instalar.php. NUNCA subas este archivo a un repositorio ni lo
// compartas: quien tenga estos datos tiene acceso completo a la base de datos.
// =============================================================================

define('EVOLVIX_DB_HOST', {$v($host)});
define('EVOLVIX_DB_NOMBRE', {$v($nombre)});
define('EVOLVIX_DB_USUARIO', {$v($usuario)});
define('EVOLVIX_DB_CLAVE', {$c});

// Opcional: carpeta donde se guardan los PDF y fotos de las facturas.
// Si no la indicas, se usa la carpeta archivos/ junto a index.html (protegida
// por su propio .htaccess). Si tu hosting te deja una carpeta FUERA de
// httpdocs, es todavía más seguro ponerla aquí, por ejemplo:
// define('EVOLVIX_DIR_ARCHIVOS', '/var/www/vhosts/tudominio.com/facturas-evolvix');

// true solo para diagnosticar problemas: muestra el detalle técnico de los
// errores en el navegador. En uso normal, false.
define('EVOLVIX_DEPURAR', false);

PHP;
}

$error = '';
$aviso = '';
$exito = false;
$configManual = '';
$f = array_map(fn($x) => is_string($x) ? $x : '', $_POST);
$pdo = null;

// --- ¿En qué punto estamos? ----------------------------------------------------
if (configRellenada()) {
  try { $pdo = conectar(EVOLVIX_DB_HOST, EVOLVIX_DB_NOMBRE, EVOLVIX_DB_USUARIO, EVOLVIX_DB_CLAVE); }
  catch (PDOException $e) { $error = 'api/config.php tiene datos, pero no conectan: ' . explicaError($e); }
}
$hayTablas = false;
$yaHayCuentas = false;
if ($pdo) {
  $hayTablas = (bool)$pdo->query("SHOW TABLES LIKE 'perfiles'")->fetch();
  $yaHayCuentas = $hayTablas && (int)$pdo->query('SELECT COUNT(*) FROM perfiles')->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$yaHayCuentas) {
  $nombre = trim($f['nombre'] ?? '');
  $apellidos = trim($f['apellidos'] ?? '');
  $email = trim($f['email'] ?? '');
  $clave = (string)($f['clave'] ?? '');

  if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Escribe tu nombre y un correo válido.';
  } elseif (strlen($clave) < 8) {
    $error = 'La contraseña del panel debe tener al menos 8 caracteres.';
  } else {
    // 1) Base de datos: probar los datos del formulario si aún no hay config.
    if (!$pdo) {
      try {
        $pdo = conectar($f['db_host'] ?? '', $f['db_nombre'] ?? '', $f['db_usuario'] ?? '', (string)($f['db_clave'] ?? ''));
      } catch (PDOException $e) {
        $error = explicaError($e) . ' (Detalle: ' . $e->getMessage() . ')';
        $pdo = null;
      }
      if ($pdo) {
        $cfg = contenidoConfig($f['db_host'], $f['db_nombre'], $f['db_usuario'], (string)$f['db_clave']);
        if (@file_put_contents(EVOLVIX_RUTA_CONFIG, $cfg, LOCK_EX) === false) $configManual = $cfg;
      }
    }
    // 2) Tablas y 3) cuenta de administrador.
    if ($pdo) {
      try {
        creaTablas($pdo);
        $ya = (int)$pdo->query('SELECT COUNT(*) FROM perfiles')->fetchColumn() > 0;
        if ($ya) {
          $aviso = 'Ya existía una cuenta, así que no he creado otra.';
        } else {
          $pdo->prepare('INSERT INTO perfiles (id, nombre, apellidos, email, clave_hash, rol, estado) VALUES (UUID(),?,?,?,?,\'admin\',\'Activo\')')
            ->execute([$nombre, $apellidos, $email, password_hash($clave, PASSWORD_BCRYPT)]);
        }
        $exito = true;
        $yaHayCuentas = true;
      } catch (Throwable $e) {
        $error = 'Conecta, pero no se han podido crear las tablas: ' . $e->getMessage();
      }
    }
  }
}
$pideBaseDatos = !$pdo;
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · Evolvix Global</title>
<style>
body{font-family:system-ui,sans-serif;background:#0B1120;color:#E2E8F0;display:flex;min-height:100vh;
  align-items:center;justify-content:center;margin:0;padding:20px;box-sizing:border-box}
.card{background:#121A2E;border:1px solid #1F2A44;border-radius:16px;padding:30px;max-width:480px;width:100%}
h1{font-size:19px;margin:0 0 6px}
h2{font-size:14px;margin:22px 0 2px;color:#EF7F14}
p.sub{font-size:12.5px;color:#8A96AE;margin:0 0 4px}
label{display:block;font-size:12px;color:#8A96AE;margin:12px 0 5px}
input{width:100%;padding:9px 11px;border-radius:9px;border:1px solid #1F2A44;background:#0F1626;color:#E2E8F0;
  font-size:14px;box-sizing:border-box}
button{margin-top:22px;width:100%;padding:12px;border-radius:9px;border:none;background:#EF7F14;color:#1A0D02;
  font-weight:700;font-size:14px;cursor:pointer}
.msg{padding:10px 12px;border-radius:9px;font-size:13px;margin:12px 0;line-height:1.45;word-break:break-word}
.err{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#FCA5A5}
.ok{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);color:#86EFAC}
.inf{background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.3);color:#BFDBFE}
textarea{width:100%;height:220px;background:#0F1626;color:#86EFAC;border:1px solid #1F2A44;border-radius:9px;font:12px monospace;padding:10px;box-sizing:border-box}
a{color:#EF7F14}
</style></head><body>
<div class="card">
<h1>Instalación de Evolvix Global</h1>
<?php if ($exito): ?>
  <div class="msg ok">¡Listo! La base de datos está preparada<?= $aviso ? '' : ' y tu cuenta de administrador creada' ?>.
    Ya puedes <a href="index.html">entrar en el panel</a>.</div>
  <?php if ($aviso): ?><div class="msg inf"><?= h($aviso) ?></div><?php endif; ?>
  <?php if ($configManual): ?>
    <div class="msg err">No he podido guardar <b>api/config.php</b> (el hosting no deja escribirlo). Ábrelo con el
      administrador de archivos de Hostalia, borra todo su contenido y pega esto:</div>
    <textarea readonly onclick="this.select()"><?= h($configManual) ?></textarea>
  <?php endif; ?>
  <div class="msg err">Ahora <b>BORRA este archivo (instalar.php)</b> del servidor.</div>
<?php elseif ($yaHayCuentas): ?>
  <div class="msg err">Ya existe al menos una cuenta en el sistema. Por seguridad, este instalador no vuelve a
    funcionar. Borra este archivo si todavía está en el servidor.</div>
  <p class="sub"><a href="index.html">Ir al panel</a></p>
<?php else: ?>
  <p class="sub">Un solo formulario: base de datos y tu cuenta. Tarda unos segundos.</p>
  <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?php if ($pideBaseDatos): ?>
      <h2>1 · Base de datos MySQL de Hostalia</h2>
      <p class="sub">Está en el panel de Hostalia → Bases de datos (la base que creaste y su usuario).</p>
      <label>Servidor</label><input name="db_host" required placeholder="p. ej. PMYSQL123.dns-servicio.com" value="<?= h($f['db_host'] ?? '') ?>">
      <label>Nombre de la base de datos</label><input name="db_nombre" required value="<?= h($f['db_nombre'] ?? '') ?>">
      <label>Usuario de MySQL</label><input name="db_usuario" required value="<?= h($f['db_usuario'] ?? '') ?>">
      <label>Contraseña de MySQL</label><input name="db_clave" type="password" required>
    <?php else: ?>
      <div class="msg inf">La base de datos ya está configurada en api/config.php y conecta bien.</div>
    <?php endif; ?>
    <h2><?= $pideBaseDatos ? '2' : '1' ?> · Tu cuenta de administrador del panel</h2>
    <label>Nombre</label><input name="nombre" required value="<?= h($f['nombre'] ?? '') ?>">
    <label>Apellidos</label><input name="apellidos" value="<?= h($f['apellidos'] ?? '') ?>">
    <label>Correo</label><input name="email" type="email" required value="<?= h($f['email'] ?? '') ?>">
    <label>Contraseña del panel (mínimo 8; mejor larga, con mayúsculas, números y símbolos)</label><input name="clave" type="password" required>
    <button type="submit">Instalar</button>
  </form>
<?php endif; ?>
</div>
</body></html>

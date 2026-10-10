<?php
// =============================================================================
// EVOLVIX GLOBAL · Capa de seguridad del backend
//
// Aquí no hay RLS (seguridad por fila dentro de la propia base de datos,
// como en Supabase/Postgres): en MySQL compartido de Hostalia esa garantía
// no existe, así que esta es la pieza que la sustituye. Cada endpoint
// (marcas.php, clientes.php, facturas.php...) llama a requiereSesion() o
// requiereAdmin() ANTES de tocar la base de datos.
// =============================================================================

require_once __DIR__ . '/db.php';

function evolvixIniciaSesion(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $seguro = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $seguro,
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
  session_name('evolvix_sesion');
  session_start();
}

function evolvixJson($datos, int $codigo = 200): void {
  http_response_code($codigo);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($datos);
  exit;
}

function evolvixCuerpo(): array {
  $crudo = file_get_contents('php://input');
  if ($crudo === '' || $crudo === false) return [];
  $datos = json_decode($crudo, true);
  return is_array($datos) ? $datos : [];
}

// --- CSRF ---------------------------------------------------------------
// La cookie de sesión ya lleva SameSite=Strict (no viaja en peticiones que
// vengan de otra web), pero añadimos esta segunda comprobación porque esto
// es un sistema de facturación: más vale una capa de más que confiar en que
// todos los navegadores de todos los clientes respeten SameSite siempre.
function evolvixTokenCsrf(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}
function evolvixCompruebaCsrf(): void {
  $recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $recibido)) {
    evolvixJson(['ok' => false, 'mensaje' => 'Sesión caducada o token de seguridad inválido. Vuelve a cargar la página.'], 403);
  }
}

// --- Autenticación / autorización ----------------------------------------
function evolvixUsuarioActual(): ?array {
  evolvixIniciaSesion();
  if (empty($_SESSION['uid'])) return null;
  return [
    'id' => $_SESSION['uid'],
    'nombre' => $_SESSION['nombre'],
    'apellidos' => $_SESSION['apellidos'],
    'email' => $_SESSION['email'],
    'rol' => $_SESSION['rol'],
  ];
}

// Toda petición que cambie datos (POST/PATCH) exige el token CSRF; las de
// solo lectura (GET) no lo necesitan.
function evolvixRequiereSesion(): array {
  $u = evolvixUsuarioActual();
  if (!$u) evolvixJson(['ok' => false, 'mensaje' => 'Tienes que iniciar sesión.'], 401);
  if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
    evolvixCompruebaCsrf();
  }
  return $u;
}
function evolvixRequiereAdmin(): array {
  $u = evolvixRequiereSesion();
  if ($u['rol'] !== 'admin') {
    evolvixJson(['ok' => false, 'mensaje' => 'Esta acción solo puede hacerla un administrador.'], 403);
  }
  return $u;
}

// --- Carpeta de los PDF/fotos subidos ---------------------------------------
// Por defecto, archivos/ junto a index.html (su propio .htaccess impide
// abrirla desde fuera). Si Hostalia te deja una carpeta FUERA de httpdocs,
// mejor aún: indícala en config.php con EVOLVIX_DIR_ARCHIVOS.
function evolvixDirArchivos(): string {
  $dir = defined('EVOLVIX_DIR_ARCHIVOS') ? EVOLVIX_DIR_ARCHIVOS : dirname(__DIR__) . '/archivos';
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    evolvixJson(['ok' => false, 'mensaje' => 'No se puede crear la carpeta de archivos en el servidor.'], 500);
  }
  return rtrim($dir, '/');
}

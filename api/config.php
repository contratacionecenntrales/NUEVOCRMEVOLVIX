<?php
// =============================================================================
// EVOLVIX GLOBAL · Configuración de la base de datos
//
// Rellena estos cuatro valores con los que te dé el panel de Hostalia al
// crear tu base de datos MySQL (Plesk/cPanel → Bases de datos). NUNCA subas
// este archivo a un repositorio público ni lo compartas: quien tenga estos
// cuatro datos tiene acceso completo a la base de datos.
// =============================================================================

define('EVOLVIX_DB_HOST', 'PON-AQUI-EL-SERVIDOR-MYSQL');      // p. ej. localhost, o mysqlXXX.hostalia.com
define('EVOLVIX_DB_NOMBRE', 'PON-AQUI-EL-NOMBRE-DE-LA-BASE-DE-DATOS');
define('EVOLVIX_DB_USUARIO', 'PON-AQUI-EL-USUARIO-MYSQL');
define('EVOLVIX_DB_CLAVE', 'PON-AQUI-LA-CONTRASEÑA-MYSQL');

// Opcional: carpeta donde se guardan los PDF y fotos de las facturas.
// Si no la indicas, se usa la carpeta archivos/ junto a index.html (protegida
// por su propio .htaccess). Si tu hosting te deja una carpeta FUERA de
// httpdocs, es todavía más seguro ponerla aquí, por ejemplo:
// define('EVOLVIX_DIR_ARCHIVOS', '/var/www/vhosts/tudominio.com/facturas-evolvix');

// Déjalo en true mientras pruebas; en producción, en cuanto todo funcione,
// cámbialo a false para que los errores no se muestren en el navegador.
define('EVOLVIX_DEPURAR', true);

#!/bin/bash
set -e

# Configuración básica
MOODLE_VERSION="MOODLE_405_STABLE"

# 1. Crear carpeta src si no existe
mkdir -p "./src"

# 2. Descargar Moodle si está vacío
if [ -z "$(ls -A "./src")" ]; then
    git clone --depth 1 --branch "$MOODLE_VERSION" https://github.com/moodle/moodle.git "./src"
else
    echo "La carpeta ./src ya contiene archivos"
fi

# 3. Crear config.php si no existe
if [ ! -f "./src/config.php" ]; then
    echo "Creando configuración base en ./src/config.php..."
    cat <<EOF > "./src/config.php"
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

// Variables de entorno (compatibilidad con Docker)
\$CFG->dbtype    = getenv('DB_TYPE');
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = getenv('DB_HOST');
\$CFG->dbname    = getenv('DB_NAME');
\$CFG->dbuser    = getenv('DB_USER');
\$CFG->dbpass    = getenv('DB_PASS');
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = array('dbpersist' => 0, 'dbport' => getenv('DB_PORT') ?: 5432, 'dbsocket' => '');
\$CFG->wwwroot   = getenv('MOODLE_URL');
\$CFG->dataroot  = getenv('MOODLE_DATA_ROOT');
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 0777;
\$CFG->slasharguments = true;

// Fix para puerto 8080 local y SSL en Proxy
if (strpos(\$CFG->wwwroot, ':8080') !== false) {
    \$_SERVER['SERVER_PORT'] = 8080;
}
if (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    \$CFG->sslproxy = true;
}

require_once(__DIR__ . '/lib/setup.php');
EOF
    chmod 644 "./src/config.php"
fi

# 4. Ajuste de permisos inicial (para el host)
chmod -R 775 "./src"


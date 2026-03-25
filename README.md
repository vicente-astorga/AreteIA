# AreteIA - Moodle Docker Architecture

## Inicio Rápido Local

Para levantar el proyecto en tu local desde cero, sigue estos pasos:

### 1. Configuración de Entorno
Copia el archivo de ejemplo y ajusta la URL para local:
```bash
cp .env.example .env
# Asegúrate de que MOODLE_URL=http://localhost:8080
```

### 2. Inicialización de la Instancia
Ejecuta el script de preparación. Esto descargará el núcleo de Moodle y creará el `config.php` con los parches necesarios para Docker:
```bash
./scripts/init-instance.sh
```

### 3. Levantar Contenedores
```bash
docker compose up -d --build
```
---

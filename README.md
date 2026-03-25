# AreteIA - Moodle Docker Architecture

## Inicio Rápido Local

Para levantar el proyecto en tu local desde cero, sigue estos pasos:

### 1. Configuración de Entorno
Copia el archivo de ejemplo y ajusta la URL para local:
```bash
cp .env.example .env
# Asegúrate de que MOODLE_URL=http://localhost:8080
```

### 2. Despliegue Zero-Step
Levanta el stack:
```bash
docker compose up -d --build
```
---

## Info

- `local/areteia/`: **Carpeta de trabajo.** Aquí es donde se debe desarrollar el código del plugin

---


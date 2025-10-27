# Guía de despliegue en Plesk

Esta referencia resume la estructura del plugin **Bodhi API**, los endpoints móviles disponibles y los pasos sugeridos para subir la actualización al hosting Plesk.

## Estructura relevante del plugin

- `bodhi-api.php`: núcleo del plugin, registra CPT/REST y helpers.
- `rest-mobile.php`: endpoints `bodhi-mobile/v1/*` orientados a la app móvil.
- `inc/rest-bridge.php`: puente para formularios/login web (`/bm/v1/*`).
- `docs/`: documentación de instalación y soporte.

## Endpoints REST clave

| Endpoint | Método | Descripción |
| --- | --- | --- |
| `/wp-json/bodhi-mobile/v1/me` | GET | Devuelve perfil básico; requiere cookie `wordpress_logged_in_*` válida. |
| `/wp-json/bodhi-mobile/v1/my-courses` | GET | Proxy a `/bodhi/v1/courses?mode=union`, normaliza cursos para la app. |
| `/wp-json/bodhi-mobile/v1/nonce` | GET | Debug: genera nonce REST temporal (opcional). |
| `/wp-json/bodhi/v1/auth/token` | POST | Login principal usado por la app. Emite cookies `SameSite=None; Secure`. |
| `/wp-json/bm/v1/form-login` | POST | Login alterno (web/app híbrida) que también emite cookies compatibles con móvil. |

## Comportamiento de cookies

- Al autenticarse desde cualquiera de los endpoints de login anteriores, ahora se emiten cookies con:
  - `domain` igual al host configurado en `home_url()` (p. ej. `staging.bodhimedicine.com`).
  - `Secure=true` cuando el sitio usa HTTPS.
  - `SameSite=None` (solo en HTTPS) para que iOS/Android con WebView/Fetch puedan re-enviarlas.
  - `wordpress_logged_in_*` se marca como `HttpOnly=false` para facilitar diagnósticos con `CookieManager`.
- Si la solicitud llega por HTTP plano (entorno local), la cookie cae de forma segura a `SameSite=Lax` y `Secure=false`.

## Checklist previo a subir a Plesk

1. **Compilar zip del plugin**
   - Desde la raíz del proyecto: `zip -r bodhi-api.zip bodhi-api.php inc rest-mobile.php` (ajusta si hay más carpetas necesarias).
   - Incluye `docs/` si deseas acompañar la distribución con esta guía.
2. **Validar PHP syntax**
   - `php -l bodhi-api.php`
   - `php -l rest-mobile.php`
   - `php -l inc/rest-bridge.php`
3. **Pruebas locales**
   - Login vía `/wp-json/bodhi/v1/auth/token` y confirma en logs del cliente que `wordpress_logged_in_*` tiene `SameSite=None`.
   - GET `/wp-json/bodhi-mobile/v1/me` debe responder 200 inmediatamente después del login.
4. **Subir a Plesk**
   - Accede a *Files* → carpeta `wp-content/plugins/`.
   - Sube el zip y descomprímelo sobreescribiendo el plugin existente.
   - Verifica permisos (archivos 0644, carpetas 0755).
5. **Verificación en staging**
   - Navega a `/wp-json/bodhi-mobile/v1/me` con una sesión válida y confirma respuesta 200.
   - Desde la app (Expo) revisa `CookieManager.get(EXPO_PUBLIC_WP_BASE)` para validar dominio, secure y SameSite.

## Diagnóstico rápido

- Si `/bodhi-mobile/v1/me` devuelve 401:
  1. Confirma que la app está apuntando exactamente a `https://staging.bodhimedicine.com`.
  2. Inspecciona la cabecera `Cookie` enviada en la petición (usa proxy o log interno).
  3. Repite el login y valida que la respuesta REST incluye cabeceras `Set-Cookie` con `SameSite=None; Secure`.
  4. En Plesk, revisa `error_log` por posibles advertencias de PHP sobre cookies.

Mantén este documento junto al plugin para futuras actualizaciones y para que el equipo de soporte tenga un checklist claro en despliegues de emergencia.

<?php
/**
 * Plugin Name: Bodhi API (Minimal Login Test)
 * Description: A minimal plugin to test only the AJAX login functionality.
 * Version: 1.0.0
 * Author: JECT
 */
if ( ! defined('ABSPATH') ) exit;

// Registramos nuestro manejador de login para usuarios no autenticados y autenticados.
add_action('wp_ajax_nopriv_bodhi_login', 'bodhi_minimal_login_handler');
add_action('wp_ajax_bodhi_login',        'bodhi_minimal_login_handler');

/**
 * Maneja la petición de login vía admin-ajax.php, establece cookies de sesión
 * y devuelve un nonce para las siguientes peticiones a la API REST.
 */
function bodhi_minimal_login_handler() {
    // 1. Limpiar cualquier salida de buffer para asegurar una respuesta JSON pura.
    while (ob_get_level()) {
        @ob_end_clean();
    }

    // 2. Validar que la petición sea de tipo POST.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_send_json_error(['code' => 'method_not_allowed', 'message' => 'Método no permitido. Se requiere POST.'], 405);
    }

    // 3. Obtener credenciales.
    $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if (empty($username) || empty($password)) {
        wp_send_json_error(['code' => 'missing_credentials', 'message' => 'Usuario y contraseña son requeridos.'], 400);
    }

    // 4. Autenticar al usuario.
    $user = wp_signon([
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => true,
    ], is_ssl());

    // 5. Manejar error de autenticación.
    if (is_wp_error($user)) {
        wp_send_json_error(['code' => $user->get_error_code(), 'message' => $user->get_error_message()], 401);
    }

    // 6. Establecer la sesión y las cookies.
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true, is_ssl());

    // 7. Generar el nonce.
    $nonce = wp_create_nonce('wp_rest');

    // 8. Enviar la respuesta exitosa.
    wp_send_json([
        'ok'   => true,
        'user' => [
            'id'    => $user->ID,
            'name'  => $user->display_name,
            'email' => $user->user_email,
            'roles' => $user->roles,
        ],
        'nonce'=> $nonce
    ], 200);
}